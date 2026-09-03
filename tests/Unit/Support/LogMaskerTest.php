<?php

namespace Tests\Unit\Support;

use App\Support\LogMasker;
use PHPUnit\Framework\TestCase;

class LogMaskerTest extends TestCase
{
    public function test_redacts_known_sensitive_keys(): void
    {
        $masked = LogMasker::mask([
            'access_token' => 'secret-value',
            'name' => 'Ali',
        ]);

        $this->assertSame('***REDACTED***', $masked['access_token']);
        $this->assertSame('Ali', $masked['name']);
    }

    public function test_redacts_nested_sensitive_keys(): void
    {
        $masked = LogMasker::mask([
            'response' => [
                'error' => ['message' => 'failed'],
                'app_secret' => 'abc123',
            ],
        ]);

        $this->assertSame('***REDACTED***', $masked['response']['app_secret']);
        $this->assertSame('failed', $masked['response']['error']['message']);
    }

    public function test_case_insensitive_matching(): void
    {
        $masked = LogMasker::mask(['Access_Token' => 'abc', 'AUTHORIZATION' => 'Bearer xyz']);

        $this->assertSame('***REDACTED***', $masked['Access_Token']);
        $this->assertSame('***REDACTED***', $masked['AUTHORIZATION']);
    }

    public function test_partial_key_match_redacts(): void
    {
        // "jazzcash_integrity_salt" contains "integrity_salt" substring.
        $masked = LogMasker::mask(['jazzcash_integrity_salt' => 'salty']);

        $this->assertSame('***REDACTED***', $masked['jazzcash_integrity_salt']);
    }

    public function test_normal_whatsapp_message_fields_survive_untouched(): void
    {
        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '123'],
                        'messages' => [[
                            'id' => 'wamid.ABC',
                            'from' => '923001234567',
                            'type' => 'text',
                            'text' => ['body' => 'Hello, is this in stock?'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $masked = LogMasker::mask($payload);

        $this->assertSame($payload, $masked);
    }

    public function test_does_not_mutate_non_sensitive_arrays(): void
    {
        $data = ['a' => 1, 'b' => ['c' => 2]];

        $this->assertSame($data, LogMasker::mask($data));
    }
}
