<?php

namespace Tests\Feature\Webhook;

use App\Jobs\ProcessInboundWhatsAppMessage;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function signedPost(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, config('whatsapp.app_secret'));

        return $this->call(
            'POST',
            '/webhook/whatsapp',
            [],
            [],
            [],
            ['HTTP_X-Hub-Signature-256' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $body
        );
    }

    private function samplePayload(string $messageId = 'wamid.TEST123'): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '1203319272865547'],
                        'messages' => [[
                            'id' => $messageId,
                            'from' => '923001234567',
                            'type' => 'text',
                            'text' => ['body' => 'Hello'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    public function test_verification_handshake_echoes_challenge_on_valid_token(): void
    {
        $response = $this->get('/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=12345');

        $response->assertOk();
        $response->assertSeeText('12345');
    }

    public function test_verification_handshake_rejects_invalid_token(): void
    {
        $response = $this->get('/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=wrong-token&hub.challenge=12345');

        $response->assertForbidden();
    }

    public function test_valid_signature_is_accepted_and_logged(): void
    {
        Queue::fake();

        $response = $this->signedPost($this->samplePayload());

        $response->assertOk();
        $this->assertDatabaseHas('webhook_logs', [
            'wa_message_id' => 'wamid.TEST123',
            'processing_status' => 'received',
        ]);
    }

    public function test_valid_message_dispatches_processing_job(): void
    {
        Queue::fake();

        $this->signedPost($this->samplePayload('wamid.DISPATCH1'));

        Queue::assertPushed(ProcessInboundWhatsAppMessage::class, function ($job) {
            $log = WebhookLog::find($job->webhookLogId);

            return $log && $log->wa_message_id === 'wamid.DISPATCH1';
        });
    }

    public function test_duplicate_delivery_does_not_dispatch_a_second_job(): void
    {
        Queue::fake();

        $payload = $this->samplePayload('wamid.DISPATCH_DUP');
        $this->signedPost($payload);
        $this->signedPost($payload);

        Queue::assertPushed(ProcessInboundWhatsAppMessage::class, 1);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $response = $this->postJson('/webhook/whatsapp', $this->samplePayload());

        $response->assertForbidden();
        $this->assertDatabaseCount('webhook_logs', 0);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $body = json_encode($this->samplePayload());

        $response = $this->call(
            'POST',
            '/webhook/whatsapp',
            [],
            [],
            [],
            ['HTTP_X-Hub-Signature-256' => 'sha256=deadbeef', 'CONTENT_TYPE' => 'application/json'],
            $body
        );

        $response->assertForbidden();
        $this->assertDatabaseCount('webhook_logs', 0);
    }

    public function test_duplicate_message_id_is_logged_but_not_reprocessed(): void
    {
        Queue::fake();

        $payload = $this->samplePayload('wamid.DUPLICATE');

        $this->signedPost($payload)->assertOk();
        $this->signedPost($payload)->assertOk();

        $this->assertDatabaseCount('webhook_logs', 2);
        $this->assertDatabaseHas('webhook_logs', [
            'wa_message_id' => 'wamid.DUPLICATE',
            'processing_status' => 'ignored_duplicate',
        ]);
        $this->assertSame(
            1,
            WebhookLog::where('wa_message_id', 'wamid.DUPLICATE')->where('processing_status', 'received')->count()
        );
    }

    public function test_missing_app_secret_config_fails_closed_not_open(): void
    {
        config(['whatsapp.app_secret' => '']);

        $body = json_encode($this->samplePayload());

        $response = $this->call(
            'POST',
            '/webhook/whatsapp',
            [],
            [],
            [],
            ['HTTP_X-Hub-Signature-256' => 'sha256=anything', 'CONTENT_TYPE' => 'application/json'],
            $body
        );

        // Must NOT succeed just because the secret is blank — that would let
        // an unsigned/any-signature request through if misconfigured.
        $response->assertStatus(500);
        $this->assertDatabaseCount('webhook_logs', 0);
    }
}
