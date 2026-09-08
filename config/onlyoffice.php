<?php

return [
    'url' => env('ONLYOFFICE_URL', 'http://localhost:8080'),
    'jwt_secret' => env('ONLYOFFICE_JWT_SECRET', ''),
    'download_url_ttl_minutes' => (int) env('ONLYOFFICE_DOWNLOAD_URL_TTL_MINUTES', 60),
    'callback_url_ttl_minutes' => (int) env('ONLYOFFICE_CALLBACK_URL_TTL_MINUTES', 1440),
    'callback_timeout_seconds' => (int) env('ONLYOFFICE_CALLBACK_TIMEOUT_SECONDS', 30),
    'max_document_kilobytes' => (int) env('ONLYOFFICE_MAX_DOCUMENT_KILOBYTES', 10240),
    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('ONLYOFFICE_ALLOWED_HOSTS', parse_url(env('ONLYOFFICE_URL', 'http://localhost:8080'), PHP_URL_HOST) ?: ''))
    ))),
];
