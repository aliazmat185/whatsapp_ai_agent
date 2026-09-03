<?php

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\Vendor;
use App\Services\Ai\AgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activating_an_agent_deactivates_others_for_same_vendor(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $agentA = Agent::factory()->for($vendor)->active()->create();
        $agentB = Agent::factory()->for($vendor)->create();

        $activated = (new AgentService())->activate($agentB);

        $this->assertTrue($activated->is_active);
        $this->assertFalse($agentA->fresh()->is_active);
    }

    public function test_activating_does_not_affect_other_vendors_agents(): void
    {
        $vendorA = Vendor::factory()->approved()->create();
        $vendorB = Vendor::factory()->approved()->create();
        $agentA = Agent::factory()->for($vendorA)->active()->create();
        $agentB = Agent::factory()->for($vendorB)->create();

        (new AgentService())->activate($agentB);

        $this->assertTrue($agentA->fresh()->is_active);
    }

    public function test_deactivate_turns_off_active_agent(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $agent = Agent::factory()->for($vendor)->active()->create();

        $deactivated = (new AgentService())->deactivate($agent);

        $this->assertFalse($deactivated->is_active);
    }
}
