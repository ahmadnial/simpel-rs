<?php

return [
    'url' => env('ONLYOFFICE_URL', 'http://localhost:8080'),
    'jwt_secret' => env('ONLYOFFICE_JWT_SECRET', ''),
    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('ONLYOFFICE_ALLOWED_HOSTS', parse_url(env('ONLYOFFICE_URL', 'http://localhost:8080'), PHP_URL_HOST) ?: ''))
    ))),
];
