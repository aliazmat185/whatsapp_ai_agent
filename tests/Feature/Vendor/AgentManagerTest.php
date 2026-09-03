<?php

namespace Tests\Feature\Vendor;

use App\Models\Agent;
use App\Models\Vendor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function approvedVendorOwner(): Vendor
    {
        $vendor = Vendor::factory()->approved()->create();
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);

        return $vendor;
    }

    public function test_vendor_can_create_an_agent(): void
    {
        $vendor = $this->approvedVendorOwner();

        Livewire::actingAs($vendor->owner)
            ->test('vendor.agent-manager')
            ->set('name', 'Sana')
            ->set('persona', 'Warm and casual.')
            ->set('sales_goal', 'upsell')
            ->set('language', 'Urdu')
            ->call('save');

        $this->assertDatabaseHas('agents', [
            'vendor_id' => $vendor->id,
            'name' => 'Sana',
            'sales_goal' => 'upsell',
            'language' => 'Urdu',
        ]);
    }

    public function test_activating_an_agent_deactivates_the_previously_active_one(): void
    {
        $vendor = $this->approvedVendorOwner();
        $agentA = Agent::factory()->for($vendor)->active()->create();
        $agentB = Agent::factory()->for($vendor)->create();

        Livewire::actingAs($vendor->owner)
            ->test('vendor.agent-manager')
            ->call('activate', $agentB->id);

        $this->assertTrue($agentB->fresh()->is_active);
        $this->assertFalse($agentA->fresh()->is_active);
    }

    public function test_vendor_sees_only_own_agents(): void
    {
        $vendorA = $this->approvedVendorOwner();
        $vendorB = $this->approvedVendorOwner();
        Agent::factory()->for($vendorA)->create(['name' => 'Agent A']);
        Agent::factory()->for($vendorB)->create(['name' => 'Agent B']);

        $component = Livewire::actingAs($vendorA->owner)->test('vendor.agent-manager');

        $this->assertStringContainsString('Agent A', $component->html());
        $this->assertStringNotContainsString('Agent B', $component->html());
    }

    public function test_vendor_can_delete_an_agent(): void
    {
        $vendor = $this->approvedVendorOwner();
        $agent = Agent::factory()->for($vendor)->create();

        Livewire::actingAs($vendor->owner)
            ->test('vendor.agent-manager')
            ->call('delete', $agent->id);

        $this->assertDatabaseMissing('agents', ['id' => $agent->id]);
    }
}
