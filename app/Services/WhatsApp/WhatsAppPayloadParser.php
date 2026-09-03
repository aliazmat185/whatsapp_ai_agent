<?php

namespace App\Services\WhatsApp;

/**
 * Normalizes Meta's webhook JSON shape into a flat array the rest of the
 * app can work with, without every caller needing to know Meta's nesting.
 * See PLAN.md §5 Webhook Handling.
 */
class WhatsAppPayloadParser
{
    public function phoneNumberId(array $payload): ?string
    {
        return data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id');
    }

    public function messageId(array $payload): ?string
    {
        return data_get($payload, 'entry.0.changes.0.value.messages.0.id');
    }

    public function hasMessage(array $payload): bool
    {
        return data_get($payload, 'entry.0.changes.0.value.messages.0') !== null;
    }

    /**
     * Returns a normalized message array, or null if the payload has no
     * message (e.g. a status/delivery-receipt callback instead).
     *
     * @return array{
     *     wa_message_id: string,
     *     from: string,
     *     type: string,
     *     text: string|null,
     *     latitude: float|null,
     *     longitude: float|null,
     *     interactive_reply_id: string|null,
     *     flow_response: array|null,
     * }|null
     */
    public function parseMessage(array $payload): ?array
    {
        $message = data_get($payload, 'entry.0.changes.0.value.messages.0');

        if (! $message) {
            return null;
        }

        $type = $message['type'] ?? 'text';

        return [
            'wa_message_id' => $message['id'],
            'from' => $message['from'],
            'type' => $type,
            'text' => $this->extractText($message, $type),
            'latitude' => data_get($message, 'location.latitude'),
            'longitude' => data_get($message, 'location.longitude'),
            'interactive_reply_id' => $this->extractInteractiveReplyId($message, $type),
            'flow_response' => $this->extractFlowResponse($message, $type),
        ];
    }

    public function senderProfileName(array $payload): ?string
    {
        return data_get($payload, 'entry.0.changes.0.value.contacts.0.profile.name');
    }

    private function extractText(array $message, string $type): ?string
    {
        return match ($type) {
            'text' => data_get($message, 'text.body'),
            'button' => data_get($message, 'button.text'),
            default => null,
        };
    }

    private function extractInteractiveReplyId(array $message, string $type): ?string
    {
        if ($type !== 'interactive') {
            return null;
        }

        return data_get($message, 'interactive.button_reply.id')
            ?? data_get($message, 'interactive.list_reply.id');
    }

    /**
     * A completed WhatsApp Flow submission arrives as a normal inbound
     * message with interactive.type === 'nfm_reply' — its response_json is
     * a JSON *string* (not a nested object) containing whatever fields the
     * Flow's "complete" action payload defined.
     */
    private function extractFlowResponse(array $message, string $type): ?array
    {
        if ($type !== 'interactive' || data_get($message, 'interactive.type') !== 'nfm_reply') {
            return null;
        }

        $responseJson = data_get($message, 'interactive.nfm_reply.response_json');

        if (! $responseJson) {
            return null;
        }

        $decoded = json_decode($responseJson, true);

        return is_array($decoded) ? $decoded : null;
    }
}
