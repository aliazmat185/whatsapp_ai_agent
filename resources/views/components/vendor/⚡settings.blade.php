<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.vendor', ['title' => 'Settings'])]
class extends Component
{
    public string $deliveryFee = '';

    public function mount(): void
    {
        $this->deliveryFee = (string) (Auth::user()->vendor->delivery_fee ?? '');
    }

    public function save(): void
    {
        $this->validate([
            'deliveryFee' => ['nullable', 'numeric', 'min:0'],
        ]);

        Auth::user()->vendor->update([
            'delivery_fee' => $this->deliveryFee === '' ? null : $this->deliveryFee,
        ]);

        $this->dispatch('saved');
    }
};
?>

<div class="max-w-lg">
    <div class="mb-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Settings</h2>
        <p class="mt-1 text-sm text-slate-500">General settings for your store.</p>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="mb-1 font-semibold text-slate-900">Delivery fee</h3>
        <p class="mb-4 text-sm text-slate-500">
            Flat delivery charge added to every order's subtotal when delivery is available. Leave blank to use the platform default.
        </p>

        <form wire:submit="save" class="space-y-3">
            <div class="relative max-w-xs">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">{{ Auth::user()->vendor->default_currency }}</span>
                <input wire:model="deliveryFee" type="number" step="0.01" min="0" placeholder="0.00"
                    class="h-11 w-full rounded-lg border border-slate-300 pl-14 pr-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
            </div>
            @error('deliveryFee') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Save
            </button>

            <span x-data="{ show: false }" x-on:saved.window="show = true; setTimeout(() => show = false, 2000)"
                x-show="show" x-cloak x-transition class="ml-3 text-sm font-medium text-emerald-600">Saved!</span>
        </form>
    </div>
</div>
