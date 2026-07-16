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
    securityEntrance: $aapanelConfig['security_entrance'] ?? '',
    securityCacheFile: __DIR__ . '/../data/aapanel-security-cache.json'
);

function stateForPct(float $pct): string
{
    return $pct >= 90 ? 'crit' : ($pct >= 75 ? 'warn' : 'ok');
}

function stateForScore(int $score): string
{
    return $score >= 80 ? 'ok' : ($score >= 60 ? 'warn' : 'crit');
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
    $payload['server'] = $status['server'];

    if (($_GET['debug'] ?? '') === '1') {
        $payload['raw'] = $aapanel->getRaw();
    }
} catch (\Throwable $e) {
    $payload['ok'] = false;
    $payload['error'] = $e->getMessage();
}

// Independente do status do host acima — o modulo de seguranca (/v2/safecloud)
// e uma rota separada, entao uma falha aqui nao deve derrubar CPU/RAM/disco/rede.
try {
    $secSummary = $aapanel->getSecuritySummary();
    $payload['security'] = $secSummary + ['state' => stateForScore($secSummary['score'])];

    if (($_GET['debug'] ?? '') === '1') {
        $payload['security_raw'] = $aapanel->getSecurityRaw();
    }
} catch (\Throwable $e) {
    $payload['security'] = null;
    $payload['securityError'] = $e->getMessage();
}

echo json_encode($payload);
