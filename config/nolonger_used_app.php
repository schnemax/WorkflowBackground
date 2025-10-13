<?php
// /var/nas002/paperless-ngx/app/config/app.php
return [
    //'internalToken' => $_SERVER['WORKER_INTERNAL_TOKEN'] ?? getenv('WORKER_INTERNAL_TOKEN') ?? 'change-me',
    'internalToken' => 'change-me',
    'paperless' => [
        'baseUrl' => $_SERVER['PAPERLESS_BASE_URL'] ?? getenv('PAPERLESS_BASE_URL') ?? 'http://127.0.0.1:8000',
       // 'apiToken' => $_SERVER['PAPERLESS_API_TOKEN'] ?? getenv('PAPERLESS_API_TOKEN') ?? '',
        'apiToken' => '6e1ad0cad2b10a98e76d97c4bfe561b36400d537',
    ],
    'maps' => [
        'typeMapFile'  => __DIR__ . '/type-map.json',
        'fieldMapFile' => __DIR__ . '/field-map.json',
    ],
];
