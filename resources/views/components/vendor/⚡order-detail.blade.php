<?php

use App\Models\Order;
use App\Models\Product;
use App\Services\Commerce\OrderService;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.vendor', ['title' => 'Order Detail'])]
class extends Component
{
    public Order $order;

    public bool $editing = false;

    public ?int $newProductId = null;

    public int $newQuantity = 1;

    public ?string $editError = null;

    public ?string $pendingAction = null;

    public ?string $pendingLabel = null;

    public function mount(Order $order): void
    {
        if ($order->vendor_id !== Auth::user()->vendor_id) {
            abort(404);
        }

        $this->order = $order;
    }

    public function with(): array
    {
        return [
            'items' => $this->order->items()->get(),
            'nextStatuses' => Order::ALLOWED_TRANSITIONS[$this->order->status] ?? [],
            'availableProducts' => $this->editing
                ? Product::withoutGlobalScope('vendor')->where('store_id', $this->order->store_id)->where('is_active', true)->orderBy('name')->get()
                : [],
        ];
    }

    public function requestConfirm(string $action, string $label): void
    {
        $this->pendingAction = $action;
        $this->pendingLabel = $label;
    }

    public function cancelPendingAction(): void
    {
        $this->pendingAction = null;
        $this->pendingLabel = null;
    }

    public function confirmPendingAction(OrderService $orderService, PaymentService $paymentService): void
    {
        [$type, $value] = array_pad(explode(':', (string) $this->pendingAction, 2), 2, null);

        match ($type) {
            'status' => $this->transitionOrderStatus($value, $orderService),
            'payment' => $this->markPaymentPaid($paymentService),
            'remove' => $this->removeItem((int) $value, $orderService),
            default => null,
        };

        $this->cancelPendingAction();
    }

    public function transitionOrderStatus(string $newStatus, OrderService $orderService): void
    {
        $orderService->transitionStatus($this->order, $newStatus, Auth::user());
        $this->order = $this->order->fresh();
    }

    public function markPaymentPaid(PaymentService $paymentService): void
    {
        $paymentService->markPaid($this->order->payment);
        $this->order = $this->order->fresh();
    }

    public function toggleEdit(): void
    {
        $this->editing = ! $this->editing;
        $this->editError = null;
    }

    public function updateItemQuantity(int $itemId, int $quantity, OrderService $orderService): void
    {
        $this->editError = null;

        try {
            $item = $this->order->items()->findOrFail($itemId);
            $this->order = $orderService->updateItemQuantity($this->order, $item, $quantity);
        } catch (\InvalidArgumentException $e) {
            $this->editError = $e->getMessage();
        }
    }

    public function removeItem(int $itemId, OrderService $orderService): void
    {
        $this->editError = null;

        try {
            $item = $this->order->items()->findOrFail($itemId);
            $this->order = $orderService->removeItem($this->order, $item);
        } catch (\InvalidArgumentException $e) {
            $this->editError = $e->getMessage();
        }
    }

    public function addItem(OrderService $orderService): void
    {
        $this->editError = null;

        if (! $this->newProductId) {
            $this->editError = 'Pick a product to add.';

            return;
        }

        try {
            $this->order = $orderService->addItem($this->order, $this->newProductId, $this->newQuantity);
            $this->newProductId = null;
            $this->newQuantity = 1;
        } catch (\InvalidArgumentException $e) {
            $this->editError = $e->getMessage();
        }
    }
};
?>

