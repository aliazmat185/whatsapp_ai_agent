<?php

use App\Models\Vendor;
use App\Services\Vendor\VendorApprovalService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.admin', ['title' => 'Vendors'])]
class extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    public ?int $rejectingId = null;

    public ?int $suspendingId = null;

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Vendor::class);
    }

    public function with(): array
    {
        return [
            'vendors' => Vendor::with('package', 'owner')
                ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
                ->when($this->search, fn ($q) => $q->where(function ($q) {
                    $q->where('business_name', 'like', "%{$this->search}%")
                        ->orWhereHas('owner', fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                            ->orWhere('email', 'like', "%{$this->search}%"));
                }))
                ->orderBy($this->sortField, $this->sortDirection)
                ->paginate(15),
            'statusCounts' => [
                '' => Vendor::count(),
                'pending' => Vendor::where('status', 'pending')->count(),
                'approved' => Vendor::where('status', 'approved')->count(),
                'rejected' => Vendor::where('status', 'rejected')->count(),
                'suspended' => Vendor::where('status', 'suspended')->count(),
            ],
        ];
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function approve(int $vendorId, VendorApprovalService $service): void
    {
        $this->authorize('manageApproval', Vendor::class);
        $service->approve(Vendor::findOrFail($vendorId), Auth::user());
    }

    public function startReject(int $vendorId): void
    {
        $this->authorize('manageApproval', Vendor::class);
        $this->rejectingId = $vendorId;
        $this->reason = '';
    }

    public function confirmReject(VendorApprovalService $service): void
    {
        $this->authorize('manageApproval', Vendor::class);
        $this->validate(['reason' => ['required', 'string', 'min:3']]);

        $service->reject(Vendor::findOrFail($this->rejectingId), Auth::user(), $this->reason);
        $this->rejectingId = null;
        $this->reason = '';
    }

    public function startSuspend(int $vendorId): void
    {
        $this->authorize('manageApproval', Vendor::class);
        $this->suspendingId = $vendorId;
        $this->reason = '';
    }

    public function confirmSuspend(VendorApprovalService $service): void
    {
        $this->authorize('manageApproval', Vendor::class);
        $this->validate(['reason' => ['required', 'string', 'min:3']]);

        $service->suspend(Vendor::findOrFail($this->suspendingId), Auth::user(), $this->reason);
        $this->suspendingId = null;
        $this->reason = '';
    }

    public function reactivate(int $vendorId, VendorApprovalService $service): void
    {
        $this->authorize('manageApproval', Vendor::class);
        $service->reactivate(Vendor::findOrFail($vendorId), Auth::user());
    }

    public function cancelAction(): void
    {
        $this->rejectingId = null;
        $this->suspendingId = null;
        $this->reason = '';
    }

    public function getActionVendorProperty(): ?Vendor
    {
        $id = $this->rejectingId ?? $this->suspendingId;

        return $id ? Vendor::with('owner')->find($id) : null;
    }
};
?>

