<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundWhatsAppMessage;
use App\Models\WebhookLog;
use App\Support\LogMasker;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PLAN.md §5 Webhook Handling.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * Meta's webhook verification handshake (GET). Echoes hub.challenge
     * back only if the verify token matches our configured value.
     */
    public function  verify(Request $request): Response
    {
        // Meta sends these as hub.mode/hub.verify_token/hub.challenge in the
        // URL, but PHP's query-string parser converts dots to underscores
        // in $_GET (dots aren't valid in PHP variable names), so they arrive
        // here as hub_mode/hub_verify_token/hub_challenge.
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && hash_equals((string) config('whatsapp.webhook_verify_token'), (string) $token)) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    /**
     * Inbound message/event delivery (POST). Signature already verified by
     * the whatsapp.signature middleware before this runs. This handler only
     * logs + dedupes + enqueues — all parsing/DB writes happen async in
     * ProcessInboundWhatsAppMessage so Meta gets a fast 200 regardless of
     * downstream processing time.
     */
    public function receive(Request $request): Response
    {
        $payload = $request->json()->all();
        $waMessageId = $this->extractMessageId($payload);

        if ($waMessageId && $this->isDuplicate($waMessageId)) {
            WebhookLog::create([
                'source' => 'whatsapp',
                'wa_message_id' => $waMessageId,
                'raw_payload' => LogMasker::mask($payload),
                'processing_status' => 'ignored_duplicate',
                'received_at' => now(),
            ]);

            return response()->json(['status' => 'duplicate_ignored']);
        }

        $log = WebhookLog::create([
            'source' => 'whatsapp',
            'wa_message_id' => $waMessageId,
            'raw_payload' => LogMasker::mask($payload),
            'processing_status' => 'received',
            'received_at' => now(),
        ]);

        ProcessInboundWhatsAppMessage::dispatch($log->id);

        return response()->json(['status' => 'received']);
    }

    private function extractMessageId(array $payload): ?string
    {
        return data_get($payload, 'entry.0.changes.0.value.messages.0.id');
    }

    private function isDuplicate(string $waMessageId): bool
    {
        return WebhookLog::where('wa_message_id', $waMessageId)
            ->where('processing_status', '!=', 'failed')
            ->exists();
    }
}