<div>
    @php
        $statusColors = [
            'pending' => 'amber',
            'confirmed' => 'blue',
            'preparing' => 'blue',
            'out_for_delivery' => 'blue',
            'completed' => 'emerald',
            'cancelled' => 'red',
        ];
    @endphp

    <div class="mb-4 flex items-start justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div>
            <h2 class="text-lg font-semibold tracking-tight text-slate-900">{{ $order->order_number }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ $order->store->name }} &middot; Placed {{ $order->created_at->format('M j, Y g:i A') }}</p>
        </div>
        <x-ui.badge :color="$statusColors[$order->status] ?? 'slate'">{{ str_replace('_', ' ', $order->status) }}</x-ui.badge>
    </div>

    <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">Customer</h3>
            <p class="text-sm font-medium text-slate-900">{{ $order->customer_name ?? '—' }}</p>
            <p class="text-sm text-slate-500">{{ $order->customer_phone }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ $order->customer_address ?? 'No address provided' }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">Payment</h3>
            <p class="text-sm font-medium uppercase text-slate-900">{{ $order->payment_method }}</p>
            <p class="mb-2 text-sm text-slate-500">Status: {{ $order->payment_status }}</p>
            @if ($order->payment_method === 'cod' && $order->payment_status !== 'paid')
                <button wire:click="requestConfirm('payment', 'Mark this order\'s payment as received?')"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50">
                    Mark payment received
                </button>
            @endif
        </div>
    </div>

    <div class="mb-4 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
            <h3 class="text-xs font-medium uppercase tracking-wide text-slate-400">Items</h3>
            @if ($order->isEditable())
                <button wire:click="toggleEdit" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">
                    {{ $editing ? 'Done editing' : 'Edit order' }}
                </button>
            @endif
        </div>

        @if ($editError)
            <div class="border-b border-red-100 bg-red-50 px-5 py-2.5 text-xs text-red-700">{{ $editError }}</div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Item</th>
                        <th class="px-5 py-3">Qty</th>
                        <th class="px-5 py-3">Unit price</th>
                        <th class="px-5 py-3">Line total</th>
                        @if ($editing)
                            <th class="px-5 py-3"></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($items as $item)
                        <tr class="odd:bg-white even:bg-slate-50/50">
                            <td class="px-5 py-3.5 font-medium text-slate-900">{{ $item->product_name_snapshot }}</td>
                            <td class="px-5 py-3.5 text-slate-600">
                                @if ($editing)
                                    <input type="number" min="1" value="{{ $item->quantity }}"
                                        wire:change="updateItemQuantity({{ $item->id }}, $event.target.value)"
                                        class="w-16 rounded-md border border-slate-200 px-2 py-1 text-sm" />
                                @else
                                    {{ $item->quantity }}
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-slate-600">{{ number_format($item->unit_price, 2) }}</td>
                            <td class="px-5 py-3.5 text-slate-600">{{ number_format($item->line_total, 2) }}</td>
                            @if ($editing)
                                <td class="px-5 py-3.5 text-right">
                                    <button wire:click="requestConfirm('remove:{{ $item->id }}', 'Remove {{ $item->product_name_snapshot }} from this order?')"
                                        class="text-xs font-medium text-red-600 hover:text-red-700">Remove</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach

                    @if ($editing)
                        <tr class="bg-slate-50/50">
                            <td class="px-5 py-3" colspan="2">
                                <select wire:model="newProductId" class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-sm">
                                    <option value="">Add a product&hellip;</option>
                                    @foreach ($availableProducts as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }} ({{ number_format($product->base_price, 2) }})</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-5 py-3">
                                <input type="number" min="1" wire:model="newQuantity" class="w-16 rounded-md border border-slate-200 px-2 py-1 text-sm" />
                            </td>
                            <td class="px-5 py-3" colspan="2">
                                <button wire:click="addItem" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700">Add item</button>
                            </td>
                        </tr>
                    @endif
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-200">
                        <td colspan="{{ $editing ? 4 : 3 }}" class="px-5 py-2.5 text-right font-medium text-slate-600">Subtotal</td>
                        <td class="px-5 py-2.5 text-slate-900">{{ number_format($order->subtotal, 2) }}</td>
                    </tr>
                    <tr>
                        <td colspan="{{ $editing ? 4 : 3 }}" class="px-5 py-2.5 text-right font-medium text-slate-600">Delivery fee</td>
                        <td class="px-5 py-2.5 text-slate-900">{{ number_format($order->delivery_fee, 2) }}</td>
                    </tr>
                    <tr>
                        <td colspan="{{ $editing ? 4 : 3 }}" class="px-5 py-3 text-right font-semibold text-slate-900">Total</td>
                        <td class="px-5 py-3 font-semibold text-slate-900">{{ number_format($order->total, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    @if (! empty($nextStatuses))
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">Update status</h3>
            <div class="flex flex-wrap gap-2">
                @foreach ($nextStatuses as $status)
                    <button wire:click="requestConfirm('status:{{ $status }}', 'Mark this order as {{ str_replace('_', ' ', $status) }}?')"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700">
                        Mark as {{ str_replace('_', ' ', $status) }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if ($pendingAction)
        <x-ui.modal close="cancelPendingAction" maxWidth="max-w-sm">
            <div class="px-6 py-5">
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h2 class="text-sm font-semibold text-slate-900">Confirm action</h2>
                </div>
                <p class="text-sm text-slate-600">{{ $pendingLabel }}</p>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="cancelPendingAction"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="button" wire:click="confirmPendingAction" wire:loading.attr="disabled" wire:target="confirmPendingAction"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60">
                        <svg wire:loading wire:target="confirmPendingAction" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Confirm
                    </button>
                </div>
            </div>
        </x-ui.modal>
    @endif
</div>
