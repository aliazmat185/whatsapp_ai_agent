<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Implements Meta's WhatsApp Flow data-exchange encryption protocol:
 * RSA-OAEP(SHA-256) to unwrap a per-request AES-128-GCM key, then
 * AES-128-GCM for the actual request/response body. See
 * https://developers.facebook.com/docs/whatsapp/flows/reference/flowsdatarequest
 *
 * The response must be re-encrypted with the SAME AES key but a
 * bit-flipped IV — this is a Meta-specific requirement, not a general
 * AES-GCM convention.
 */
class WhatsAppFlowCrypto
{
    private const TAG_LENGTH = 16;

    /**
     * @return array{data: array, aesKey: string, iv: string} decrypted request
     *     body, plus the raw AES key/IV needed to encrypt the response.
     */
    public function decryptRequest(string $encryptedFlowData, string $encryptedAesKey, string $initialVector): array
    {
        $privateKey = openssl_pkey_get_private(
            $this->privateKeyPem(),
            config('whatsapp.flow_private_key_passphrase')
        );

        if (! $privateKey) {
            throw new RuntimeException('Unable to load WhatsApp Flow private key: '.openssl_error_string());
        }

        $aesKey = '';
        $decrypted = openssl_private_decrypt(
            base64_decode($encryptedAesKey),
            $aesKey,
            $privateKey,
            OPENSSL_PKCS1_OAEP_PADDING
        );

        if (! $decrypted) {
            throw new RuntimeException('Unable to decrypt Flow AES key: '.openssl_error_string());
        }

        $iv = base64_decode($initialVector);
        $buffer = base64_decode($encryptedFlowData);
        $ciphertext = substr($buffer, 0, -self::TAG_LENGTH);
        $tag = substr($buffer, -self::TAG_LENGTH);

        $json = openssl_decrypt($ciphertext, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);

        if ($json === false) {
            throw new RuntimeException('Unable to decrypt Flow request body: '.openssl_error_string());
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new RuntimeException('Decrypted Flow request body is not valid JSON.');
        }

        return ['data' => $data, 'aesKey' => $aesKey, 'iv' => $iv];
    }

    /**
     * Encrypts a response with the request's AES key and a bit-flipped IV,
     * returning the raw base64 string Meta expects as the entire HTTP body.
     */
    public function encryptResponse(array $responseData, string $aesKey, string $iv): string
    {
        $flippedIv = $this->flipBits($iv);
        $tag = '';

        $ciphertext = openssl_encrypt(
            json_encode($responseData),
            'aes-128-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $flippedIv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt Flow response body: '.openssl_error_string());
        }

        return base64_encode($ciphertext.$tag);
    }

    private function flipBits(string $iv): string
    {
        $flipped = '';

        foreach (str_split($iv) as $byte) {
            $flipped .= chr(~ord($byte) & 0xFF);
        }

        return $flipped;
    }

    private function privateKeyPem(): string
    {
        $path = config('whatsapp.flow_private_key_path');

        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('WhatsApp Flow private key not found — run `php artisan whatsapp:flow:generate-keys` first.');
        }

        return Storage::disk('local')->get($path);
    }
}
