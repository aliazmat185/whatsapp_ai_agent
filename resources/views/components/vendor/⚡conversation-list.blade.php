<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.vendor', ['title' => 'Conversations'])]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $statusFilter = '';

    public function with(): array
    {
        return [
            'conversations' => Auth::user()->vendor->conversations()
                ->with('store')
                ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
                ->latest('last_message_at')
                ->paginate(15),
        ];
    }
};
?>

<div>
    @php
        $tabs = ['' => 'All', 'active' => 'Active', 'awaiting_customer' => 'Awaiting customer', 'needs_attention' => 'Needs attention', 'closed' => 'Closed'];
        $statusStyles = [
            'active' => 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200',
            'awaiting_customer' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200',
            'needs_attention' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-200',
            'closed' => 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200',
        ];
    @endphp

    <div class="mb-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Conversations</h2>
        <p class="mt-1 text-sm text-slate-500">Chat threads between your stores and customers</p>
    </div>

    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ($tabs as $value => $label)
            <button wire:click="$set('statusFilter', '{{ $value }}')"
                class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-medium transition
                    {{ $statusFilter === $value
                        ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/30'
                        : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-50' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <ul class="divide-y divide-slate-100">
            @forelse ($conversations as $conversation)
                <li>
                    <a href="{{ route('vendor.conversations.show', $conversation) }}" wire:navigate
                        class="flex items-center gap-3 px-5 py-4 hover:bg-indigo-50/40 transition-colors">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold uppercase text-indigo-700">
                            {{ mb_substr($conversation->customer_name ?? $conversation->customer_phone, 0, 1) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-slate-900">{{ $conversation->customer_name ?? $conversation->customer_phone }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $conversation->store?->name ?? '—' }}</p>
                        </div>
                        <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $statusStyles[$conversation->status] ?? 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200' }}">
                            {{ str_replace('_', ' ', $conversation->status) }}
                        </span>
                        <span class="w-28 shrink-0 text-right text-xs text-slate-400">{{ $conversation->last_message_at?->diffForHumans() ?? '—' }}</span>
                        <svg class="h-4 w-4 shrink-0 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </a>
                </li>
            @empty
                <li class="px-5 py-16 text-center">
                    <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                    </svg>
                    <p class="mt-3 text-sm font-medium text-slate-500">No conversations yet</p>
                </li>
            @endforelse
        </ul>
    </div>

    <div class="mt-5">
        {{ $conversations->links() }}
    </div>
</div>
