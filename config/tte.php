<?php

return [
    'profile' => 'internal_strong_otp_v2',

    'otp' => [
        'digits' => 8,
        'ttl_seconds' => 180,
        'max_attempts' => 5,
        'max_sends_per_window' => 3,
        'send_window_minutes' => 15,
        'max_sends_per_day' => 20,
        'policy_version' => 'otp-email-internal-v2.0',
        'action' => 'sign_document',
        'reauthentication_max_age_seconds' => 300,
        // `display` hanya diizinkan pada local/testing untuk uji alur tanpa email.
        'delivery' => env('TTE_OTP_DELIVERY', 'email'),
        // Dibaca saat config cache dibuat agar tetap tersedia pada production.
        'secret' => env('TTE_OTP_HMAC_SECRET'),
        'destination_secret' => env('TTE_OTP_DESTINATION_HMAC_SECRET'),
    ],

    'verifier' => [
        'max_upload_kilobytes' => 20480,
        'dangerous_pdf_tokens' => ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/OpenAction'],
    ],

    'immutable' => [
        'retention_days' => 3650,
    ],

    'providers' => [
        'signer' => env('TTE_SIGNER_PROVIDER', 'unavailable'),
        'immutable_store' => env('TTE_IMMUTABLE_STORE_PROVIDER', 'unavailable'),
        'openbao' => [
            'address' => env('OPENBAO_ADDR', 'http://127.0.0.1:8200'),
            'token' => env('OPENBAO_TOKEN'),
            'transit_key' => env('OPENBAO_TRANSIT_KEY', 'simpel-rs-evidence'),
            'timeout_seconds' => (int) env('OPENBAO_TIMEOUT_SECONDS', 10),
        ],
        'minio' => [
            'endpoint' => env('MINIO_ENDPOINT', 'http://127.0.0.1:9000'),
            'region' => env('MINIO_REGION', 'us-east-1'),
            'bucket' => env('MINIO_BUCKET', 'simpel-rs-evidence'),
            'access_key' => env('MINIO_ACCESS_KEY'),
            'secret_key' => env('MINIO_SECRET_KEY'),
        ],
    ],

    'monitoring' => [
        'channel' => env('TTE_SECURITY_LOG_CHANNEL', 'stack'),
    ],
];
