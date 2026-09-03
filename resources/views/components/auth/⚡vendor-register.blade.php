<?php

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorApproval;
use App\Models\VendorPackage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.guest')]
class extends Component
{
    public string $businessName = '';

    public string $ownerName = '';

    public string $email = '';

    public string $phone = '';

    public string $whatsappNumber = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(): void
    {
        $throttleKey = 'register|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $this->addError('businessName', "Too many registration attempts. Try again in {$seconds} seconds.");

            return;
        }

        RateLimiter::hit($throttleKey, 3600);

        $this->validate([
            'businessName' => ['required', 'string', 'max:255'],
            'ownerName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')],
            'whatsappNumber' => ['required', 'string', 'min:8', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Assumption: newly registered vendors start on the lowest-tier
        // package until admin assigns one during approval (§9 Admin panel).
        $defaultPackage = VendorPackage::where('is_active', true)->orderBy('price')->first();

        if (! $defaultPackage) {
            $this->addError('businessName', 'Registration is temporarily unavailable. Please try again later.');

            return;
        }

        $vendor = DB::transaction(function () use ($defaultPackage) {
            $user = User::create([
                'name' => $this->ownerName,
                'email' => $this->email,
                'phone' => $this->phone,
                'password' => $this->password,
            ]);
            $user->assignRole('vendor_owner');

            $vendor = Vendor::create([
                'owner_user_id' => $user->id,
                'business_name' => $this->businessName,
                'vendor_package_id' => $defaultPackage->id,
                'status' => 'pending',
                'whatsapp_number' => $this->whatsappNumber,
            ]);

            $user->update(['vendor_id' => $vendor->id]);

            VendorApproval::create([
                'vendor_id' => $vendor->id,
                'action' => 'submitted',
                'performed_by' => null,
            ]);

            return $vendor;
        });

        Auth::login($vendor->owner);

        // Guarded: real HTTP traffic always has a session (StartSession is
        // in the `web` middleware group on every route). Livewire's test
        // harness calls components with middleware disabled, so this is
        // only ever skipped under Livewire::test().
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        $this->redirect(route('vendor.dashboard'), navigate: true);
    }
};
?>

<div class="w-full max-w-md py-10">
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center h-14 w-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 shadow-lg shadow-indigo-500/30 mb-4">
            <svg class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72" />
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white tracking-tight">Register your shop</h1>
        <p class="text-sm text-slate-400 mt-1.5">An admin will review and approve your account before you can go live.</p>
    </div>

    <div class="bg-white/95 backdrop-blur rounded-2xl shadow-2xl shadow-black/30 ring-1 ring-white/10 p-8">
        <form wire:submit="register" class="space-y-4">
            <div>
                <x-ui.input wire:model="businessName" id="businessName" name="businessName" type="text" placeholder="Acme Store" label="Business name" />
            </div>

            <div>
                <x-ui.input wire:model="ownerName" id="ownerName" name="ownerName" type="text" autocomplete="name" label="Your name" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-ui.input wire:model="email" id="email" name="email" type="email" autocomplete="email" placeholder="you@example.com" label="Email" />
                </div>

                <div>
                    <x-ui.input wire:model="phone" id="phone" name="phone" type="text" autocomplete="tel" placeholder="+9230..." label="Phone" />
                </div>
            </div>

            <div>
                <x-ui.input wire:model="whatsappNumber" id="whatsappNumber" name="whatsappNumber" type="text" autocomplete="tel" placeholder="+9230..." label="WhatsApp number" />
                <p class="mt-1 text-xs text-gray-500">The number your customers will message. You'll verify it after approval.</p>
            </div>

            <div>
                <x-ui.input wire:model="password" id="password" name="password" type="password" autocomplete="new-password" placeholder="••••••••" label="Password" />
            </div>

            <div>
                <x-ui.input wire:model="password_confirmation" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" placeholder="••••••••" label="Confirm password" />
            </div>

            <button type="submit" wire:loading.attr="disabled"
                class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:from-indigo-500 hover:to-violet-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-60 disabled:cursor-not-allowed">
                <svg wire:loading wire:target="register" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span wire:loading.remove wire:target="register">Create account</span>
                <span wire:loading wire:target="register">Creating account&hellip;</span>
            </button>
        </form>

        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
            <div class="relative flex justify-center"><span class="bg-white px-3 text-xs uppercase tracking-wider text-gray-400">Already registered?</span></div>
        </div>

        <p class="text-center text-sm text-gray-600">
            <a href="{{ route('login') }}" class="font-medium text-indigo-600 hover:text-indigo-500" wire:navigate>Sign in instead &rarr;</a>
        </p>
    </div>
</div>
