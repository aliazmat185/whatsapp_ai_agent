<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * One-time (or re-run-on-change) setup for the multi-select product-picker
 * WhatsApp Flow: uploads the RSA public key, creates the Flow, uploads its
 * JSON definition, points it at our data-exchange endpoint, and publishes
 * it. Requires `whatsapp:flow:generate-keys` to have been run first, and a
 * publicly reachable HTTPS endpoint (e.g. the ngrok tunnel) at the time of
 * publishing — Meta pings the endpoint synchronously before allowing it.
 */
class WhatsAppFlowRegister extends Command
{
    protected $signature = 'whatsapp:flow:register
        {base_url : Public HTTPS base URL Meta can reach right now, e.g. https://your-tunnel.ngrok-free.app}
        {--phone= : phone_number_id to register the encryption key against (defaults to WHATSAPP_DEV_PHONE_NUMBER_ID)}
        {--flow-id= : Resume an already-created Flow (skips key upload + creation, jumps to steps 3-5) instead of creating a new one}';

    protected $description = 'Register the RSA public key and create/publish the multi-select product Flow with Meta';

    public function handle(): int
    {
        $phoneNumberId = $this->option('phone') ?: config('whatsapp.dev_phone_number_id');
        $resumeFlowId = $this->option('flow-id');

        if (! $phoneNumberId) {
            $this->error('No phone_number_id available — pass --phone= or set WHATSAPP_DEV_PHONE_NUMBER_ID.');

            return self::FAILURE;
        }

        if (! Storage::disk('local')->exists(config('whatsapp.flow_private_key_path'))) {
            $this->error('No keypair found — run `php artisan whatsapp:flow:generate-keys` first.');

            return self::FAILURE;
        }

        $privateKey = openssl_pkey_get_private(
            Storage::disk('local')->get(config('whatsapp.flow_private_key_path')),
            config('whatsapp.flow_private_key_passphrase')
        );

        if (! $privateKey) {
            $this->error('Could not load the stored private key — check WHATSAPP_FLOW_PRIVATE_KEY_PASSPHRASE.');

            return self::FAILURE;
        }

        if ($resumeFlowId) {
            $flowId = $resumeFlowId;
            $this->line("Resuming existing Flow {$flowId} — skipping key upload and creation.");
        } else {
            $publicKeyPem = openssl_pkey_get_details($privateKey)['key'];

            $this->line('1/5 Uploading RSA public key to Meta...');
            $response = $this->graph()->asForm()->post("/{$phoneNumberId}/whatsapp_business_encryption", [
                'business_public_key' => $publicKeyPem,
            ]);

            if (! $response->successful()) {
                $this->error('Public key upload failed: '.$response->body());

                return self::FAILURE;
            }

            $this->line('2/5 Creating the Flow...');
            $response = $this->graph()->post("/{$this->wabaId()}/flows", [
                'name' => 'Product Picker '.now()->format('Y-m-d H:i'),
                'categories' => ['OTHER'],
            ]);

            if (! $response->successful()) {
                $this->error('Flow creation failed: '.$response->body());

                return self::FAILURE;
            }

            $flowId = $response->json('id');
            $this->line("   Flow ID: {$flowId}");
        }

        $this->line('3/5 Uploading Flow JSON definition...');
        $flowJson = file_get_contents(resource_path('whatsapp-flows/product-select.json'));
        $response = $this->graph()->attach('file', $flowJson, 'flow.json', ['Content-Type' => 'application/json'])
            ->post("/{$flowId}/assets", [
                'name' => 'flow.json',
                'asset_type' => 'FLOW_JSON',
            ]);

        if (! $response->successful()) {
            $this->error('Flow JSON upload failed: '.$response->body());

            return self::FAILURE;
        }

        $endpointUri = rtrim($this->normalizedBaseUrl(), '/').'/webhook/whatsapp-flow';
        $this->line("4/5 Setting endpoint_uri to {$endpointUri}...");
        $response = $this->graph()->post("/{$flowId}", [
            'endpoint_uri' => $endpointUri,
        ]);

        if (! $response->successful()) {
            $this->error('Setting endpoint_uri failed: '.$response->body());

            return self::FAILURE;
        }

        $this->line('5/5 Publishing (Meta will ping the endpoint now — make sure the queue worker and web server are up)...');
        $response = $this->graph()->post("/{$flowId}/publish");

        if (! $response->successful()) {
            $this->error('Publish failed: '.$response->body());
            $this->warn("The Flow was created (ID {$flowId}) but is not published yet. Fix the issue above and re-run: php artisan whatsapp:flow:register {$this->argument('base_url')} --flow-id={$flowId}");

            return self::FAILURE;
        }

        $this->info('Flow published successfully.');
        $this->newLine();
        $this->line('Add this to your .env:');
        $this->line("WHATSAPP_FLOW_ID={$flowId}");

        return self::SUCCESS;
    }

    private function normalizedBaseUrl(): string
    {
        $url = $this->argument('base_url');

        return preg_match('#^https?://#i', $url) ? $url : "https://{$url}";
    }

    private function graph()
    {
        $baseUrl = config('whatsapp.graph_base_url');
        $apiVersion = config('whatsapp.api_version');

        return Http::withToken(config('whatsapp.system_user_token'))
            ->baseUrl("{$baseUrl}/{$apiVersion}");
    }

    private function wabaId(): string
    {
        return (string) config('whatsapp.waba_id');
    }
}
