<?php

return [
    'default_currency' => env('DEFAULT_CURRENCY', 'PKR'),
    'store_search_radius_km' => (float) env('STORE_SEARCH_RADIUS_KM', 50),
    'default_low_stock_threshold' => (int) env('DEFAULT_LOW_STOCK_THRESHOLD', 5),

    // Flat delivery fee applied when DeliveryAvailabilityService confirms a
    // store delivers to the customer's city. 0 by default — no dollar
    // amount changes until a vendor/admin sets this explicitly.
    'default_delivery_fee' => (float) env('DEFAULT_DELIVERY_FEE', 0),

    'payment_gateways' => [
        'jazzcash' => [
            'merchant_id' => env('JAZZCASH_MERCHANT_ID'),
            'password' => env('JAZZCASH_PASSWORD'),
            'integrity_salt' => env('JAZZCASH_INTEGRITY_SALT'),
        ],
        'easypaisa' => [
            'store_id' => env('EASYPAISA_STORE_ID'),
            'hash_key' => env('EASYPAISA_HASH_KEY'),
        ],
        'card' => [
            'public_key' => env('CARD_GATEWAY_PUBLIC_KEY'),
            'secret_key' => env('CARD_GATEWAY_SECRET_KEY'),
        ],
    ],
];
