<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use App\Models\ConversationState;
use App\Services\Store\StoreLocatorService;

/**
 * Owns every conversation_states.current_step transition. Pure PHP,
 * deterministic, no LLM call — Claude informs intent, this decides state.
 * See PLAN.md §6.1.
 */
class ConversationStateMachine
{
    public function __construct(
        private StoreLocatorService $storeLocator,
    ) {}

    public function stateFor(Conversation $conversation): ConversationState
    {
        return ConversationState::firstOrCreate(
            ['conversation_id' => $conversation->id],
            ['current_step' => 'greeting']
        );
    }

    /**
     * Resolves conversation.store_id if possible, and advances state to
     * awaiting_location or browsing accordingly. Returns true if the
     * conversation is ready to proceed with product browsing/search; false
     * if it's now blocked waiting on the customer to share their location.
     */
    public function resolveStoreOrRequestLocation(Conversation $conversation): bool
    {
        if ($conversation->store_id !== null) {
            $this->advanceTo($conversation, 'browsing');

            return true;
        }

        $store = $this->storeLocator->resolveStoreForCustomer(
            $conversation->vendor_id,
            $conversation->customer_lat !== null ? (float) $conversation->customer_lat : null,
            $conversation->customer_lng !== null ? (float) $conversation->customer_lng : null,
        );

        if ($store) {
            $conversation->update(['store_id' => $store->id]);
            $this->advanceTo($conversation, 'browsing');

            return true;
        }

        // Multi-store vendor, no location yet — block until customer shares it.
        $this->advanceTo($conversation, 'awaiting_location');

        return false;
    }

    public function advanceTo(Conversation $conversation, string $step): void
    {
        $state = $this->stateFor($conversation);
        $state->update(['current_step' => $step]);
    }

    public function updateContext(Conversation $conversation, array $merge): void
    {
        $state = $this->stateFor($conversation);
        $state->update(['context' => array_merge($state->context ?? [], $merge)]);
    }

    public function escalate(Conversation $conversation): void
    {
        $this->advanceTo($conversation, 'support_escalation');
        $conversation->update(['status' => 'needs_attention']);
    }
}
