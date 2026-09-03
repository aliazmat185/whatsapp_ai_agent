<?php

namespace Database\Factories;

use App\Models\WebhookLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookLog>
 */
class WebhookLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source' => 'whatsapp',
            'wa_message_id' => 'wamid.'.fake()->unique()->uuid(),
            'raw_payload' => ['test' => true],
            'processing_status' => 'processed',
            'received_at' => now(),
        ];
    }
}
