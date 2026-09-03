<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Product;
use App\Services\WhatsApp\WhatsAppFlowCrypto;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Encrypted data-exchange endpoint for the multi-select product-picker
 * WhatsApp Flow. Meta calls this directly (not through the normal webhook)
 * whenever the Flow needs data — opening the screen (INIT) or the
 * screen's ping health-check before allowing the Flow to publish. The
 * Flow's final *submission* is delivered separately, as a normal inbound
 * webhook message (see WhatsAppPayloadParser's nfm_reply handling).
 */
class WhatsAppFlowController extends Controller
{
    /** Flow CheckboxGroup can comfortably scroll further than a List message's 10-row cap. */
    private const MAX_FLOW_ITEMS = 20;

    public function __construct(private WhatsAppFlowCrypto $crypto) {}

    public function handle(Request $request): Response
    {
        $body = $request->json()->all();

        if (! isset($body['encrypted_flow_data'], $body['encrypted_aes_key'], $body['initial_vector'])) {
            abort(400, 'Malformed Flow data-exchange request.');
        }

        try {
            $decrypted = $this->crypto->decryptRequest(
                $body['encrypted_flow_data'],
                $body['encrypted_aes_key'],
                $body['initial_vector']
            );
        } catch (Throwable $e) {
            Log::error('WhatsApp Flow request decryption failed', ['error' => $e->getMessage()]);
            abort(421, 'Decryption failed.');
        }

        [$aesKey, $iv] = [$decrypted['aesKey'], $decrypted['iv']];
        $action = $decrypted['data']['action'] ?? null;

        $response = match ($action) {
            'ping' => ['data' => ['status' => 'active']],
            'INIT', 'data_exchange' => $this->screenData($decrypted['data']),
            default => ['data' => ['acknowledged' => true]],
        };

        $encrypted = $this->crypto->encryptResponse($response, $aesKey, $iv);

        return response($encrypted, 200)->header('Content-Type', 'text/plain');
    }

    private function screenData(array $requestData): array
    {
        $flowToken = $requestData['flow_token'] ?? null;

        try {
            $context = json_decode(Crypt::decryptString((string) $flowToken), true);
        } catch (Throwable) {
            return ['data' => ['error_message' => 'This menu has expired — please ask for the menu again.']];
        }

        $conversation = Conversation::find($context['conversation_id'] ?? null);

        if (! $conversation || ! $conversation->store_id) {
            return ['data' => ['error_message' => 'This menu has expired — please ask for the menu again.']];
        }

        $categoryId = $context['category_id'] ?? null;
        $currency = $conversation->vendor->default_currency;

        $products = Product::withoutGlobalScope('vendor')
            ->where('store_id', $conversation->store_id)
            ->where('category_id', $categoryId)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(self::MAX_FLOW_ITEMS)
            ->get();

        $rows = $products->map(fn (Product $product) => [
            'id' => "product:{$product->id}",
            'title' => Str::limit($product->name, 30, ''),
            'description' => Str::limit("{$currency} ".number_format((float) $product->base_price, 2), 80, ''),
        ])->all();

        return [
            'screen' => 'PRODUCT_SELECT',
            'data' => [
                'products' => $rows,
                'category_id' => (string) $categoryId,
                'flow_token' => $flowToken,
            ],
        ];
    }
}
