<?php

use App\Models\WhatsappAccount;
use App\Services\Vendor\PackageLimitService;
use App\Services\WhatsApp\WhatsAppProvisioningService;
use Endroid\QrCode\Builder\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.vendor', ['title' => 'WhatsApp'])]
class extends Component
{
    public string $phoneNumber = '';

    public string $otpMethod = 'sms';

    public string $otpCode = '';

    public function mount(): void
    {
        $vendor = Auth::user()->vendor;

        if ($vendor->status !== 'approved') {
            abort(403, 'Your vendor account must be approved before you can connect WhatsApp.');
        }

        if (! $this->currentAccount() && $vendor->whatsapp_number) {
            $this->phoneNumber = $vendor->whatsapp_number;
        }
    }

    public function with(): array
    {
        $vendor = Auth::user()->vendor;

        return [
            'vendor' => $vendor,
            'account' => $this->currentAccount(),
            'canConnect' => app(PackageLimitService::class)->canConnectWhatsapp($vendor->fresh()),
        ];
    }

    public function submitNumber(WhatsAppProvisioningService $service): void
    {
        $vendor = Auth::user()->vendor;

        if (! app(PackageLimitService::class)->canConnectWhatsapp($vendor)) {
            $this->addError('phoneNumber', 'Your package does not include WhatsApp, or a number is already connected.');

            return;
        }

        $this->validate(['phoneNumber' => ['required', 'string', 'min:8', 'max:20']]);

        $service->submitNumber($vendor, $this->phoneNumber);
    }

    public function sendOtp(WhatsAppProvisioningService $service): void
    {
        $this->validate(['otpMethod' => ['required', 'in:sms,voice']]);

        $service->requestOtp($this->currentAccount(), $this->otpMethod);
    }

    public function verifyCode(WhatsAppProvisioningService $service): void
    {
        $this->validate(['otpCode' => ['required', 'string', 'min:4', 'max:10']]);

        $service->verifyOtp($this->currentAccount(), $this->otpCode);
        $this->reset('otpCode');
    }

    public function startOver(WhatsAppProvisioningService $service): void
    {
        if ($account = $this->currentAccount()) {
            $service->release($account);
            $account->delete();
        }

        $this->reset(['phoneNumber', 'otpMethod', 'otpCode']);
    }

    #[Computed]
    public function waLinkQrDataUri(): ?string
    {
        $account = $this->currentAccount();

        if (! $account || $account->status !== 'active') {
            return null;
        }

        $waLink = 'https://wa.me/'.preg_replace('/[^0-9]/', '', $account->display_phone_number);

        $result = (new Builder(data: $waLink, size: 200, margin: 10))->build();

        return 'data:'.$result->getMimeType().';base64,'.base64_encode($result->getString());
    }

    private function currentAccount(): ?WhatsappAccount
    {
        return WhatsappAccount::where('vendor_id', Auth::user()->vendor_id)->first();
    }
};
?>

