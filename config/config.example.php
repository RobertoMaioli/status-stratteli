<?php
declare(strict_types=1);

// Copie este arquivo para config.php e preencha com as chaves reais.
// config.php fica fora do versionamento (.gitignore).

return [
    'debug' => true,

    'db' => [
        'host' => '127.0.0.1',
        'name' => 'dashstatus',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    'services' => [
        'opencage' => [
            'api_key' => '',
            'daily_limit' => 125000,
            'endpoint' => 'https://api.opencagedata.com/geocode/v1/json',
            // Planos com assinatura (ex: Medium) nao retornam o campo `rate`
            // na resposta de geocoding. Pegue a URL do CSV em:
            // dashboard OpenCage > aba "Geocoding API" > link de download.
            'usage_csv_url' => '',
        ],
        'mapbox' => [
            'access_token' => '',
            'monthly_limit' => 50000,
            // Mapbox nao expoe uso via API publica - leitura manual.
        ],
    ],
];
