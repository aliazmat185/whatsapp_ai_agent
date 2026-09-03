<?php

namespace Tests\Unit\Services;

use App\Services\Ai\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolRegistrySchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Anthropic's API rejects any input_schema.properties that serializes
     * as a JSON array ([]) instead of an object ({}) — this happens
     * whenever a tool has no parameters and uses a plain empty PHP array.
     * Caught via a real API call during Phase 5 verification; this test
     * guards against reintroducing it.
     */
    public function test_every_tool_schema_properties_serializes_as_json_object(): void
    {
        $registry = app(ToolRegistry::class);

        foreach ($registry->schemas() as $schema) {
            $encoded = json_encode($schema['input_schema']);
            $decoded = json_decode($encoded);

            $this->assertIsObject(
                $decoded->properties,
                "Tool '{$schema['name']}' input_schema.properties must encode as a JSON object, not an array."
            );
        }
    }
}
