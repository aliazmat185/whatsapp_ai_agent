<?php

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.vendor', ['title' => 'Conversation'])]
class extends Component
{
    public Conversation $conversation;

    public string $reply = '';

    public function mount(Conversation $conversation): void
    {
        if ($conversation->vendor_id !== Auth::user()->vendor_id) {
            abort(404);
        }

        $this->conversation = $conversation;
    }

    public function with(): array
    {
        return [
            'messages' => $this->conversation->messages()->get(),
        ];
    }

    public function markResolved(): void
    {
        $this->conversation->update(['status' => 'active']);
        $this->conversation = $this->conversation->fresh();
    }

    public function sendReply(WhatsAppService $whatsApp): void
    {
        $this->validate(['reply' => ['required', 'string', 'max:4096']]);

        $account = $this->conversation->vendor->whatsappAccount;

        if (! $account || ! $account->isActive()) {
            $this->addError('reply', 'No active WhatsApp number is connected for this vendor.');

            return;
        }

        $body = $this->reply;

        try {
            $whatsApp->sendText($account, $this->conversation->customer_phone, $body);
        } catch (\Throwable $e) {
            Log::error('Manual WhatsApp reply failed', ['conversation_id' => $this->conversation->id, 'error' => $e->getMessage()]);
        }

        ConversationMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => ['text' => $body],
            'ai_generated' => false,
        ]);

        $this->conversation->update(['last_message_at' => now()]);
        $this->reset('reply');
    }
};
?>

<div>
    <div class="mb-4 flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold uppercase text-indigo-700">
                {{ mb_substr($conversation->customer_name ?? $conversation->customer_phone, 0, 1) }}
            </div>
            <div>
                <p class="font-medium text-slate-900">{{ $conversation->customer_name ?? $conversation->customer_phone }}</p>
                <p class="text-sm text-slate-500">{{ $conversation->customer_phone }} &middot; {{ $conversation->store?->name ?? 'No store resolved yet' }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            @if ($conversation->status === 'needs_attention')
                <button wire:click="markResolved" wire:loading.attr="disabled" wire:target="markResolved"
                    class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-red-700 disabled:opacity-60">
                    Mark resolved
                </button>
            @endif
            <a href="{{ route('vendor.conversations') }}" wire:navigate class="text-sm font-medium text-indigo-600 hover:text-indigo-500">Back to list</a>
        </div>
    </div>

    @if ($conversation->status === 'needs_attention')
        <x-ui.alert-banner color="red" class="mb-4">
            This conversation was escalated — the AI couldn't resolve the customer's request automatically.
        </x-ui.alert-banner>
    @endif

    @error('reply')
        <x-ui.alert-banner color="red" class="mb-4">{{ $message }}</x-ui.alert-banner>
    @enderror

    <div class="mb-4 space-y-3 overflow-y-auto rounded-xl border border-slate-200 bg-white p-5 shadow-sm max-h-[32rem]">
        @forelse ($messages as $message)
            <div class="flex items-end gap-2 {{ $message->direction === 'outbound' ? 'justify-end' : 'justify-start' }}">
                @if ($message->direction === 'inbound')
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold uppercase text-slate-500">
                        {{ mb_substr($conversation->customer_name ?? $conversation->customer_phone, 0, 1) }}
                    </div>
                @endif
                <div @class([
                    'max-w-md rounded-2xl px-3.5 py-2.5 text-sm shadow-sm',
                    'bg-indigo-600 text-white' => $message->direction === 'outbound',
                    'bg-slate-100 text-slate-900' => $message->direction === 'inbound',
                ])>
                    @if ($message->message_type === 'text')
                        <p>{{ $message->content['text'] ?? $message->content['body'] ?? '' }}</p>
                    @elseif ($message->message_type === 'location')
                        <p>📍 Location shared ({{ $message->content['latitude'] ?? '?' }}, {{ $message->content['longitude'] ?? '?' }})</p>
                    @else
                        <p class="italic text-xs opacity-75">{{ ucfirst($message->message_type) }} message</p>
                    @endif
                    <p @class([
                        'mt-1 text-xs',
                        'text-indigo-200' => $message->direction === 'outbound',
                        'text-slate-400' => $message->direction === 'inbound',
                    ])>
                        {{ $message->created_at->format('M j, g:i A') }}
                        @if ($message->ai_generated) &middot; AI @endif
                    </p>
                </div>
            </div>
        @empty
            <x-ui.empty-state message="No messages yet." icon="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
        @endforelse
    </div>

    <form wire:submit="sendReply" class="flex items-end gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        <x-ui.textarea wire:model="reply" rows="1" placeholder="Type a reply to the customer…" class="flex-1 resize-none" />
        <button type="submit" wire:loading.attr="disabled" wire:target="sendReply"
            class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700 disabled:opacity-60">
            <svg wire:loading wire:target="sendReply" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg wire:loading.remove wire:target="sendReply" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
            </svg>
            Send
        </button>
    </form>
</div>
