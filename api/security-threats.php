<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

use DashStatus\Services\CrowdSecService;

header('Content-Type: application/json; charset=utf-8');

auth_check();

$crowdsecConfig = $config['services']['crowdsec'];
$crowdsec = new CrowdSecService(
    lapiUrl: $crowdsecConfig['lapi_url'],
    machineId: $crowdsecConfig['machine_id'],
    password: $crowdsecConfig['password'],
    tokenFile: __DIR__ . '/../data/crowdsec-token.json',
    alertsCacheFile: __DIR__ . '/../data/crowdsec-alerts-cache.json',
    cacheTtlSeconds: $crowdsecConfig['cache_ttl_seconds']
);

$payload = ['ok' => true, 'error' => null];

try {
    $summary = $crowdsec->getThreatSummary();
    $payload += $summary;
} catch (\Throwable $e) {
    $payload['ok'] = false;
    $payload['error'] = $e->getMessage();
    $payload['updatedAt'] = null;
    $payload['totals'] = ['alerts' => 0, 'events' => 0, 'countries' => 0, 'uniqueIps' => 0];
    $payload['events'] = [];
    $payload['byScenario'] = [];
    $payload['byCountry'] = [];
    $payload['timeseries'] = [];
}

echo json_encode($payload);
