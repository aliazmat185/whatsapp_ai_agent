<?php
namespace Tests\Feature\Vendor;
use App\Models\Vendor;
use App\Models\ProductCategory;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_edit_renames_category(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);

        $category = $vendor->categories()->create(['name' => 'Old Name', 'slug' => 'old-name-abc']);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.category-manager')
            ->call('edit', $category->id)
            ->assertSet('name', 'Old Name')
            ->assertSet('editingId', $category->id)
            ->set('name', 'New Name')
            ->call('save');

        $this->assertSame('New Name', $category->fresh()->name);
        $this->assertSame(1, ProductCategory::where('vendor_id', $vendor->id)->count());
    }

    public function test_edit_does_not_touch_slug(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);

        $category = $vendor->categories()->create(['name' => 'Old', 'slug' => 'fixed-slug']);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.category-manager')
            ->call('edit', $category->id)
            ->set('name', 'Renamed')
            ->call('save');

        $this->assertSame('fixed-slug', $category->fresh()->slug);
    }

    public function test_vendor_cannot_edit_another_vendors_category(): void
    {
        $vendorA = Vendor::factory()->approved()->create();
        $vendorA->owner->assignRole('vendor_owner');
        $vendorA->owner->update(['vendor_id' => $vendorA->id]);

        $vendorB = Vendor::factory()->approved()->create();
        $categoryB = $vendorB->categories()->create(['name' => 'B Cat', 'slug' => 'b-cat']);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($vendorA->owner)
            ->test('vendor.category-manager')
            ->call('edit', $categoryB->id);
    }
}
