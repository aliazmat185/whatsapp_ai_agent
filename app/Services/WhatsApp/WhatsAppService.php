<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Meta Graph API for sending WhatsApp messages.
 * Always uses the platform System User token + the vendor's own
 * phone_number_id (PLAN.md decision-8 / A11) unless the vendor is on the
 * (not-yet-built) bring-your-own-WABA tier.
 */
class WhatsAppService
{
    public function sendText(WhatsappAccount $account, string $toPhone, string $body): Response
    {
        return $this->post($account, [
            'messaging_product' => 'whatsapp',
            'to' => $toPhone,
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    public function sendInteractiveList(WhatsappAccount $account, string $toPhone, array $interactive): Response
    {
        return $this->post($account, [
            'messaging_product' => 'whatsapp',
            'to' => $toPhone,
            'type' => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    public function sendImage(WhatsappAccount $account, string $toPhone, string $imageUrl, ?string $caption = null): Response
    {
        return $this->post($account, [
            'messaging_product' => 'whatsapp',
            'to' => $toPhone,
            'type' => 'image',
            'image' => array_filter([
                'link' => $imageUrl,
                'caption' => $caption,
            ]),
        ]);
    }

    public function sendLocationRequest(WhatsappAccount $account, string $toPhone, string $bodyText): Response
    {
        return $this->post($account, [
            'messaging_product' => 'whatsapp',
            'to' => $toPhone,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'location_request_message',
                'body' => ['text' => $bodyText],
                'action' => ['name' => 'send_location'],
            ],
        ]);
    }

    public function sendTemplate(WhatsappAccount $account, string $toPhone, string $templateName, string $languageCode = 'en', array $components = []): Response
    {
        return $this->post($account, [
            'messaging_product' => 'whatsapp',
            'to' => $toPhone,
            'type' => 'template',
            'template' => array_filter([
                'name' => $templateName,
                'language' => ['code' => $languageCode],
                'components' => $components,
            ]),
        ]);
    }

    private function post(WhatsappAccount $account, array $payload): Response
    {
        $apiVersion = config('whatsapp.api_version');
        $baseUrl = config('whatsapp.graph_base_url');
        $token = $this->resolveToken($account);

        return Http::withToken($token)
            ->post("{$baseUrl}/{$apiVersion}/{$account->phone_number_id}/messages", $payload);
    }

    private function resolveToken(WhatsappAccount $account): string
    {
        if (! $account->uses_platform_token && $account->access_token) {
            return $account->access_token;
        }

        return config('whatsapp.system_user_token');
    }
}
