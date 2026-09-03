<?php

namespace App\Console\Commands;

use App\Models\WhatsappAccount;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;

class WhatsAppSendTest extends Command
{
    protected $signature = 'whatsapp:send-test
        {to}
        {message=Test message from AI Sales Agent}
        {--account=1}
        {--template= : Send an approved template instead of plain text (required if the customer hasn\'t messaged you in the last 24h)}
        {--lang=en_US : Template language code}';

    protected $description = 'Send a real WhatsApp message via the Cloud API for manual testing';

    public function handle(WhatsAppService $whatsapp): int
    {
        $account = WhatsappAccount::findOrFail($this->option('account'));
        $to = $this->argument('to');

        if ($templateName = $this->option('template')) {
            $this->line("Sending template [{$templateName}] to {$to}...");
            $response = $whatsapp->sendTemplate($account, $to, $templateName, $this->option('lang'));
        } else {
            $this->line("Sending plain text to {$to}. Note: this only works if they messaged you within the last 24h — use --template otherwise.");
            $response = $whatsapp->sendText($account, $to, $this->argument('message'));
        }

        $this->line('Status: '.$response->status());
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT));

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