<div class="max-w-lg">
    <div class="mb-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">WhatsApp</h2>
        <p class="mt-1 text-sm text-slate-500">Connect the number customers will message to place orders.</p>
    </div>

    @php
        $stepIndex = match (true) {
            ! $account => 1,
            in_array($account->onboarding_status, ['number_submitted', 'verifying']) && ! $account->verification_method => 2,
            $account->onboarding_status === 'verifying' => 3,
            default => 4,
        };
    @endphp

    @if ($account?->status !== 'active')
        <div class="mb-6 flex items-center gap-2">
            @foreach (['Number', 'Method', 'Verify'] as $i => $label)
                <div class="flex flex-1 items-center gap-2">
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold
                        {{ $stepIndex > $i + 1 ? 'bg-indigo-600 text-white' : ($stepIndex === $i + 1 ? 'bg-indigo-100 text-indigo-700 ring-2 ring-indigo-500' : 'bg-slate-100 text-slate-400') }}">
                        @if ($stepIndex > $i + 1)
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        @else
                            {{ $i + 1 }}
                        @endif
                    </div>
                    <span class="text-xs font-medium {{ $stepIndex >= $i + 1 ? 'text-slate-700' : 'text-slate-400' }}">{{ $label }}</span>
                    @if (! $loop->last)
                        <div class="h-px flex-1 {{ $stepIndex > $i + 1 ? 'bg-indigo-600' : 'bg-slate-200' }}"></div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @unless ($canConnect && ! $account)
        @if (! $account)
            <x-ui.alert-banner color="amber" class="mb-4">
                Your current package does not include WhatsApp connectivity.
            </x-ui.alert-banner>
        @endif
    @endunless

    @if (! $account)
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="mb-2 font-semibold text-slate-900">Connect WhatsApp</h3>
            <p class="mb-4 text-sm text-slate-500">
                Enter the phone number customers will message. Make sure WhatsApp (personal or Business app) is not
                currently installed with this number — it needs to be free to become your shop's official number.
            </p>

            <form wire:submit="submitNumber" class="space-y-3">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                    </svg>
                    <input wire:model="phoneNumber" type="text" placeholder="+92 300 1234567"
                        class="h-11 w-full rounded-lg border border-slate-300 pl-10 pr-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                </div>
                @error('phoneNumber') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <button type="submit" @disabled(! $canConnect) wire:loading.attr="disabled" wire:target="submitNumber"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg wire:loading wire:target="submitNumber" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Continue
                </button>
            </form>
        </div>
    @elseif ($account->onboarding_status === 'failed')
        <x-ui.alert-banner color="red" class="mb-4">
            {{ $account->rejection_reason ?? 'Something went wrong connecting this number.' }}
        </x-ui.alert-banner>
        <button wire:click="startOver"
            class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
            Try a different number
        </button>
    @elseif (in_array($account->onboarding_status, ['number_submitted', 'verifying']) && ! $account->verification_method)
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="mb-2 font-semibold text-slate-900">Verify {{ $account->display_phone_number }}</h3>
            <p class="mb-4 text-sm text-slate-500">How should we send your verification code?</p>

            <form wire:submit="sendOtp" class="space-y-3">
                <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-700 has-checked:border-indigo-300 has-checked:bg-indigo-50 has-checked:text-indigo-700">
                    <input type="radio" wire:model="otpMethod" value="sms" class="text-indigo-600 focus:ring-indigo-400"> Text message (SMS)
                </label>
                <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-700 has-checked:border-indigo-300 has-checked:bg-indigo-50 has-checked:text-indigo-700">
                    <input type="radio" wire:model="otpMethod" value="voice" class="text-indigo-600 focus:ring-indigo-400"> Voice call
                </label>

                <button type="submit" wire:loading.attr="disabled" wire:target="sendOtp"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700 disabled:opacity-60">
                    <svg wire:loading wire:target="sendOtp" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Send code
                </button>
            </form>
        </div>
    @elseif ($account->onboarding_status === 'verifying')
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="mb-2 font-semibold text-slate-900">Enter verification code</h3>
            <p class="mb-4 text-sm text-slate-500">
                We sent a code to {{ $account->display_phone_number }} via {{ $account->verification_method }}.
            </p>

            <form wire:submit="verifyCode" class="space-y-3">
                <input wire:model="otpCode" type="text" placeholder="Enter code" inputmode="numeric" autocomplete="one-time-code"
                    class="h-11 w-full rounded-lg border border-slate-300 px-3 text-center text-lg tracking-widest text-slate-900 shadow-sm placeholder:text-sm placeholder:font-normal placeholder:tracking-normal placeholder:text-slate-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                @error('otpCode') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <button type="submit" wire:loading.attr="disabled" wire:target="verifyCode"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700 disabled:opacity-60">
                    <svg wire:loading wire:target="verifyCode" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Verify
                </button>
            </form>
        </div>
    @elseif ($account->status === 'active')
        <x-ui.alert-banner color="emerald" icon="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z">
            WhatsApp connected! Your shop number is {{ $account->display_phone_number }}.
        </x-ui.alert-banner>

        <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">Share this link with customers</p>
            <div class="flex items-center gap-2" x-data="{ copied: false }">
                <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $account->display_phone_number) }}" target="_blank"
                    class="flex-1 truncate rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-indigo-600 hover:underline">
                    wa.me/{{ preg_replace('/[^0-9]/', '', $account->display_phone_number) }}
                </a>
                <button type="button"
                    x-on:click="navigator.clipboard.writeText('https://wa.me/{{ preg_replace('/[^0-9]/', '', $account->display_phone_number) }}'); copied = true; setTimeout(() => copied = false, 1500)"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50">
                    <svg x-show="!copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75h-6a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                    </svg>
                    <svg x-show="copied" x-cloak class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </button>
            </div>

            @if ($this->waLinkQrDataUri)
                <div class="mt-4 flex flex-col items-center gap-2 border-t border-slate-100 pt-4">
                    <img src="{{ $this->waLinkQrDataUri }}" alt="QR code linking to WhatsApp chat" class="h-40 w-40 rounded-lg border border-slate-200 p-1">
                    <p class="text-xs text-slate-400">Scan to open a chat</p>
                </div>
            @endif
        </div>
    @endif
</div>
