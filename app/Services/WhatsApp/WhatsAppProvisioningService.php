<?php

namespace App\Services\WhatsApp;

use App\Models\Vendor;
use App\Models\WhatsappAccount;
use App\Support\LogMasker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

/**
 * Onboards a vendor's WhatsApp number under the platform's single shared
 * WABA, with zero Meta knowledge required from the vendor. See PLAN.md §5a.
 *
 * Flow: submit number -> register under WABA -> request OTP -> verify OTP -> activate.
 * All Graph API calls use the platform System User token, never a per-vendor one.
 */
class WhatsAppProvisioningService
{
    public function submitNumber(Vendor $vendor, string $phoneNumber): WhatsappAccount
    {
        $account = WhatsappAccount::updateOrCreate(
            ['vendor_id' => $vendor->id],
            [
                'display_phone_number' => $phoneNumber,
                'onboarding_status' => 'number_submitted',
                'status' => 'pending',
                'rejection_reason' => null,
            ]
        );

        try {
            [$countryCode, $nationalNumber] = $this->splitPhoneNumber($phoneNumber);
        } catch (NumberParseException) {
            $account->update([
                'onboarding_status' => 'failed',
                'rejection_reason' => 'Could not recognize this as a valid phone number. Include the country code, e.g. +923001234567.',
            ]);

            return $account;
        }

        $response = $this->registerNumber($countryCode, $nationalNumber, $vendor->business_name);

        if (! $response->successful()) {
            $account->update([
                'onboarding_status' => 'failed',
                'rejection_reason' => $this->extractErrorMessage($response),
            ]);

            return $account;
        }

        $phoneNumberId = $response->json('id');

        $account->update([
            'phone_number_id' => $phoneNumberId,
            'waba_id' => config('whatsapp.waba_id'),
            'onboarding_status' => 'verifying',
        ]);

        return $account;
    }

    public function requestOtp(WhatsappAccount $account, string $method = 'sms'): WhatsappAccount
    {
        $response = $this->requestVerificationCode($account->phone_number_id, $method);

        if (! $response->successful()) {
            $account->update([
                'onboarding_status' => 'failed',
                'rejection_reason' => $this->extractErrorMessage($response),
            ]);

            return $account;
        }

        $account->update(['verification_method' => $method]);

        return $account;
    }

    public function verifyOtp(WhatsappAccount $account, string $code): WhatsappAccount
    {
        $response = $this->confirmVerificationCode($account->phone_number_id, $code);

        if (! $response->successful()) {
            $account->update([
                'onboarding_status' => 'failed',
                'rejection_reason' => $this->extractErrorMessage($response),
            ]);

            return $account;
        }

        $account->update(['onboarding_status' => 'registered']);

        $activation = $this->activateNumber($account->phone_number_id);

        if (! $activation->successful()) {
            $account->update([
                'onboarding_status' => 'failed',
                'rejection_reason' => $this->extractErrorMessage($activation),
            ]);

            return $account;
        }

        $account->update([
            'onboarding_status' => 'active',
            'status' => 'active',
            'connected_at' => now(),
        ]);

        return $account;
    }

    public function retry(WhatsappAccount $account): WhatsappAccount
    {
        return $this->submitNumber($account->vendor, $account->display_phone_number);
    }

    /**
     * Release a phone number resource from the platform WABA on Meta's side,
     * so the same number can be re-submitted later without an "already
     * registered" conflict. Best-effort — failures are logged, not thrown,
     * since the local account row is being deleted regardless.
     */
    public function release(WhatsappAccount $account): void
    {
        if (! $account->phone_number_id) {
            return;
        }

        $response = $this->graph()->post("/{$account->phone_number_id}/deregister");

        if (! $response->successful()) {
            Log::warning('Failed to deregister WhatsApp phone number from Meta', [
                'phone_number_id' => $account->phone_number_id,
                'response' => LogMasker::mask($response->json() ?? []),
            ]);
        }
    }

    private function registerNumber(string $countryCode, string $nationalNumber, string $verifiedName)
    {
        return $this->graph()->post("/{$this->wabaId()}/phone_numbers", [
            'cc' => $countryCode,
            'phone_number' => $nationalNumber,
            'verified_name' => $verifiedName,
        ]);
    }

    /**
     * Split a phone number like "+923001234567" into ['92', '3001234567'].
     *
     * @return array{0: string, 1: string}
     *
     * @throws NumberParseException
     */
    private function splitPhoneNumber(string $phoneNumber): array
    {
        $util = PhoneNumberUtil::getInstance();
        $parsed = $util->parse($phoneNumber, null);

        return [
            (string) $parsed->getCountryCode(),
            (string) $parsed->getNationalNumber(),
        ];
    }

    private function requestVerificationCode(string $phoneNumberId, string $method)
    {
        return $this->graph()->post("/{$phoneNumberId}/request_code", [
            'code_method' => $method,
            'language' => 'en',
        ]);
    }

    private function confirmVerificationCode(string $phoneNumberId, string $code)
    {
        return $this->graph()->post("/{$phoneNumberId}/verify_code", [
            'code' => $code,
        ]);
    }

    private function activateNumber(string $phoneNumberId)
    {
        return $this->graph()->post("/{$phoneNumberId}/register", [
            'messaging_product' => 'whatsapp',
            'pin' => config('whatsapp.two_step_pin'),
        ]);
    }

    private function graph()
    {
        $baseUrl = config('whatsapp.graph_base_url');
        $apiVersion = config('whatsapp.api_version');

        return Http::withToken(config('whatsapp.system_user_token'))
            ->baseUrl("{$baseUrl}/{$apiVersion}");
    }

    private function wabaId(): string
    {
        return (string) config('whatsapp.waba_id');
    }

    private function extractErrorMessage($response): string
    {
        $message = $response->json('error.error_user_msg')
            ?? $response->json('error.message')
            ?? 'Unknown error from WhatsApp.';
        Log::warning('WhatsApp provisioning error', ['response' => LogMasker::mask($response->json() ?? [])]);

        if (str_contains(strtolower($message), 'already registered')) {
            return "This number is still active on WhatsApp (personal or Business app) and can't be connected here yet. "
                ."Open WhatsApp on that phone, go to Settings → Account → Delete my account, then come back and try again in a few minutes. "
                .'Heads up: this permanently deletes that number\'s existing WhatsApp chat history.';
        }

        return $message;
    }
}
