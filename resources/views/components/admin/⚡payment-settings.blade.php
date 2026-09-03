<?php

use App\Models\SystemSetting;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.admin', ['title' => 'Payment Settings'])]
class extends Component
{
    /** @var array<string, bool> */
    public array $enabled = [];

    public ?string $lastToggled = null;

    private array $methods = ['cod', 'jazzcash', 'easypaisa', 'card'];

    public function mount(): void
    {
        if (! auth()->user()->hasRole('super_admin')) {
            abort(403);
        }

        foreach ($this->methods as $method) {
            $this->enabled[$method] = (bool) SystemSetting::get("payment_methods.{$method}.enabled", true);
        }
    }

    public function toggle(string $method): void
    {
        $this->enabled[$method] = ! $this->enabled[$method];
        SystemSetting::set("payment_methods.{$method}.enabled", $this->enabled[$method]);
        $this->lastToggled = $method;
    }
};
?>

<div class="max-w-2xl">
    @php
        $methods = [
            'cod' => ['label' => 'Cash on Delivery', 'icon' => 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm6 0h.008v.008H21V10.5zm-18 0h.008v.008H3V10.5z'],
            'jazzcash' => ['label' => 'JazzCash', 'icon' => 'M12 7.5v9m3-6.75a2.25 2.25 0 00-2.25-2.25h-1.5a2.25 2.25 0 000 4.5h1.5a2.25 2.25 0 010 4.5h-1.5a2.25 2.25 0 01-2.25-2.25M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            'easypaisa' => ['label' => 'Easypaisa', 'icon' => 'M12 7.5v9m3-6.75a2.25 2.25 0 00-2.25-2.25h-1.5a2.25 2.25 0 000 4.5h1.5a2.25 2.25 0 010 4.5h-1.5a2.25 2.25 0 01-2.25-2.25M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            'card' => ['label' => 'Card', 'icon' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z'],
        ];
    @endphp

    <div class="mb-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Payment Settings</h2>
        <p class="mt-1 text-sm text-slate-500 max-w-xl">
            Platform-wide kill switches. A payment method must be enabled here AND included in the vendor's
            package before customers can use it at checkout.
        </p>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm divide-y divide-slate-100">
        @foreach ($methods as $method => $meta)
            <div class="flex items-center justify-between gap-4 px-5 py-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $enabled[$method] ? 'bg-indigo-50 text-indigo-600' : 'bg-slate-100 text-slate-400' }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $meta['icon'] }}" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ $meta['label'] }}</p>
                        <p class="text-xs text-slate-400">{{ $enabled[$method] ? 'Available at checkout' : 'Hidden at checkout' }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    @if ($lastToggled === $method)
                        <span class="text-xs font-medium text-emerald-600"
                            x-data x-init="setTimeout(() => $wire.set('lastToggled', null), 1500)">
                            Saved
                        </span>
                    @endif
                    <button type="button" wire:click="toggle('{{ $method }}')" wire:loading.attr="disabled" wire:target="toggle('{{ $method }}')"
                        title="{{ $enabled[$method] ? 'Disable' : 'Enable' }}"
                        role="switch" aria-checked="{{ $enabled[$method] ? 'true' : 'false' }}"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-60 {{ $enabled[$method] ? 'bg-indigo-600' : 'bg-slate-200' }}">
                        <span class="inline-block h-4.5 w-4.5 transform rounded-full bg-white shadow transition-transform {{ $enabled[$method] ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                </div>
            </div>
        @endforeach
    </div>
</div>
