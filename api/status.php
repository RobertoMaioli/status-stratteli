<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

use DashStatus\Services\OpenCageService;

header('Content-Type: application/json; charset=utf-8');

auth_check();

$opencageConfig = $config['services']['opencage'];

$opencage = new OpenCageService(
    usageCsvUrl: $opencageConfig['usage_csv_url'],
    dailyLimit: $opencageConfig['daily_limit'],
    cacheFile: __DIR__ . '/../data/opencage-usage.csv'
);

$payload = [
    'ok' => true,
    'opencage' => null,
    'error' => null,
];

try {
    $payload['opencage'] = $opencage->getUsage();
} catch (\Throwable $e) {
    $payload['ok'] = false;
    $payload['error'] = $e->getMessage();
}

// TODO: instanciar DashStatus\Services\MapboxService quando a leitura
// manual de uso estiver definida.

echo json_encode($payload);