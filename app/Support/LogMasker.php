<?php

namespace App\Support;

/**
 * PLAN.md §15 Security — "mask sensitive tokens in logs". Recursively
 * redacts known-sensitive keys from any array before it's persisted to
 * webhook_logs/ai_routing_logs or written to the application log.
 */
class LogMasker
{
    /**
     * Case-insensitive key names that get fully redacted wherever found,
     * at any nesting depth.
     */
    private const SENSITIVE_KEYS = [
        'access_token', 'system_user_token', 'api_key', 'x-api-key',
        'authorization', 'app_secret', 'password', 'password_confirmation',
        'token', 'secret', 'client_secret', 'private_key', 'card_number',
        'cvv', 'integrity_salt', 'hash_key',
    ];

    public static function mask(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::mask($value);

                continue;
            }

            $result[$key] = self::isSensitiveKey((string) $key) ? '***REDACTED***' : $value;
        }

        return $result;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