<div>
    @php
        $tabs = ['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'suspended' => 'Suspended'];
        $statusStyles = [
            'pending' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200',
            'approved' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
            'rejected' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-200',
            'suspended' => 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200',
        ];
    @endphp

    <!-- Page header + stat cards -->
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold tracking-tight text-slate-900">Vendors</h2>
            <p class="mt-1 text-sm text-slate-500">{{ $statusCounts[''] }} total vendors across all statuses</p>
        </div>

        <div class="relative w-full sm:w-72">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
            </svg>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search business or owner…"
                class="w-full rounded-lg border-slate-300 bg-white py-2 pl-9 pr-3 text-sm shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'suspended' => 'Suspended'] as $key => $label)
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $statusCounts[$key] }}</p>
            </div>
        @endforeach
    </div>

    <!-- Status filter pills -->
    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ($tabs as $value => $label)
            <button wire:click="$set('statusFilter', '{{ $value }}')"
                class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-medium transition
                    {{ $statusFilter === $value
                        ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/30'
                        : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-50' }}">
                {{ $label }}
                <span class="rounded-full px-1.5 py-0.5 text-xs {{ $statusFilter === $value ? 'bg-white/20' : 'bg-slate-100 text-slate-500' }}">
                    {{ $statusCounts[$value] }}
                </span>
            </button>
        @endforeach
    </div>

    <!-- Table card -->
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" wire:loading.class="opacity-60" wire:target="search,statusFilter,sortBy,approve,confirmReject,confirmSuspend,reactivate">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">
                            <button wire:click="sortBy('business_name')" class="inline-flex items-center gap-1 hover:text-slate-700">
                                Business
                                @if ($sortField === 'business_name')
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $sortDirection === 'asc' ? 'M4.5 15.75l7.5-7.5 7.5 7.5' : 'M19.5 8.25l-7.5 7.5-7.5-7.5' }}" />
                                    </svg>
                                @endif
                            </button>
                        </th>
                        <th class="px-5 py-3">Owner</th>
                        <th class="px-5 py-3">Package</th>
                        <th class="px-5 py-3">
                            <button wire:click="sortBy('status')" class="inline-flex items-center gap-1 hover:text-slate-700">
                                Status
                                @if ($sortField === 'status')
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $sortDirection === 'asc' ? 'M4.5 15.75l7.5-7.5 7.5 7.5' : 'M19.5 8.25l-7.5 7.5-7.5-7.5' }}" />
                                    </svg>
                                @endif
                            </button>
                        </th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($vendors as $vendor)
                        <tr class="odd:bg-white even:bg-slate-50/50 hover:bg-indigo-50/40 transition-colors">
                            <td class="px-5 py-3.5 font-medium text-slate-900">{{ $vendor->business_name }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold uppercase text-indigo-700">
                                        {{ mb_substr($vendor->owner->name, 0, 1) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate text-slate-800">{{ $vendor->owner->name }}</p>
                                        <p class="truncate text-xs text-slate-400">{{ $vendor->owner->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                                    {{ $vendor->package->name }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $statusStyles[$vendor->status] ?? '' }}">
                                    {{ ucfirst($vendor->status) }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($vendor->status === 'pending')
                                        <button wire:click="approve({{ $vendor->id }})" title="Approve"
                                            class="group inline-flex h-8 w-8 items-center justify-center rounded-lg text-emerald-600 hover:bg-emerald-50">
                                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                        </button>
                                        <button wire:click="startReject({{ $vendor->id }})" title="Reject"
                                            class="group inline-flex h-8 w-8 items-center justify-center rounded-lg text-red-600 hover:bg-red-50">
                                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    @elseif ($vendor->status === 'approved')
                                        <button wire:click="startSuspend({{ $vendor->id }})" title="Suspend"
                                            class="group inline-flex h-8 w-8 items-center justify-center rounded-lg text-red-600 hover:bg-red-50">
                                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                            </svg>
                                        </button>
                                    @elseif ($vendor->status === 'suspended')
                                        <button wire:click="reactivate({{ $vendor->id }})"
                                            wire:confirm="Reactivate this vendor?" title="Reactivate"
                                            class="group inline-flex h-8 w-8 items-center justify-center rounded-lg text-emerald-600 hover:bg-emerald-50">
                                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0113.36-4.66M19.5 12a7.5 7.5 0 01-13.36 4.66M4.5 12H2.25m17.25 0H21.75M4.5 4.5v3h3m9 9v3h3" />
                                            </svg>
                                        </button>
                                    @elseif ($vendor->status === 'rejected')
                                        <button wire:click="approve({{ $vendor->id }})"
                                            wire:confirm="Approve this vendor anyway?" title="Approve anyway"
                                            class="group inline-flex h-8 w-8 items-center justify-center rounded-lg text-emerald-600 hover:bg-emerald-50">
                                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21" />
                                </svg>
                                <p class="mt-3 text-sm font-medium text-slate-500">No vendors found</p>
                                <p class="mt-1 text-xs text-slate-400">Try adjusting your search or filter.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div wire:loading.flex wire:target="search,statusFilter,sortBy" class="hidden mt-4 items-center justify-center gap-2 text-sm text-slate-400">
        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        Loading…
    </div>

    <div class="mt-5">
        {{ $vendors->links() }}
    </div>

    @if ($rejectingId || $suspendingId)
        <x-ui.modal
            title="{{ $rejectingId ? 'Reject' : 'Suspend' }} {{ $this->actionVendor?->business_name }}"
            close="cancelAction" maxWidth="max-w-md">
            <form wire:submit="{{ $rejectingId ? 'confirmReject' : 'confirmSuspend' }}" class="px-6 py-5">
                <x-ui.textarea wire:model="reason" name="reason" autofocus
                    label="Reason for {{ $rejectingId ? 'rejection' : 'suspension' }}"
                    placeholder="Explain why this vendor is being {{ $rejectingId ? 'rejected' : 'suspended' }}…" />

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="cancelAction"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="submit"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
                        Confirm
                    </button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
