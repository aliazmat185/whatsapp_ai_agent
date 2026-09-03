<?php

use App\Models\Store;
use App\Services\Vendor\PackageLimitService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.vendor', ['title' => 'Stores'])]
class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Store::class);
    }

    public function with(): array
    {
        $vendor = Auth::user()->vendor;
        $limitService = app(PackageLimitService::class);

        $stores = $vendor->stores()->with('location')->get();

        return [
            'stores' => $stores,
            'activeStoreCount' => $stores->where('is_active', true)->count(),
            'inactiveStoreCount' => $stores->where('is_active', false)->count(),
            'canAddStore' => $vendor->status === 'approved' && $limitService->canAddStore($vendor),
            'remainingStoreSlots' => $limitService->remainingStoreSlots($vendor),
            'isApproved' => $vendor->status === 'approved',
        ];
    }

    public function toggleActive(int $id): void
    {
        $store = Auth::user()->vendor->stores()->findOrFail($id);
        $this->authorize('update', $store);

        $store->update(['is_active' => ! $store->is_active]);
    }
};
?>

<div>
    {{-- Page header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold tracking-tight text-slate-900">Stores</h2>
            <p class="mt-1 text-sm text-slate-500">Manage your stores and locations.</p>
        </div>
        @if ($isApproved)
            @if ($canAddStore)
                <a href="{{ route('vendor.stores.create') }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New store
                </a>
            @else
                <span title="You have reached your package's store limit."
                    class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white opacity-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New store
                </span>
            @endif
        @endif
    </div>

    @unless ($isApproved)
        <div class="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
            </svg>
            Your account must be approved before you can create stores.
        </div>
    @endunless

    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Stat cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        @php
            $statCards = [
                ['label' => 'Total stores', 'value' => $stores->count(), 'icon' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21', 'iconBg' => 'bg-indigo-50 text-indigo-600', 'valueClass' => 'text-slate-900'],
                ['label' => 'Active', 'value' => $activeStoreCount, 'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'iconBg' => 'bg-emerald-50 text-emerald-600', 'valueClass' => 'text-emerald-600'],
                ['label' => 'Inactive', 'value' => $inactiveStoreCount, 'icon' => 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636', 'iconBg' => 'bg-slate-100 text-slate-500', 'valueClass' => 'text-slate-500'],
                ['label' => 'Slots remaining', 'value' => $remainingStoreSlots === PHP_INT_MAX ? '∞' : $remainingStoreSlots, 'icon' => 'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375', 'iconBg' => 'bg-violet-50 text-violet-600', 'valueClass' => 'text-slate-900'],
            ];
        @endphp
        @foreach ($statCards as $stat)
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-slate-500">{{ $stat['label'] }}</p>
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg {{ $stat['iconBg'] }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $stat['icon'] }}" />
                        </svg>
                    </span>
                </div>
                <p class="mt-2 text-2xl font-bold tracking-tight {{ $stat['valueClass'] }}">{{ $stat['value'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Table / empty state --}}
    @if ($stores->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-indigo-50 text-indigo-500">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75" />
                </svg>
            </span>
            <p class="mt-4 text-sm font-semibold text-slate-900">No stores found</p>
            <p class="mt-1 max-w-sm text-sm text-slate-500">Create your first store to start managing locations, hours, and inventory.</p>
            @if ($isApproved && $canAddStore)
                <a href="{{ route('vendor.stores.create') }}"
                    class="mt-5 inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New store
                </a>
            @endif
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            <th class="px-5 py-3 font-medium text-slate-500">Store</th>
                            <th class="px-5 py-3 font-medium text-slate-500">City</th>
                            <th class="px-5 py-3 font-medium text-slate-500">Type</th>
                            <th class="px-5 py-3 font-medium text-slate-500">Status</th>
                            <th class="px-5 py-3 font-medium text-slate-500">Created</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($stores as $index => $store)
                            <tr class="{{ $index % 2 === 1 ? 'bg-slate-50/40' : '' }} transition hover:bg-indigo-50/40">
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-slate-900">{{ $store->name }}</p>
                                    <p class="text-xs text-slate-400">Store #{{ $store->id }}</p>
                                </td>
                                <td class="px-5 py-3.5 text-slate-600">{{ $store->location?->city ?? '—' }}</td>
                                <td class="px-5 py-3.5">
                                    @if ($store->is_primary)
                                        <span class="inline-flex items-center rounded-full bg-violet-50 px-2.5 py-0.5 text-xs font-medium text-violet-700">Primary</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">Secondary</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5">
                                    @if ($store->is_active)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Active
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
                                            <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span> Inactive
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 text-slate-500">{{ $store->created_at->format('M j, Y') }}</td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('vendor.stores.edit', $store) }}" title="Edit store"
                                            class="rounded-lg p-2 text-slate-400 transition hover:bg-indigo-50 hover:text-indigo-600">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18V6.75A2.25 2.25 0 016 4.5h4.5" />
                                            </svg>
                                        </a>
                                        <button wire:click="toggleActive({{ $store->id }})" title="{{ $store->is_active ? 'Deactivate' : 'Activate' }}"
                                            class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                                            @if ($store->is_active)
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                                </svg>
                                            @else
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            @endif
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
