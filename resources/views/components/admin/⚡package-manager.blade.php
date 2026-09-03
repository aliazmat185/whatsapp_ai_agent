<?php

use App\Models\VendorPackage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.admin', ['title' => 'Packages'])]
class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public float $price = 0;

    public string $billingCycle = 'monthly';

    public int $maxStores = 1;

    public int $maxProducts = 50;

    public int $maxStaffUsers = 1;

    public int $maxWhatsappNumbers = 1;

    public int $maxOrdersPerMonth = -1;

    public bool $aiFeaturesEnabled = true;

    public bool $analyticsAccess = true;

    /** @var array<int, string> */
    public array $enabledPaymentMethods = ['cod'];

    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', VendorPackage::class);
    }

    public function with(): array
    {
        return [
            'packages' => VendorPackage::orderBy('price')->get(),
        ];
    }

    public function create(): void
    {
        $this->authorize('manage', VendorPackage::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('manage', VendorPackage::class);

        $package = VendorPackage::findOrFail($id);
        $this->editingId = $package->id;
        $this->name = $package->name;
        $this->price = (float) $package->price;
        $this->billingCycle = $package->billing_cycle;
        $this->maxStores = $package->max_stores;
        $this->maxProducts = $package->max_products;
        $this->maxStaffUsers = $package->max_staff_users;
        $this->maxWhatsappNumbers = $package->max_whatsapp_numbers;
        $this->maxOrdersPerMonth = $package->max_orders_per_month;
        $this->aiFeaturesEnabled = $package->ai_features_enabled;
        $this->analyticsAccess = $package->analytics_access;
        $this->enabledPaymentMethods = $package->enabled_payment_methods ?? [];
        $this->isActive = $package->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage', VendorPackage::class);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'billingCycle' => ['required', 'in:monthly,yearly'],
            'maxStores' => ['required', 'integer', 'min:-1'],
            'maxProducts' => ['required', 'integer', 'min:-1'],
            'maxStaffUsers' => ['required', 'integer', 'min:-1'],
            'maxWhatsappNumbers' => ['required', 'integer', 'min:-1'],
            'maxOrdersPerMonth' => ['required', 'integer', 'min:-1'],
        ]);

        $data = [
            'name' => $this->name,
            'price' => $this->price,
            'billing_cycle' => $this->billingCycle,
            'max_stores' => $this->maxStores,
            'max_products' => $this->maxProducts,
            'max_staff_users' => $this->maxStaffUsers,
            'max_whatsapp_numbers' => $this->maxWhatsappNumbers,
            'max_orders_per_month' => $this->maxOrdersPerMonth,
            'ai_features_enabled' => $this->aiFeaturesEnabled,
            'analytics_access' => $this->analyticsAccess,
            'enabled_payment_methods' => $this->enabledPaymentMethods,
            'is_active' => $this->isActive,
        ];

        if ($this->editingId) {
            VendorPackage::findOrFail($this->editingId)->update($data);
        } else {
            $data['slug'] = Str::slug($this->name).'-'.Str::random(4);
            VendorPackage::create($data);
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manage', VendorPackage::class);

        $package = VendorPackage::findOrFail($id);
        $package->update(['is_active' => ! $package->is_active]);
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'price', 'billingCycle', 'maxStores', 'maxProducts',
            'maxStaffUsers', 'maxWhatsappNumbers', 'maxOrdersPerMonth',
            'aiFeaturesEnabled', 'analyticsAccess', 'enabledPaymentMethods', 'isActive',
        ]);
        $this->billingCycle = 'monthly';
        $this->maxStores = 1;
        $this->maxProducts = 50;
        $this->maxStaffUsers = 1;
        $this->maxWhatsappNumbers = 1;
        $this->maxOrdersPerMonth = -1;
        $this->aiFeaturesEnabled = true;
        $this->analyticsAccess = true;
        $this->enabledPaymentMethods = ['cod'];
        $this->isActive = true;
    }
};
?>

