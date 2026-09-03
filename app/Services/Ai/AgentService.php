<?php

namespace App\Services\Ai;

use App\Models\Agent;
use Illuminate\Support\Facades\DB;

/**
 * Exactly one agent per vendor may be active at a time — that's the one
 * ClaudeAgentService uses, since a vendor only has one bound WhatsApp
 * number (whatsapp_accounts.vendor_id is unique).
 */
class AgentService
{
    public function activate(Agent $agent): Agent
    {
        DB::transaction(function () use ($agent) {
            Agent::withoutGlobalScope('vendor')
                ->where('vendor_id', $agent->vendor_id)
                ->where('id', '!=', $agent->id)
                ->update(['is_active' => false]);

            $agent->update(['is_active' => true]);
        });

        return $agent->fresh();
    }

    public function deactivate(Agent $agent): Agent
    {
        $agent->update(['is_active' => false]);

        return $agent->fresh();
    }
}
