<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.vendor', ['title' => 'Orders'])]
class extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public ?int $storeFilter = null;

    public function with(): array
    {
        $vendor = Auth::user()->vendor;

        return [
            'orders' => $vendor->orders()
                ->with('store')
                ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
                ->when($this->storeFilter, fn ($q) => $q->where('store_id', $this->storeFilter))
                ->latest()
                ->paginate(15),
            'stores' => $vendor->stores()->get(),
            'todaysOrderCount' => $vendor->orders()->whereDate('created_at', today())->count(),
            'pendingOrderCount' => $vendor->orders()->where('status', 'pending')->count(),
            'totalOrderCount' => $vendor->orders()->count(),
        ];
    }
};
?>

<div>
    @php
        $statusTabs = ['' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'preparing' => 'Preparing', 'out_for_delivery' => 'Out for delivery', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
        $statusStyles = [
            'pending' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200',
            'confirmed' => 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200',
            'preparing' => 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200',
            'out_for_delivery' => 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200',
            'completed' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
            'cancelled' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-200',
        ];
    @endphp

    <!-- Page header -->
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold tracking-tight text-slate-900">Orders</h2>
            <p class="mt-1 text-sm text-slate-500">Track and manage orders across your stores</p>
        </div>

        <x-ui.select wire:model.live="storeFilter" class="sm:w-56">
            <option value="">All stores</option>
            @foreach ($stores as $store)
                <option value="{{ $store->id }}">{{ $store->name }}</option>
            @endforeach
        </x-ui.select>
    </div>

    <div class="mb-6 grid grid-cols-3 gap-4">
        <x-ui.stat-card label="Today's orders" :value="$todaysOrderCount" color="sky"
            icon="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />
        <x-ui.stat-card label="Pending orders" :value="$pendingOrderCount" :color="$pendingOrderCount > 0 ? 'amber' : 'slate'"
            icon="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-ui.stat-card label="Total orders" :value="$totalOrderCount" color="indigo"
            icon="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
    </div>

    <!-- Status filter pills -->
    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ($statusTabs as $value => $label)
            <button wire:click="$set('statusFilter', '{{ $value }}')"
                class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-medium transition
                    {{ $statusFilter === $value
                        ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/30'
                        : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-50' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <!-- Table card -->
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Order #</th>
                        <th class="px-5 py-3">Store</th>
                        <th class="px-5 py-3">Customer</th>
                        <th class="px-5 py-3">Total</th>
                        <th class="px-5 py-3">Payment</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Placed</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($orders as $order)
                        <tr class="odd:bg-white even:bg-slate-50/50 hover:bg-indigo-50/40 transition-colors">
                            <td class="px-5 py-3.5 font-medium text-slate-900">{{ $order->order_number }}</td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $order->store->name }}</td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $order->customer_name ?? $order->customer_phone }}</td>
                            <td class="px-5 py-3.5 text-slate-600">{{ number_format($order->total, 2) }}</td>
                            <td class="px-5 py-3.5 text-xs uppercase text-slate-500">{{ $order->payment_method }}</td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $statusStyles[$order->status] ?? 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200' }}">
                                    {{ str_replace('_', ' ', $order->status) }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-slate-400">{{ $order->created_at->diffForHumans() }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <a href="{{ route('vendor.orders.show', $order) }}" wire:navigate
                                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-indigo-600 hover:bg-indigo-50">
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-16 text-center">
                                <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.06.435 1.119 1.007z" />
                                </svg>
                                <p class="mt-3 text-sm font-medium text-slate-500">No orders yet</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">
        {{ $orders->links() }}
    </div>
</div>
