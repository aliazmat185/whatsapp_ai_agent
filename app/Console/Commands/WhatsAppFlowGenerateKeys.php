<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class WhatsAppFlowGenerateKeys extends Command
{
    protected $signature = 'whatsapp:flow:generate-keys {--force : Overwrite an existing keypair}';

    protected $description = 'Generate the RSA keypair used to encrypt/decrypt WhatsApp Flow data-exchange requests';

    private const KEY_PATH = 'private/whatsapp-flow/private.pem';

    public function handle(): int
    {
        if (Storage::disk('local')->exists(self::KEY_PATH) && ! $this->option('force')) {
            $this->error('A keypair already exists at storage/app/'.self::KEY_PATH.'. Pass --force to overwrite (this will invalidate any published Flow until re-registered).');

            return self::FAILURE;
        }

        $passphrase = $this->generatePassphrase();

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $resource) {
            $this->error('openssl_pkey_new failed: '.openssl_error_string());

            return self::FAILURE;
        }

        openssl_pkey_export($resource, $privateKeyPem, $passphrase);
        $details = openssl_pkey_get_details($resource);
        $publicKeyPem = $details['key'];

        Storage::disk('local')->put(self::KEY_PATH, $privateKeyPem);

        $this->info('Private key written to storage/app/'.self::KEY_PATH);
        $this->newLine();
        $this->line('Add these to your .env:');
        $this->line('WHATSAPP_FLOW_PRIVATE_KEY_PASSPHRASE='.$passphrase);
        $this->newLine();
        $this->line('Public key (will be uploaded automatically by whatsapp:flow:register):');
        $this->line($publicKeyPem);

        return self::SUCCESS;
    }

    private function generatePassphrase(): string
    {
        return bin2hex(random_bytes(32));
    }
}
