<?php

use App\Models\Inventory;
use App\Models\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.vendor', ['title' => 'Dashboard'])]
class extends Component
{
    public function dismissNotification(int $id): void
    {
        $notification = Notification::where('notifiable_id', Auth::user()->vendor_id)
            ->where('notifiable_type', \App\Models\Vendor::class)
            ->findOrFail($id);

        $notification->markAsRead();
    }

    public function with(): array
    {
        $vendor = Auth::user()->vendor;

        $todaysOrders = $vendor->orders()->whereDate('created_at', today());
        $pendingOrders = $vendor->orders()->where('status', 'pending');
        $storeIds = $vendor->stores()->pluck('id');

        $days = collect(range(13, 0))->map(fn ($i) => today()->subDays($i));
        $ordersByDay = $vendor->orders()
            ->whereDate('created_at', '>=', $days->first())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->groupBy('day')
            ->pluck('count', 'day');
        $salesByDay = $vendor->orders()
            ->whereIn('status', ['confirmed', 'preparing', 'out_for_delivery', 'completed'])
            ->whereDate('created_at', '>=', $days->first())
            ->selectRaw('DATE(created_at) as day, SUM(total) as revenue')
            ->groupBy('day')
            ->pluck('revenue', 'day');

        return [
            'vendor' => $vendor,
            'storeCount' => $vendor->stores()->count(),
            'activeStoreCount' => $vendor->stores()->where('is_active', true)->count(),
            'productCount' => $vendor->products()->count(),
            'todaysOrderCount' => $todaysOrders->count(),
            'todaysSales' => (clone $todaysOrders)->whereIn('status', ['confirmed', 'preparing', 'out_for_delivery', 'completed'])->sum('total'),
            'pendingOrderCount' => $pendingOrders->count(),
            'lowStockCount' => Inventory::whereIn('store_id', $storeIds)
                ->where('track_stock', true)
                ->whereColumn('quantity', '<=', 'low_stock_threshold')
                ->count(),
            'needsAttentionCount' => $vendor->conversations()->where('status', 'needs_attention')->count(),
            'unreadNotifications' => $vendor->notifications()->where('channel', 'dashboard')->whereNull('read_at')->limit(5)->get(),

            'trendLabels' => $days->map(fn ($d) => $d->format('M j'))->values(),
            'trendOrders' => $days->map(fn ($d) => (int) ($ordersByDay[$d->toDateString()] ?? 0))->values(),
            'trendSales' => $days->map(fn ($d) => (float) ($salesByDay[$d->toDateString()] ?? 0))->values(),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Dashboard</h2>
        <p class="mt-1 text-sm text-slate-500">Overview of {{ $vendor->business_name }}</p>
    </div>

    @if ($vendor->status === 'pending')
        <x-ui.alert-banner color="amber" class="mb-6">
            Your account is awaiting admin approval. You'll be able to create stores and connect WhatsApp once approved.
        </x-ui.alert-banner>
    @elseif ($vendor->status === 'rejected')
        <x-ui.alert-banner color="red" class="mb-6">
            Your registration was rejected. Reason: {{ $vendor->rejection_reason ?? 'not specified' }}
        </x-ui.alert-banner>
    @elseif ($vendor->status === 'suspended')
        <x-ui.alert-banner color="red" class="mb-6">
            Your account has been suspended. Reason: {{ $vendor->suspended_reason ?? 'not specified' }}
        </x-ui.alert-banner>
    @else
        <x-ui.alert-banner color="emerald" class="mb-6" icon="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z">
            Your account is approved. Package: {{ $vendor->package->name }}.
        </x-ui.alert-banner>

        @if ($needsAttentionCount > 0)
            <x-ui.alert-banner color="red" class="mb-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <span>{{ $needsAttentionCount }} conversation(s) need your attention — the AI couldn't resolve them.</span>
                    <a href="{{ route('vendor.conversations', ['statusFilter' => 'needs_attention']) }}" wire:navigate class="font-medium underline">Review now</a>
                </div>
            </x-ui.alert-banner>
        @endif

        @if ($unreadNotifications->isNotEmpty())
            <div class="mb-6 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                <p class="mb-1 font-medium">Recent activity</p>
                <ul class="space-y-1">
                    @foreach ($unreadNotifications as $notification)
                        <li class="flex items-center justify-between gap-2">
                            <span>New order {{ $notification->payload['order_number'] ?? '' }} at {{ $notification->payload['store_name'] ?? '' }} — {{ $vendor->default_currency }} {{ number_format($notification->payload['total'] ?? 0, 2) }}</span>
                            <button wire:click="dismissNotification({{ $notification->id }})" class="ml-2 shrink-0 text-xs font-medium text-indigo-600 hover:underline">Dismiss</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
            <x-ui.stat-card label="Today's orders" :value="$todaysOrderCount" color="sky" href="{{ route('vendor.orders') }}"
                icon="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />
            <x-ui.stat-card label="Pending orders" :value="$pendingOrderCount" :color="$pendingOrderCount > 0 ? 'amber' : 'slate'" href="{{ route('vendor.orders') }}"
                icon="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            <x-ui.stat-card label="Today's sales" :value="$vendor->default_currency.' '.number_format($todaysSales, 2)" color="emerald"
                icon="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
            <x-ui.stat-card label="Low stock items" :value="$lowStockCount" :color="$lowStockCount > 0 ? 'red' : 'slate'"
                icon="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
        </div>

        <div class="mb-4 grid grid-cols-2 gap-4">
            <x-ui.stat-card label="Stores" value="{{ $activeStoreCount }} / {{ $storeCount }} active" color="indigo" href="{{ route('vendor.stores') }}"
                icon="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35" />
            <x-ui.stat-card label="Products" :value="$productCount" color="violet" href="{{ route('vendor.products') }}"
                icon="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h3 class="font-semibold text-slate-900">Sales &amp; orders</h3>
                <p class="mt-0.5 text-xs text-slate-400">Last 14 days</p>
            </div>
            <div class="h-64" wire:ignore
                x-data="{
                    chart: null,
                    init() {
                        this.chart = new Chart(this.$refs.canvas, {
                            type: 'bar',
                            data: {
                                labels: @js($trendLabels),
                                datasets: [
                                    {
                                        label: 'Orders',
                                        data: @js($trendOrders),
                                        backgroundColor: '#818cf8',
                                        borderRadius: 4,
                                        yAxisID: 'y',
                                    },
                                    {
                                        label: 'Sales',
                                        data: @js($trendSales),
                                        type: 'line',
                                        borderColor: '#10b981',
                                        backgroundColor: '#10b981',
                                        tension: 0.35,
                                        pointRadius: 0,
                                        yAxisID: 'y1',
                                    },
                                ],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false },
                                scales: {
                                    y: { beginAtZero: true, position: 'left', grid: { display: false } },
                                    y1: { beginAtZero: true, position: 'right', grid: { display: false } },
                                },
                                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } },
                            },
                        });
                    },
                }">
                <canvas x-ref="canvas"></canvas>
            </div>
        </div>
    @endif
</div>
