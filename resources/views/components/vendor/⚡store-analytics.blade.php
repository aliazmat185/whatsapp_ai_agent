<?php

use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * PLAN.md §11 Vendor panel — "store-wise sales, best-selling products,
 * conversation-to-order conversion rate."
 */
new
#[Layout('layouts.vendor', ['title' => 'Analytics'])]
class extends Component
{
    public ?int $storeId = null;

    public function mount(): void
    {
        $this->storeId = Auth::user()->vendor->stores()->value('id');
    }

    public function with(): array
    {
        $vendor = Auth::user()->vendor;
        $stores = $vendor->stores()->get();

        if (! $this->storeId) {
            return ['stores' => $stores, 'hasStore' => false];
        }

        $store = $stores->firstWhere('id', $this->storeId);

        if (! $store) {
            abort(404);
        }

        $completedStatuses = ['confirmed', 'preparing', 'out_for_delivery', 'completed'];

        $totalSales = Order::where('store_id', $store->id)
            ->whereIn('status', $completedStatuses)
            ->sum('total');

        $orderCount = Order::where('store_id', $store->id)->count();

        $bestSellers = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)
            ->whereIn('orders.status', $completedStatuses)
            ->selectRaw('order_items.product_name_snapshot, SUM(order_items.quantity) as total_quantity, SUM(order_items.line_total) as total_revenue')
            ->groupBy('order_items.product_name_snapshot')
            ->orderByDesc('total_quantity')
            ->limit(5)
            ->get();

        $totalConversations = Conversation::where('store_id', $store->id)->count();
        $conversionRate = $totalConversations > 0 ? round(($orderCount / $totalConversations) * 100, 1) : 0;

        $days = collect(range(13, 0))->map(fn ($i) => today()->subDays($i));
        $salesByDay = Order::where('store_id', $store->id)
            ->whereIn('status', $completedStatuses)
            ->whereDate('created_at', '>=', $days->first())
            ->selectRaw('DATE(created_at) as day, SUM(total) as revenue')
            ->groupBy('day')
            ->pluck('revenue', 'day');

        return [
            'stores' => $stores,
            'hasStore' => true,
            'store' => $store,
            'totalSales' => (float) $totalSales,
            'orderCount' => $orderCount,
            'bestSellers' => $bestSellers,
            'totalConversations' => $totalConversations,
            'conversionRate' => $conversionRate,
            'trendLabels' => $days->map(fn ($d) => $d->format('M j'))->values(),
            'trendSales' => $days->map(fn ($d) => (float) ($salesByDay[$d->toDateString()] ?? 0))->values(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold tracking-tight text-slate-900">Analytics</h2>
            <p class="mt-1 text-sm text-slate-500">Sales performance and AI conversion for a single store.</p>
        </div>
        @if ($hasStore)
            <x-ui.select wire:model.live="storeId" class="sm:w-56">
                @foreach ($stores as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
            </x-ui.select>
        @endif
    </div>

    @if (! $hasStore)
        <x-ui.alert-banner color="amber">
            Create a store first to see analytics.
        </x-ui.alert-banner>
    @else
        <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4" wire:loading.class="opacity-60" wire:target="storeId">
            <x-ui.stat-card label="Total sales" :value="number_format($totalSales, 2)" color="emerald"
                icon="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
            <x-ui.stat-card label="Total orders" :value="$orderCount" color="sky"
                icon="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />
            <x-ui.stat-card label="Conversations" :value="$totalConversations" color="indigo"
                icon="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
            <x-ui.stat-card label="Conversion rate" value="{{ $conversionRate }}%" color="violet"
                icon="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
        </div>

        <div class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h3 class="font-semibold text-slate-900">Sales trend</h3>
                <p class="mt-0.5 text-xs text-slate-400">Last 14 days</p>
            </div>
            <div class="h-56" wire:ignore wire:key="chart-{{ $store->id }}"
                x-data="{
                    chart: null,
                    init() {
                        this.chart = new Chart(this.$refs.canvas, {
                            type: 'line',
                            data: {
                                labels: @js($trendLabels),
                                datasets: [{
                                    label: 'Sales',
                                    data: @js($trendSales),
                                    borderColor: '#10b981',
                                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                    fill: true,
                                    tension: 0.35,
                                    pointRadius: 0,
                                }],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: { y: { beginAtZero: true, grid: { display: false } } },
                                plugins: { legend: { display: false } },
                            },
                        });
                    },
                }">
                <canvas x-ref="canvas"></canvas>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="font-semibold text-slate-900">Best-selling products</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <th class="px-5 py-3">#</th>
                            <th class="px-5 py-3">Product</th>
                            <th class="px-5 py-3">Quantity sold</th>
                            <th class="px-5 py-3">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($bestSellers as $item)
                            <tr class="odd:bg-white even:bg-slate-50/50 hover:bg-indigo-50/40 transition-colors">
                                <td class="px-5 py-3.5">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold
                                        {{ $loop->index === 0 ? 'bg-amber-100 text-amber-700' : ($loop->index === 1 ? 'bg-slate-200 text-slate-600' : ($loop->index === 2 ? 'bg-orange-100 text-orange-700' : 'bg-slate-100 text-slate-400')) }}">
                                        {{ $loop->iteration }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 font-medium text-slate-900">{{ $item->product_name_snapshot }}</td>
                                <td class="px-5 py-3.5 text-slate-600">{{ $item->total_quantity }}</td>
                                <td class="px-5 py-3.5 text-slate-600">{{ number_format($item->total_revenue, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <x-ui.empty-state message="No completed orders yet."
                                        icon="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