<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold tracking-tight text-slate-900">Packages</h2>
            <p class="mt-1 text-sm text-slate-500">Packages control store/product/staff limits and feature access per vendor.</p>
        </div>
        @can('manage', \App\Models\VendorPackage::class)
            <button wire:click="create"
                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                New package
            </button>
        @endcan
    </div>

    @if ($showForm)
        <div class="fixed inset-0 z-30 flex items-center justify-center bg-slate-900/40 p-4" wire:click.self="cancel"
            x-data x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
            <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl"
                x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
                <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <h2 class="text-base font-semibold text-slate-900">{{ $editingId ? 'Edit package' : 'New package' }}</h2>
                    <button type="button" wire:click="cancel" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="save" class="max-h-[75vh] overflow-y-auto px-6 py-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <x-ui.input wire:model="name" name="name" type="text" label="Name" />
                        </div>

                        <div>
                            <x-ui.input wire:model="price" name="price" type="number" step="0.01" label="Price" />
                        </div>

                        <div>
                            <x-ui.select wire:model="billingCycle" name="billingCycle" label="Billing cycle">
                                <option value="monthly">Monthly</option>
                                <option value="yearly">Yearly</option>
                            </x-ui.select>
                        </div>

                        <div>
                            <x-ui.input wire:model="maxStores" name="maxStores" type="number" label="Max stores (-1 = unlimited)" />
                        </div>

                        <div>
                            <x-ui.input wire:model="maxProducts" name="maxProducts" type="number" label="Max products (-1 = unlimited)" />
                        </div>

                        <div>
                            <x-ui.input wire:model="maxStaffUsers" name="maxStaffUsers" type="number" label="Max staff users (-1 = unlimited)" />
                        </div>

                        <div>
                            <x-ui.input wire:model="maxWhatsappNumbers" name="maxWhatsappNumbers" type="number" label="Max WhatsApp numbers" />
                        </div>

                        <div class="col-span-2">
                            <x-ui.input wire:model="maxOrdersPerMonth" name="maxOrdersPerMonth" type="number" label="Max orders/month (-1 = unlimited)" />
                        </div>

                        <div class="col-span-2">
                            <label class="mb-2 block text-sm font-medium text-slate-700">Enabled payment methods</label>
                            <div class="flex flex-wrap gap-3">
                                @foreach (['cod' => 'COD', 'jazzcash' => 'JazzCash', 'easypaisa' => 'Easypaisa', 'card' => 'Card'] as $value => $label)
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 has-checked:border-indigo-300 has-checked:bg-indigo-50 has-checked:text-indigo-700">
                                        <input type="checkbox" wire:model="enabledPaymentMethods" value="{{ $value }}"
                                            class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-400">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="col-span-2 flex flex-wrap gap-x-8 gap-y-3 border-t border-slate-100 pt-4">
                            <x-ui.checkbox wire:model="aiFeaturesEnabled" label="AI features enabled" />
                            <x-ui.checkbox wire:model="analyticsAccess" label="Analytics access" />
                            <x-ui.checkbox wire:model="isActive" label="Active" />
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-4">
                        <button type="button" wire:click="cancel"
                            class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                            Cancel
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60">
                            <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Name</th>
                        <th class="px-5 py-3">Price</th>
                        <th class="px-5 py-3">Stores</th>
                        <th class="px-5 py-3">Products</th>
                        <th class="px-5 py-3">Staff</th>
                        <th class="px-5 py-3">Payment methods</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($packages as $package)
                        <tr class="odd:bg-white even:bg-slate-50/50 hover:bg-indigo-50/40 transition-colors">
                            <td class="px-5 py-3.5 font-medium text-slate-900">{{ $package->name }}</td>
                            <td class="px-5 py-3.5 text-slate-700">{{ number_format($package->price, 2) }} <span class="text-slate-400">/ {{ $package->billing_cycle }}</span></td>
                            <td class="px-5 py-3.5 text-slate-700">{{ $package->max_stores === -1 ? '∞' : $package->max_stores }}</td>
                            <td class="px-5 py-3.5 text-slate-700">{{ $package->max_products === -1 ? '∞' : $package->max_products }}</td>
                            <td class="px-5 py-3.5 text-slate-700">{{ $package->max_staff_users === -1 ? '∞' : $package->max_staff_users }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($package->enabled_payment_methods ?? [] as $method)
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                                            {{ $method }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $package->is_active ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-1 ring-inset ring-slate-200' }}">
                                    {{ $package->is_active ? 'Active' : 'Retired' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1.5">
                                    @can('manage', \App\Models\VendorPackage::class)
                                        <button wire:click="edit({{ $package->id }})" title="Edit"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-indigo-600 hover:bg-indigo-50">
                                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 9.75l-4.5-4.5" />
                                            </svg>
                                        </button>
                                        <button wire:click="toggleActive({{ $package->id }})"
                                            wire:confirm="{{ $package->is_active ? 'Retire' : 'Reactivate' }} this package?"
                                            title="{{ $package->is_active ? 'Retire' : 'Reactivate' }}"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg {{ $package->is_active ? 'text-red-600 hover:bg-red-50' : 'text-emerald-600 hover:bg-emerald-50' }}">
                                            @if ($package->is_active)
                                                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                                </svg>
                                            @else
                                                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0113.36-4.66M19.5 12a7.5 7.5 0 01-13.36 4.66M4.5 12H2.25m17.25 0H21.75M4.5 4.5v3h3m9 9v3h3" />
                                                </svg>
                                            @endif
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-16 text-center">
                                <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                                </svg>
                                <p class="mt-3 text-sm font-medium text-slate-500">No packages yet</p>
                                <p class="mt-1 text-xs text-slate-400">Create one to get started.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
