<?php

namespace App\Services\Ai;

use App\AI\Retrieval\KnowledgeRetriever;
use App\AI\Retrieval\ProductRetriever;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Catalog\ProductSearchService;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\DeliveryAvailabilityService;
use App\Services\Payment\PaymentService;
use App\Services\Store\StoreLocatorService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Defines the tools Claude may call (PLAN.md §6.1) and executes them.
 * Every tool implementation hard-filters by the conversation's own
 * vendor_id — Claude cannot be prompt-injected into cross-vendor access
 * since these checks don't depend on anything Claude says (PLAN.md §6.3).
 *
 * Claude never computes prices/totals itself and never marks an order paid
 * — add_to_cart/start_checkout always go through CartService/CheckoutService,
 * which re-fetch real prices/stock from the DB (PLAN.md §6.3 guardrails).
 */
class ToolRegistry
{
    public function __construct(
        private ProductSearchService $productSearch,
        private StoreLocatorService $storeLocator,
        private ConversationStateMachine $stateMachine,
        private CartService $cartService,
        private CheckoutService $checkoutService,
        private KnowledgeRetriever $knowledgeRetriever,
        private ProductRetriever $productRetriever,
        private WhatsAppService $whatsApp,
        private DeliveryAvailabilityService $deliveryAvailability,
        private PaymentService $paymentService,
    ) {}

    /**
     * Tools withheld from a faq_only agent — no sense exposing checkout
     * tools to an agent whose sales goal is answering questions only.
     */
    private const FAQ_ONLY_EXCLUDED_TOOLS = [
        'add_to_cart', 'get_cart', 'start_checkout',
        'remove_from_cart', 'update_cart_item', 'empty_cart',
    ];

    /** WhatsApp interactive list messages cap at 10 rows total. */
    private const MAX_LIST_ROWS = 10;

