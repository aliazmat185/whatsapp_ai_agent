<?php

return [
    // Platform-level Meta Tech Provider setup — shared across all vendors.
    // See PLAN.md decision-8 / A11 / §5a.
    'waba_id' => env('WHATSAPP_WABA_ID'),
    'system_user_token' => env('WHATSAPP_SYSTEM_USER_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v20.0'),
    'graph_base_url' => env('WHATSAPP_GRAPH_BASE_URL', 'https://graph.facebook.com'),

    // Two-step verification PIN used to register every vendor's number under
    // the shared platform WABA. Vendors never see or set this themselves.
    'two_step_pin' => env('WHATSAPP_TWO_STEP_PIN'),

    // Seeds the first/dev vendor's whatsapp_accounts row directly, bypassing
    // the OTP onboarding wizard for local testing.
    'dev_phone_number_id' => env('WHATSAPP_DEV_PHONE_NUMBER_ID'),

    // One shared Meta Product Catalog for the whole platform, connected to
    // the platform WABA in Meta Commerce Manager (one-time manual setup).
    // Products are namespaced per vendor via retailer_id, not per-vendor catalogs.
    'catalog_id' => env('WHATSAPP_CATALOG_ID'),

    // Multi-select product-picker Flow (see whatsapp:flow:generate-keys /
    // whatsapp:flow:register). The private key lives on disk, never in .env —
    // only its passphrase does.
    'flow_id' => env('WHATSAPP_FLOW_ID'),
    'flow_private_key_path' => 'private/whatsapp-flow/private.pem',
    'flow_private_key_passphrase' => env('WHATSAPP_FLOW_PRIVATE_KEY_PASSPHRASE'),

    // Meta's servers must fetch media over the public internet, so local dev
    // (APP_URL is localhost) needs a publicly reachable stand-in — an ngrok
    // tunnel to this machine — to serve product images. Not used in
    // production, where APP_URL is already public.
    'public_asset_base_url' => env('PUBLIC_ASSET_BASE_URL'),
];
