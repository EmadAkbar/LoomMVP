<?php

return [
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'stream_api_token' => env('CLOUDFLARE_STREAM_API_TOKEN'),
    'stream_webhook_secret' => env('CLOUDFLARE_STREAM_WEBHOOK_SECRET'),
    'webhook_signature_tolerance_seconds' => (int) env('CLOUDFLARE_WEBHOOK_SIGNATURE_TOLERANCE_SECONDS', 300),
];
