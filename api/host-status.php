<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

use DashStatus\Services\AapanelService;

header('Content-Type: application/json; charset=utf-8');

auth_check();

$aapanelConfig = $config['services']['aapanel'];
$aapanel = new AapanelService(
    baseUrl: $aapanelConfig['base_url'],
    apiKey: $aapanelConfig['api_key'],
    diskPath: $aapanelConfig['disk_path'],
    verifySsl: $aapanelConfig['verify_ssl'],
    cacheFile: __DIR__ . '/../data/aapanel-cache.json',
    securityEntrance: $aapanelConfig['security_entrance'] ?? ''
);

function stateForPct(float $pct): string
{
    return $pct >= 90 ? 'crit' : ($pct >= 75 ? 'warn' : 'ok');
}

$payload = ['ok' => true, 'error' => null];

try {
    $status = $aapanel->getHostStatus();

    $payload['updatedAt'] = $status['updatedAt'];
    $payload['cpu'] = $status['cpu'] + ['state' => stateForPct($status['cpu']['pct'])];
    $payload['mem'] = $status['mem'] + ['state' => stateForPct($status['mem']['pct'])];
    $payload['disk'] = $status['disk'] + ['state' => stateForPct($status['disk']['pct'])];
    $payload['network'] = $status['network'];
    $payload['load'] = $status['load'];

    if (($_GET['debug'] ?? '') === '1') {
        $payload['raw'] = $aapanel->getRaw();

        try {
            $payload['security_probe'] = $aapanel->probeSecurityOverview();
        } catch (\Throwable $e) {
            $payload['security_probe_error'] = $e->getMessage();
        }
    }
} catch (\Throwable $e) {
    $payload['ok'] = false;
    $payload['error'] = $e->getMessage();
}

echo json_encode($payload);
