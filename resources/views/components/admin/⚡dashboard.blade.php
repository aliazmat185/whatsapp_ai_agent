<?php

use App\Models\Order;
use App\Models\Vendor;
use App\Models\VendorPackage;
use App\Models\WebhookLog;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.admin', ['title' => 'Dashboard'])]
class extends Component
{
    public function with(): array
    {
        $revenueByPackage = VendorPackage::query()
            ->withCount('vendors')
            ->get()
            ->map(fn (VendorPackage $package) => [
                'name' => $package->name,
                'vendor_count' => $package->vendors_count,
                'monthly_revenue' => (float) $package->price * $package->vendors_count,
            ]);

        $totalWebhooks = WebhookLog::count();
        $failedWebhooks = WebhookLog::where('processing_status', 'failed')->count();

        $days = collect(range(13, 0))->map(fn ($i) => today()->subDays($i));
        $ordersByDay = Order::whereDate('created_at', '>=', $days->first())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count, SUM(total) as revenue')
            ->groupBy('day')
            ->pluck('count', 'day');
        $revenueByDay = Order::whereIn('status', ['confirmed', 'preparing', 'out_for_delivery', 'completed'])
            ->whereDate('created_at', '>=', $days->first())
            ->selectRaw('DATE(created_at) as day, SUM(total) as revenue')
            ->groupBy('day')
            ->pluck('revenue', 'day');

        return [
            'totalVendors' => Vendor::count(),
            'pendingVendors' => Vendor::where('status', 'pending')->count(),
            'approvedVendors' => Vendor::where('status', 'approved')->count(),
            'suspendedVendors' => Vendor::where('status', 'suspended')->count(),
            'activePackages' => VendorPackage::where('is_active', true)->count(),

            'totalOrders' => Order::count(),
            'ordersToday' => Order::whereDate('created_at', today())->count(),
            'platformRevenue' => Order::whereIn('status', ['confirmed', 'preparing', 'out_for_delivery', 'completed'])->sum('total'),

            'activeConversations' => \App\Models\Conversation::where('status', 'active')
                ->where('last_message_at', '>=', now()->subHours(24))
                ->count(),
            'conversationsNeedingAttention' => \App\Models\Conversation::where('status', 'needs_attention')->count(),

            'revenueByPackage' => $revenueByPackage,

            'webhookFailureRate' => $totalWebhooks > 0 ? round(($failedWebhooks / $totalWebhooks) * 100, 1) : 0,
            'totalWebhooks' => $totalWebhooks,
            'failedWebhooks' => $failedWebhooks,

            'trendLabels' => $days->map(fn ($d) => $d->format('M j'))->values(),
            'trendOrders' => $days->map(fn ($d) => (int) ($ordersByDay[$d->toDateString()] ?? 0))->values(),
            'trendRevenue' => $days->map(fn ($d) => (float) ($revenueByDay[$d->toDateString()] ?? 0))->values(),
        ];
    }
};
?>

<div class="space-y-6">
    @if ($pendingVendors > 0)
        <x-ui.alert-banner color="amber">
            <span class="font-semibold">{{ $pendingVendors }}</span> vendor(s) waiting for approval.
            <a href="{{ route('admin.vendors') }}" wire:navigate class="ml-2 font-medium underline">Review now</a>
        </x-ui.alert-banner>
    @endif

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-ui.stat-card label="Total vendors" :value="$totalVendors" color="indigo" href="{{ route('admin.vendors') }}"
            icon="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
        <x-ui.stat-card label="Pending approval" :value="$pendingVendors" :color="$pendingVendors > 0 ? 'amber' : 'slate'" href="{{ route('admin.vendors', ['status' => 'pending']) }}"
            icon="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-ui.stat-card label="Orders today" :value="$ordersToday" color="sky" href="{{ route('admin.dashboard') }}"
            icon="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />
        <x-ui.stat-card label="Platform GMV" :value="number_format($platformRevenue, 2)" color="emerald"
            icon="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
    </div>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-ui.stat-card label="Approved vendors" :value="$approvedVendors" color="emerald"
            icon="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-ui.stat-card label="Suspended vendors" :value="$suspendedVendors" :color="$suspendedVendors > 0 ? 'red' : 'slate'"
            icon="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
        <x-ui.stat-card label="Active conversations (24h)" :value="$activeConversations" color="sky" href="{{ route('admin.dashboard') }}"
            icon="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
        <x-ui.stat-card label="Needs attention" :value="$conversationsNeedingAttention" :color="$conversationsNeedingAttention > 0 ? 'red' : 'slate'"
            icon="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-slate-900">Order volume &amp; revenue</h3>
                    <p class="mt-0.5 text-xs text-slate-400">Last 14 days</p>
                </div>
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
                                        label: 'Revenue',
                                        data: @js($trendRevenue),
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

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="mb-4 font-semibold text-slate-900">Webhook health</h3>
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg {{ $webhookFailureRate > 5 ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600' }}">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                    </svg>
                </div>
                <div>
                    <p class="text-2xl font-semibold tracking-tight {{ $webhookFailureRate > 5 ? 'text-red-600' : 'text-slate-900' }}">{{ $webhookFailureRate }}%</p>
                    <p class="text-xs text-slate-400">failure rate</p>
                </div>
            </div>
            <p class="mt-4 text-sm text-slate-500">{{ $failedWebhooks }} failed of {{ number_format($totalWebhooks) }} total</p>
            <a href="{{ route('admin.webhook-logs') }}" wire:navigate class="mt-3 inline-flex text-sm font-medium text-indigo-600 hover:text-indigo-500">View webhook logs &rarr;</a>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <div>
                <h3 class="font-semibold text-slate-900">Revenue by package</h3>
                <p class="text-xs text-slate-400 mt-0.5">Monthly recurring revenue per package tier</p>
            </div>
            <a href="{{ route('admin.packages') }}" wire:navigate
                class="text-sm font-medium text-indigo-600 hover:text-indigo-500">Manage packages &rarr;</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-400">
                        <th class="px-5 py-3 font-medium">Package</th>
                        <th class="px-5 py-3 font-medium">Vendors</th>
                        <th class="px-5 py-3 font-medium text-right">Monthly revenue</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($revenueByPackage as $row)
                        <tr class="transition-colors hover:bg-slate-50">
                            <td class="px-5 py-3 font-medium text-slate-900">{{ $row['name'] }}</td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $row['vendor_count'] > 0 ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-500' }}">
                                    {{ $row['vendor_count'] }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right font-semibold tabular-nums {{ $row['monthly_revenue'] > 0 ? 'text-emerald-600' : 'text-slate-400' }}">
                                {{ number_format($row['monthly_revenue'], 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-5 py-8 text-center text-slate-400">No packages yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