    /**
     * @return array<int, array{name: string, description: string, input_schema: array}>
     */
    public function schemas(?string $salesGoal = null): array
    {
        $schemas = [
            [
                'name' => 'search_products',
                'description' => 'Search the customer\'s resolved store for products by keyword, category, or price range.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Keyword to search product name/description.'],
                        'price_min' => ['type' => 'number'],
                        'price_max' => ['type' => 'number'],
                    ],
                ],
            ],
            [
                'name' => 'get_store_candidates',
                'description' => 'List the vendor\'s nearest stores to the customer\'s shared location, ranked by distance.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max number of stores to return, default 3.'],
                    ],
                ],
            ],
            [
                'name' => 'request_location',
                'description' => 'Signal that the customer should be asked to share their location before continuing.',
                // properties must serialize as a JSON object ({}), not an array ([]) —
                // an empty PHP array json_encodes as [], which Anthropic's API rejects.
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'add_to_cart',
                'description' => 'Add a product to the customer\'s cart. Creates the cart if one doesn\'t exist yet.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                        'quantity' => ['type' => 'integer', 'description' => 'Defaults to 1.'],
                        'variant_id' => ['type' => 'integer'],
                    ],
                    'required' => ['product_id'],
                ],
            ],
            [
                'name' => 'get_cart',
                'description' => 'Get the customer\'s current cart contents and total.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'update_cart_item',
                'description' => 'Change the quantity of a product already in the customer\'s cart. Setting quantity to 0 removes it.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                        'quantity' => ['type' => 'integer'],
                    ],
                    'required' => ['product_id', 'quantity'],
                ],
            ],
            [
                'name' => 'remove_from_cart',
                'description' => 'Remove a product entirely from the customer\'s cart (e.g. "remove this", "delete that item").',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                    ],
                    'required' => ['product_id'],
                ],
            ],
            [
                'name' => 'empty_cart',
                'description' => 'Clear every item out of the customer\'s cart (e.g. "empty my cart", "start a new order", "cancel this order" while still in cart/pre-checkout).',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'show_menu',
                'description' => 'Send the customer a tappable WhatsApp list of what\'s available, instead of typing out a text list. Call with no category_id to show the top-level categories; call again with a category_id (from the categories just shown) to list that category\'s products.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'Omit to show categories. Provide to show products within that category.'],
                    ],
                ],
            ],
            [
                'name' => 'start_checkout',
                'description' => 'Two-step checkout. Step 1 — call with whatever of customer_name/city/customer_address/payment_method you have (omit confirm, or leave it false): this validates everything (delivery availability for that city, payment method, stock/price) and returns either what\'s still missing, why it can\'t proceed, or a full order breakdown (items, subtotal, delivery fee, total) to show the customer verbatim. Step 2 — only after the customer explicitly confirms THAT exact breakdown, call again with confirm=true to actually place the order. Never guess or default payment_method — always ask if the customer hasn\'t said. Never pass confirm=true unless the customer just confirmed.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_name' => ['type' => 'string'],
                        'city' => ['type' => 'string', 'description' => 'The delivery city, captured separately from the street/area address — ask for it explicitly if not given.'],
                        'customer_address' => ['type' => 'string', 'description' => 'The street/house/area-level delivery address, NOT just the city name.'],
                        'payment_method' => ['type' => 'string', 'enum' => ['cod', 'jazzcash', 'easypaisa', 'card'], 'description' => 'Only set this once the customer has explicitly told you which one they want — never assume or default it.'],
                        'confirm' => ['type' => 'boolean', 'description' => 'Set true only on the second call, only after the customer explicitly confirmed the exact breakdown start_checkout returned.'],
                    ],
                ],
            ],
            [
                'name' => 'get_order_status',
                'description' => 'Look up a past order the customer already placed — status, items, prices, and total. Only works for orders placed from this same phone number. If the customer doesn\'t give an order number, use their most recent order.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'order_number' => ['type' => 'string', 'description' => 'e.g. "ORD-20260818-0003". Omit to look up the customer\'s most recent order.'],
                    ],
                ],
            ],
            [
                'name' => 'escalate_to_human',
                'description' => 'Escalate this conversation to the vendor because the request cannot be resolved automatically.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['reason'],
                ],
            ],
            [
                'name' => 'search_knowledge_base',
                'description' => 'Search the vendor\'s uploaded documents, FAQs, and policies for information to answer the customer\'s question. Only answer using what this tool returns — if it returns no results, say you don\'t have that information rather than guessing.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'The customer\'s question, in their own words.'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'search_products_semantic',
                'description' => 'Find products matching a descriptive or fuzzy query (e.g. "something spicy under $10") that plain keyword search in search_products might miss.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];

        if ($salesGoal === 'faq_only') {
            return array_values(array_filter(
                $schemas,
                fn (array $schema) => ! in_array($schema['name'], self::FAQ_ONLY_EXCLUDED_TOOLS, true)
            ));
        }

        return $schemas;
    }

    public function execute(string $toolName, array $input, Conversation $conversation): array
    {
        return match ($toolName) {
            'search_products' => $this->searchProducts($conversation, $input),
            'get_store_candidates' => $this->getStoreCandidates($conversation, $input),
            'request_location' => $this->requestLocation($conversation),
            'add_to_cart' => $this->addToCart($conversation, $input),
            'get_cart' => $this->getCart($conversation),
            'update_cart_item' => $this->updateCartItem($conversation, $input),
            'remove_from_cart' => $this->removeFromCart($conversation, $input),
            'empty_cart' => $this->emptyCart($conversation),
            'show_menu' => $this->showMenu($conversation, $input),
            'start_checkout' => $this->startCheckout($conversation, $input),
            'get_order_status' => $this->getOrderStatus($conversation, $input),
            'escalate_to_human' => $this->escalateToHuman($conversation, $input),
            'search_knowledge_base' => $this->searchKnowledgeBase($conversation, $input),
            'search_products_semantic' => $this->searchProductsSemantic($conversation, $input),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    private function searchProducts(Conversation $conversation, array $input): array
    {
        if (! $conversation->store_id) {
            return ['error' => 'No store resolved yet for this conversation.'];
        }

        $results = $this->productSearch->search(
            storeId: $conversation->store_id,
            query: $input['query'] ?? null,
            priceMin: $input['price_min'] ?? null,
            priceMax: $input['price_max'] ?? null,
        );

        $this->rememberRecentProducts($conversation, $results->values()->all());

        return ['products' => $results->values()->all()];
    }

    /**
     * Persists a compact id/name/price summary of the products just shown to
     * the customer, so the next inbound message (a new job, with no memory of
     * this request's tool calls) can still resolve "yes place the order" to
     * the right product_id instead of re-searching from a blank slate.
     */
    private function rememberRecentProducts(Conversation $conversation, array $products): void
    {
        if (empty($products)) {
            return;
        }

        $summary = collect($products)
            ->take(10)
            ->map(fn (array $p) => [
                'id' => $p['id'] ?? null,
                'name' => $p['name'] ?? null,
                'price' => $p['price'] ?? null,
            ])
            ->all();

        $this->stateMachine->updateContext($conversation, ['recent_products' => $summary]);
    }

    private function getStoreCandidates(Conversation $conversation, array $input): array
    {
        if (! $conversation->hasLocation()) {
            return ['error' => 'Customer location not available yet.'];
        }

        $stores = $this->storeLocator->nearestStores(
            $conversation->vendor_id,
            (float) $conversation->customer_lat,
            (float) $conversation->customer_lng,
            $input['limit'] ?? 3,
        );

        return [
            'stores' => $stores->map(fn ($store) => [
                'id' => $store->id,
                'name' => $store->name,
                'distance_km' => $store->distance_km,
            ])->values()->all(),
        ];
    }

    private function requestLocation(Conversation $conversation): array
    {
        $this->stateMachine->advanceTo($conversation, 'awaiting_location');

        return ['status' => 'location_requested'];
    }

    private function addToCart(Conversation $conversation, array $input): array
    {
        if (! $conversation->store_id) {
            return ['error' => 'No store resolved yet for this conversation.'];
        }

        if (! isset($input['product_id'])) {
            return ['error' => 'product_id is required.'];
        }

        if (isset($input['quantity']) && ! $this->isWholeNumber($input['quantity'])) {
            return ['error' => 'Quantity must be a whole number.'];
        }

        try {
            $cart = $this->cartService->getOrCreateOpenCart($conversation);
            $item = $this->cartService->addItem(
                $cart,
                (int) $input['product_id'],
                (int) ($input['quantity'] ?? 1),
                isset($input['variant_id']) ? (int) $input['variant_id'] : null,
            );
        } catch (Throwable $e) {
            return ['error' => 'Could not add that item — it may not exist in this store.'];
        }

        $this->stateMachine->advanceTo($conversation, 'cart_review');
        $this->stateMachine->updateContext($conversation, ['recent_products' => []]);

        return [
            'status' => 'added',
            'cart_item_id' => $item->id,
            'cart_subtotal' => $cart->fresh()->subtotal(),
        ];
    }

    private function getCart(Conversation $conversation): array
    {
        $cart = $conversation->activeCart();

        if (! $cart || $cart->items->isEmpty()) {
            return ['items' => [], 'subtotal' => 0];
        }

        $cart->load('items.product');

        return [
            'items' => $cart->items->map(fn ($item) => [
                'product_name' => $item->product->name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => $item->lineTotal(),
            ])->values()->all(),
            'subtotal' => $cart->subtotal(),
        ];
    }

    private function updateCartItem(Conversation $conversation, array $input): array
    {
        $item = $this->findCartItem($conversation, $input['product_id'] ?? null);

        if (! $item) {
            return ['error' => 'That item is not in the cart.'];
        }

        if (isset($input['quantity']) && ! $this->isWholeNumber($input['quantity'])) {
            return ['error' => 'Quantity must be a whole number.'];
        }

        $this->cartService->updateQuantity($item, (int) ($input['quantity'] ?? 0));

        return ['status' => 'updated', 'cart' => $this->getCart($conversation)];
    }

    private function removeFromCart(Conversation $conversation, array $input): array
    {
        $item = $this->findCartItem($conversation, $input['product_id'] ?? null);

        if (! $item) {
            return ['error' => 'That item is not in the cart.'];
        }

        $this->cartService->removeItem($item);

        return ['status' => 'removed', 'cart' => $this->getCart($conversation)];
    }

    private function emptyCart(Conversation $conversation): array
    {
        $cart = $conversation->activeCart();

        if ($cart) {
            $this->cartService->emptyCart($cart);
        }

        return ['status' => 'emptied'];
    }

    private function findCartItem(Conversation $conversation, ?int $productId)
    {
        if (! $productId) {
            return null;
        }

        $cart = $conversation->activeCart();

        return $cart?->items()->where('product_id', $productId)->first();
    }

    /**
     * Sends a tappable WhatsApp list message (categories, or a category's
     * products) directly — a "here's the menu" text wall is exactly the
     * clunky UX this replaces. Also returns the same data to Claude so it
     * can write a short natural acknowledgement instead of restating it.
     */
    private function showMenu(Conversation $conversation, array $input): array
    {
        if (! $conversation->store_id) {
            return ['error' => 'No store resolved yet for this conversation.'];
        }

        $account = $conversation->vendor->whatsappAccount;

        if (! $account || ! $account->isActive()) {
            return ['error' => 'WhatsApp is not connected for this vendor.'];
        }

        return isset($input['category_id'])
            ? $this->sendCategoryProducts($conversation, $account, (int) $input['category_id'])
            : $this->sendCategoryList($conversation, $account);
    }

    private function sendCategoryList(Conversation $conversation, $account): array
    {
        $categories = ProductCategory::where('vendor_id', $conversation->vendor_id)
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->where('store_id', $conversation->store_id)->where('is_active', true))
            ->orderBy('name')
            ->limit(self::MAX_LIST_ROWS)
            ->get();

        if ($categories->isEmpty()) {
            return ['error' => 'No menu categories found for this store yet.'];
        }

        $rows = $categories->map(fn (ProductCategory $category) => [
            'id' => "category:{$category->id}",
            'title' => Str::limit($category->name, 24, ''),
        ])->all();

        $this->sendList($account, $conversation->customer_phone, 'What are you in the mood for?', 'View menu', $rows);

        return [
            'status' => 'categories_sent',
            'categories' => $categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            'note' => 'The category list was already sent to the customer as a tappable message — just briefly acknowledge it, do not repeat the category names as text.',
        ];
    }

    private function sendCategoryProducts(Conversation $conversation, $account, int $categoryId): array
    {
        $currency = $conversation->vendor->default_currency;

        $products = Product::withoutGlobalScope('vendor')
            ->where('store_id', $conversation->store_id)
            ->where('category_id', $categoryId)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(self::MAX_LIST_ROWS)
            ->get();

        if ($products->isEmpty()) {
            return ['error' => 'No products found in that category.'];
        }

        if (config('whatsapp.flow_id')) {
            $this->sendProductFlow($conversation, $account, $categoryId);
        } else {
            // Flow not registered yet (see whatsapp:flow:register) — fall back
            // to the old single-select list so the bot keeps working.
            $rows = $products->map(fn (Product $product) => [
                'id' => "product:{$product->id}",
                'title' => Str::limit($product->name, 24, ''),
                'description' => Str::limit("{$currency} ".number_format((float) $product->base_price, 2), 72, ''),
            ])->all();

            $this->sendList($account, $conversation->customer_phone, 'Tap an item to add it to your order.', 'View items', $rows);
        }

        $summary = $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) $p->base_price])->values()->all();
        $this->rememberRecentProducts($conversation, $summary);

        return [
            'status' => 'products_sent',
            'products' => $summary,
            'note' => 'The product list was already sent to the customer as a tappable message — just briefly acknowledge it, do not repeat the item list as text.',
        ];
    }

    /**
     * Sends the multi-select product picker Flow. The Flow's data-exchange
     * endpoint (WhatsAppFlowController) fetches the live product list itself
     * using the category_id encoded in flow_token — we don't send rows here.
     */
    private function sendProductFlow(Conversation $conversation, $account, int $categoryId): void
    {
        $flowToken = Crypt::encryptString(json_encode([
            'conversation_id' => $conversation->id,
            'category_id' => $categoryId,
        ]));

        try {
            $this->whatsApp->sendInteractiveList($account, $conversation->customer_phone, [
                'type' => 'flow',
                'body' => ['text' => 'Tap the items you\'d like to order — you can pick more than one.'],
                'action' => [
                    'name' => 'flow',
                    'parameters' => [
                        'flow_message_version' => '3',
                        'flow_token' => $flowToken,
                        'flow_id' => config('whatsapp.flow_id'),
                        'flow_cta' => 'View items',
                        'flow_action' => 'navigate',
                        'flow_action_payload' => ['screen' => 'PRODUCT_SELECT'],
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('WhatsApp Flow send failed', ['error' => $e->getMessage()]);
        }
    }

    private function sendList($account, string $toPhone, string $bodyText, string $buttonText, array $rows): void
    {
        try {
            $this->whatsApp->sendInteractiveList($account, $toPhone, [
                'type' => 'list',
                'body' => ['text' => $bodyText],
                'action' => [
                    'button' => Str::limit($buttonText, 20, ''),
                    'sections' => [['rows' => $rows]],
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('WhatsApp interactive list send failed', ['error' => $e->getMessage()]);
        }
    }

    private function startCheckout(Conversation $conversation, array $input): array
    {
        $cart = $conversation->activeCart();

        if (! $cart || $cart->items->isEmpty()) {
            return ['error' => 'Cart is empty — nothing to check out.'];
        }

        $cart = $this->cartService->refreshPrices($cart);

        // Whatever fields Claude passes THIS turn are remembered against the
        // conversation, so a later turn where the customer just confirms
        // without repeating themselves can still complete — Claude doesn't
        // have to re-extract it from a long noisy history.
        $pending = array_filter([
            'customer_name' => $input['customer_name'] ?? null,
            'city' => $input['city'] ?? null,
            'customer_address' => $input['customer_address'] ?? null,
            'payment_method' => $input['payment_method'] ?? null,
        ]);

        if (! empty($pending)) {
            $this->stateMachine->updateContext($conversation, ['pending_checkout' => array_merge(
                $this->stateMachine->stateFor($conversation)->context['pending_checkout'] ?? [],
                $pending,
            )]);
        }

        $stored = $this->stateMachine->stateFor($conversation)->context['pending_checkout'] ?? [];
        $customerName = $input['customer_name'] ?? $stored['customer_name'] ?? null;
        $city = $input['city'] ?? $stored['city'] ?? null;
        $customerAddress = $input['customer_address'] ?? $stored['customer_address'] ?? null;
        // Never defaults — payment_method is only ever set once the customer
        // has explicitly stated it, this turn or a remembered earlier one.
        $paymentMethod = $input['payment_method'] ?? $stored['payment_method'] ?? null;

        $missing = array_filter([
            'customer_name' => empty($customerName),
            'city' => empty($city),
            'customer_address' => empty($customerAddress) || ! $this->isCompleteAddress($customerAddress, $city),
            'payment_method' => empty($paymentMethod),
        ]);

        if (! empty($missing)) {
            $response = ['status' => 'missing_fields', 'missing' => array_keys($missing)];

            if (isset($missing['payment_method'])) {
                $response['message'] = 'Which payment method would you prefer? Cash on Delivery, Card, JazzCash or EasyPaisa.';
            }

            return $response;
        }

        $delivery = $conversation->store
            ? $this->deliveryAvailability->check($conversation->store, $city)
            : ['available' => true, 'store_city' => null, 'delivery_fee' => 0.0];

        if (! $delivery['available']) {
            // Force re-capture of the rejected city/address rather than
            // leaving an unusable value sitting in context.
            $this->stateMachine->updateContext($conversation, [
                'pending_checkout' => collect($stored)->except(['city', 'customer_address'])->all(),
            ]);

            return [
                'status' => 'delivery_unavailable',
                'message' => "Sorry, we currently only deliver within {$delivery['store_city']} — we're not able to deliver to {$city} right now.",
            ];
        }

        $validation = $this->checkoutService->validate($cart, $paymentMethod);

        if (! $validation['valid']) {
            return ['status' => 'invalid', 'errors' => $validation['errors']];
        }

        if ($paymentMethod !== 'cod' && ! $this->paymentService->supportsOnlinePayment($paymentMethod)) {
            // Never silently fall back to cod — clear it so the customer
            // must be explicitly asked and must explicitly agree.
            $this->stateMachine->updateContext($conversation, [
                'pending_checkout' => collect($stored)->except('payment_method')->all(),
            ]);

            return [
                'status' => 'online_payment_unavailable',
                'message' => "Online payment via {$paymentMethod} isn't available yet — would you like to pay Cash on Delivery instead?",
            ];
        }

        $deliveryFee = (float) $delivery['delivery_fee'];
        $subtotal = (float) $cart->subtotal();
        $total = $subtotal + $deliveryFee;

        // Step 1 of 2: show the authoritative breakdown and wait — never
        // create the order until the customer has explicitly confirmed
        // exactly this, and Claude has echoed confirm=true back to us.
        if (($input['confirm'] ?? false) !== true) {
            return [
                'status' => 'confirm_required',
                'items' => $cart->items->map(fn ($item) => [
                    'product_name' => $item->product->name,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'line_total' => (float) $item->lineTotal(),
                ])->all(),
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
                'city' => $city,
                'customer_address' => $customerAddress,
                'payment_method' => $paymentMethod,
                'note' => 'Show this exact breakdown to the customer (items, subtotal, delivery fee, total, address, payment method) and wait for them to explicitly confirm before calling start_checkout again with confirm=true. Do not recalculate or restate these numbers differently.',
            ];
        }

        try {
            $order = $this->checkoutService->createOrder(
                $cart,
                $paymentMethod,
                $customerName,
                "{$customerAddress}, {$city}",
                $conversation->customer_lat !== null ? (float) $conversation->customer_lat : null,
                $conversation->customer_lng !== null ? (float) $conversation->customer_lng : null,
                $deliveryFee,
            );
        } catch (Throwable $e) {
            Log::error('Order creation failed during checkout tool', ['error' => $e->getMessage()]);

            return ['error' => 'Something went wrong placing the order. Please try again.'];
        }

        $this->stateMachine->updateContext($conversation, ['pending_checkout' => []]);

        $this->stateMachine->advanceTo($conversation, 'order_placed');

        return [
            'status' => 'order_placed',
            'order_number' => $order->order_number,
            'total' => (float) $order->total,
            'delivery_fee' => (float) $order->delivery_fee,
            'payment_method' => $order->payment_method,
        ];
    }

    /**
     * Scoped to BOTH the conversation's vendor_id (via withoutGlobalScope +
     * explicit where, since this needs cross-store lookup within one
     * vendor) AND customer_phone — a customer must never be able to look up
     * another customer's order just by guessing/knowing its order number.
     * Sends each item's product image as its own WhatsApp message first
     * (best-effort), then returns the real order data for the model to
     * summarize — mirrors the deterministic image-send pattern already used
     * for product taps (see RunConversationAgent::sendProductImageIfSelected).
     */
    private function getOrderStatus(Conversation $conversation, array $input): array
    {
        $query = Order::withoutGlobalScope('vendor')
            ->with('items.product.images')
            ->where('vendor_id', $conversation->vendor_id)
            ->where('customer_phone', $conversation->customer_phone);

        $order = empty($input['order_number'])
            ? $query->latest('id')->first()
            : $query->where('order_number', $input['order_number'])->first();

        if (! $order) {
            return ['error' => 'No matching order found for this number.'];
        }

        $account = $conversation->vendor->whatsappAccount;

        foreach ($order->items as $item) {
            $image = $item->product?->images->sortByDesc('is_primary')->first();

            if (! $image || ! $account) {
                continue;
            }

            try {
                $this->whatsApp->sendImage($account, $conversation->customer_phone, $image->publicUrl(), $item->product_name_snapshot);
            } catch (Throwable $e) {
                Log::error('WhatsApp order item image send failed', ['error' => $e->getMessage(), 'order_id' => $order->id]);
            }
        }

        return [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'items' => $order->items->map(fn ($item) => [
                'product_name' => $item->product_name_snapshot,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->all(),
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'total' => (float) $order->total,
            'payment_method' => $order->payment_method,
            'customer_address' => $order->customer_address,
            'placed_at' => $order->created_at->toDateTimeString(),
            'note' => 'Any product images were already sent as separate WhatsApp messages — do not claim to attach images yourself, just reference that they were sent.',
        ];
    }

    /**
     * Lightweight completeness backstop, not real NLU — the actual
     * structural fix is capturing city/address as separate tool inputs
     * (see the start_checkout schema) so Claude is far less likely to mash
     * a name+city into one field the way the reported bug did. This just
     * catches the residual case of an empty, trivially short, or
     * city-repeating address slipping through.
     */
    private function isCompleteAddress(string $address, ?string $city): bool
    {
        $trimmed = trim($address);

        if (mb_strlen($trimmed) < 8) {
            return false;
        }

        if ($city && Str::lower($trimmed) === Str::lower(trim($city))) {
            return false;
        }

        return true;
    }

    /**
     * Defensive backstop: Anthropic's tool-use schema already types
     * quantity as an integer, so this should rarely trigger — but a naive
     * (int) cast would silently truncate 1.5 to 1 rather than reject it.
     */
    private function isWholeNumber(mixed $value): bool
    {
        return is_int($value) || (is_numeric($value) && (float) $value == (int) $value);
    }

    private function escalateToHuman(Conversation $conversation, array $input): array
    {
        $this->stateMachine->escalate($conversation);

        return ['status' => 'escalated', 'reason' => $input['reason'] ?? null];
    }

    private function searchKnowledgeBase(Conversation $conversation, array $input): array
    {
        if (empty($input['query'])) {
            return ['error' => 'query is required.'];
        }

        try {
            $results = $this->knowledgeRetriever->retrieve($conversation->vendor_id, $input['query'], $conversation->id);
        } catch (Throwable $e) {
            Log::error('Knowledge base search failed', ['error' => $e->getMessage()]);

            return ['found' => false, 'message' => 'Knowledge base search is temporarily unavailable — answer from general knowledge or offer to escalate to a human.'];
        }

        if ($results->isEmpty()) {
            return ['found' => false, 'message' => 'No relevant information found in the knowledge base for this query.'];
        }

        return [
            'found' => true,
            'results' => $results->map(fn (array $r) => [
                'content' => $r['chunk']->content,
                'relevance_score' => round($r['score'], 3),
            ])->all(),
        ];
    }

    private function searchProductsSemantic(Conversation $conversation, array $input): array
    {
        if (empty($input['query'])) {
            return ['error' => 'query is required.'];
        }

        if (! $conversation->store_id) {
            return ['error' => 'No store resolved yet for this conversation.'];
        }

        try {
            $results = $this->productRetriever->retrieve($conversation->vendor_id, $conversation->store_id, $input['query'], $conversation->id);
        } catch (Throwable $e) {
            Log::error('Semantic product search failed', ['error' => $e->getMessage()]);

            return ['error' => 'Product search is temporarily unavailable — try search_products (keyword search) instead.'];
        }

        $this->rememberRecentProducts($conversation, $results->values()->all());

        return ['products' => $results->values()->all()];
    }
}
