<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

auth_check();

use DashStatus\Services\OpenCageService;
use DashStatus\Services\MapboxService;
use DashStatus\Services\StatuspageService;
use DashStatus\ActivityLog;
use DashStatus\StateTracker;

// ---- OpenCage: dado real via CSV de uso do dashboard ----
$opencageConfig = $config['services']['opencage'];
$opencage = new OpenCageService(
    usageCsvUrl: $opencageConfig['usage_csv_url'],
    dailyLimit: $opencageConfig['daily_limit'],
    cacheFile: __DIR__ . '/data/opencage-usage.csv'
);

$ocError = null;
try {
    $oc = $opencage->getUsage(90);
} catch (\Throwable $e) {
    $ocError = $e->getMessage();
    $oc = [
        'used' => 0,
        'limit' => $opencageConfig['daily_limit'],
        'remaining' => $opencageConfig['daily_limit'],
        'history' => [],
        'referenceDate' => date('Y-m-d'),
        'hasRecentActivity' => false,
    ];
}

$ocPct = $oc['limit'] > 0 ? ($oc['used'] / $oc['limit']) * 100 : 0;
$ocPctLabel = $ocPct > 0 && $ocPct < 10 ? round($ocPct, 1) : (int) round($ocPct);
$ocGaugePct = max(0, min(100, $ocPct));
$ocCircumference = 251.3;
$ocDashOffset = round($ocCircumference * (1 - $ocGaugePct / 100), 1);

$statusLabels = ['ok' => 'Operacional', 'warn' => 'Alerta', 'crit' => 'Crítico'];

$ocState = $ocPct >= 90 ? 'crit' : ($ocPct >= 75 ? 'warn' : 'ok');
$ocColorVar = ['crit' => 'var(--crit)', 'warn' => 'var(--warn)', 'ok' => 'var(--ok)'][$ocState];
$ocCardStateClass = $ocState === 'ok' ? '' : (' state-' . $ocState);
$ocStatusLabel = $statusLabels[$ocState];

$tz = new \DateTimeZone('America/Sao_Paulo');
$nowSp = new \DateTimeImmutable('now', $tz);

$ocRefDate = new \DateTimeImmutable($oc['referenceDate'], $tz);
$ocRefIsToday = $oc['referenceDate'] === $nowSp->format('Y-m-d');
$ocRefLabel = $ocRefIsToday ? 'Hoje' : $ocRefDate->format('d/m/Y');

// ---- Mapbox: sem API de uso oficial, leitura manual salva em data/ ----
$mapboxConfig = $config['services']['mapbox'];
$mapbox = new MapboxService(
    monthlyLimit: $mapboxConfig['monthly_limit'],
    storageFile: __DIR__ . '/data/mapbox-usage.json'
);

$mbError = null;
try {
    $mb = $mapbox->getUsage();
} catch (\Throwable $e) {
    $mbError = $e->getMessage();
    $mb = ['used' => 0, 'limit' => $mapboxConfig['monthly_limit'], 'updated_at' => null];
}

$mbPct = $mb['limit'] > 0 ? ($mb['used'] / $mb['limit']) * 100 : 0;
$mbPctLabel = $mbPct > 0 && $mbPct < 10 ? round($mbPct, 1) : (int) round($mbPct);
$mbGaugePct = max(0, min(100, $mbPct));
$mbDashOffset = round($ocCircumference * (1 - $mbGaugePct / 100), 1);

$mapboxState = $mbError ? 'warn' : ($mbPct >= 90 ? 'crit' : ($mbPct >= 75 ? 'warn' : 'ok'));
$mbColorVar = ['crit' => 'var(--crit)', 'warn' => 'var(--warn)', 'ok' => 'var(--ok)'][$mapboxState];
$mbCardStateClass = $mapboxState === 'ok' ? '' : (' state-' . $mapboxState);
$mbStatusLabel = $statusLabels[$mapboxState];

$mbUpdatedLabel = 'Nunca';
if (!empty($mb['updated_at'])) {
    $mbUpdatedAt = new \DateTimeImmutable($mb['updated_at']);
    $mbUpdatedLabel = $mbUpdatedAt->format('d/m/Y H:i');
}

$mbHistory = $mapbox->getDailyHistory();

// ---- Mapbox Search (Temporary Geocoding API): mesmo esquema do Map Loads ----
$mapboxSearchConfig = $config['services']['mapbox_search'];
$mapboxSearch = new MapboxService(
    monthlyLimit: $mapboxSearchConfig['monthly_limit'],
    storageFile: __DIR__ . '/data/mapbox-search-usage.json'
);

$srError = null;
try {
    $sr = $mapboxSearch->getUsage();
} catch (\Throwable $e) {
    $srError = $e->getMessage();
    $sr = ['used' => 0, 'limit' => $mapboxSearchConfig['monthly_limit'], 'updated_at' => null];
}

$srPct = $sr['limit'] > 0 ? ($sr['used'] / $sr['limit']) * 100 : 0;
$srPctLabel = $srPct > 0 && $srPct < 10 ? round($srPct, 1) : (int) round($srPct);
$srGaugePct = max(0, min(100, $srPct));
$srDashOffset = round($ocCircumference * (1 - $srGaugePct / 100), 1);

$mapboxSearchState = $srError ? 'warn' : ($srPct >= 90 ? 'crit' : ($srPct >= 75 ? 'warn' : 'ok'));
$srColorVar = ['crit' => 'var(--crit)', 'warn' => 'var(--warn)', 'ok' => 'var(--ok)'][$mapboxSearchState];
$srCardStateClass = $mapboxSearchState === 'ok' ? '' : (' state-' . $mapboxSearchState);
$srStatusLabel = $statusLabels[$mapboxSearchState];

$srUpdatedLabel = 'Nunca';
if (!empty($sr['updated_at'])) {
    $srUpdatedAt = new \DateTimeImmutable($sr['updated_at']);
    $srUpdatedLabel = $srUpdatedAt->format('d/m/Y H:i');
}

$srHistory = $mapboxSearch->getDailyHistory();

// ---- Status externos (Statuspage publico) — lista cresce via config/status-pages.php ----
$statusPagesConfig = require __DIR__ . '/config/status-pages.php';

$statusIndicatorMap = ['none' => 'ok', 'minor' => 'warn', 'major' => 'warn', 'critical' => 'crit'];
$componentStateMap = ['operational' => 'ok', 'degraded_performance' => 'warn', 'partial_outage' => 'warn', 'major_outage' => 'crit', 'under_maintenance' => 'warn'];

$statusServices = [];
foreach ($statusPagesConfig as $svc) {
    $statuspage = new StatuspageService(
        summaryUrl: $svc['summary_url'],
        cacheFile: __DIR__ . '/data/status-' . $svc['key'] . '.json'
    );

    $svcError = null;
    try {
        $svcData = $statuspage->getStatus();
    } catch (\Throwable $e) {
        $svcError = $e->getMessage();
        $svcData = ['indicator' => 'none', 'description' => 'Indisponível', 'components' => [], 'incidents' => [], 'updatedAt' => ''];
    }

    $svcState = $svcError ? 'warn' : ($statusIndicatorMap[$svcData['indicator']] ?? 'ok');
    $problemComponents = array_values(array_filter(
        $svcData['components'],
        static fn (array $c): bool => ($c['status'] ?? 'operational') !== 'operational'
    ));

    $statusServices[] = [
        'key' => $svc['key'],
        'name' => $svc['name'],
        'meta' => $svc['meta'],
        'link' => $svc['link'],
        'icon' => $svc['icon'] ?? '',
        'state' => $svcState,
        'colorVar' => ['crit' => 'var(--crit)', 'warn' => 'var(--warn)', 'ok' => 'var(--ok)'][$svcState],
        'error' => $svcError,
        'description' => $svcData['description'],
        'componentsTotal' => count($svcData['components']),
        'problemComponents' => $problemComponents,
        'incidents' => $svcData['incidents'] ?? [],
    ];
}

// ---- Registro de atividade: detecta mudanca de estado dos servicos ----
$stateTracker = new StateTracker(__DIR__ . '/data/service-states.json');
$activityLog = new ActivityLog(__DIR__ . '/data/activity-log.json');

$trackedLabels = $statusLabels + ['error' => 'Erro'];
$trackedLevel = ['ok' => 'ok', 'warn' => 'warn', 'crit' => 'crit', 'error' => 'crit'];

$ocTrackedState = $ocError ? 'error' : $ocState;
$ocTransition = $stateTracker->checkTransition('opencage', $ocTrackedState);
if ($ocTransition['changed'] && ($ocTransition['previous'] !== null || $ocTrackedState !== 'ok')) {
    $activityLog->log(
        $trackedLevel[$ocTrackedState],
        $ocTransition['previous'] !== null
            ? sprintf('OpenCage passou de %s para %s.', $trackedLabels[$ocTransition['previous']], $trackedLabels[$ocTrackedState])
            : sprintf('OpenCage está em %s.', $trackedLabels[$ocTrackedState])
    );
}

$mbTrackedState = $mbError ? 'error' : $mapboxState;
$mbTransition = $stateTracker->checkTransition('mapbox', $mbTrackedState);
if ($mbTransition['changed'] && ($mbTransition['previous'] !== null || $mbTrackedState !== 'ok')) {
    $activityLog->log(
        $trackedLevel[$mbTrackedState],
        $mbTransition['previous'] !== null
            ? sprintf('Mapbox passou de %s para %s.', $trackedLabels[$mbTransition['previous']], $trackedLabels[$mbTrackedState])
            : sprintf('Mapbox está em %s.', $trackedLabels[$mbTrackedState])
    );
}

$srTrackedState = $srError ? 'error' : $mapboxSearchState;
$srTransition = $stateTracker->checkTransition('mapbox_search', $srTrackedState);
if ($srTransition['changed'] && ($srTransition['previous'] !== null || $srTrackedState !== 'ok')) {
    $activityLog->log(
        $trackedLevel[$srTrackedState],
        $srTransition['previous'] !== null
            ? sprintf('Mapbox Search passou de %s para %s.', $trackedLabels[$srTransition['previous']], $trackedLabels[$srTrackedState])
            : sprintf('Mapbox Search está em %s.', $trackedLabels[$srTrackedState])
    );
}

foreach ($statusServices as $svc) {
    $svcTrackedState = $svc['error'] ? 'error' : $svc['state'];

    // Fingerprint inclui severidade + incidentes ativos + componentes com problema,
    // assim um incidente novo no mesmo nivel de severidade (ex: warn -> warn) ainda
    // e detectado como mudanca, nao so a transicao de ok/warn/crit.
    $incidentNames = array_map(static fn (array $i): string => $i['name'], $svc['incidents']);
    sort($incidentNames);
    $componentFingerprint = array_map(static fn (array $c): string => $c['name'] . ':' . $c['status'], $svc['problemComponents']);
    sort($componentFingerprint);
    $svcFingerprint = $svcTrackedState . '|' . implode(',', $incidentNames) . '|' . implode(',', $componentFingerprint);

    $svcTransition = $stateTracker->checkTransition($svc['key'], $svcFingerprint);
    if ($svcTransition['changed'] && ($svcTransition['previous'] !== null || $svcTrackedState !== 'ok')) {
        if ($svcTrackedState === 'ok') {
            $reason = 'Voltou ao normal.';
        } elseif ($svc['error']) {
            $reason = $svc['error'];
        } elseif (!empty($svc['incidents'])) {
            $reason = implode('; ', array_map(static fn (array $i): string => $i['name'], $svc['incidents']));
        } elseif (!empty($svc['problemComponents'])) {
            $reason = 'Problema em: ' . implode(', ', array_map(static fn (array $c): string => $c['name'], $svc['problemComponents']));
        } else {
            $reason = $svc['description'];
        }

        $activityLog->log(
            $trackedLevel[$svcTrackedState],
            sprintf('%s - %s', $svc['name'], $reason)
        );
    }
}

$activityEntries = $activityLog->recent(10);

$statusCritCount = count(array_filter($statusServices, static fn (array $s): bool => $s['state'] === 'crit'));
$statusWarnCount = count(array_filter($statusServices, static fn (array $s): bool => $s['state'] === 'warn'));

$criticalCount = ($ocState === 'crit' ? 1 : 0) + ($mapboxState === 'crit' ? 1 : 0) + ($mapboxSearchState === 'crit' ? 1 : 0) + $statusCritCount;
$warnCount = ($ocState === 'warn' ? 1 : 0) + ($mapboxState === 'warn' ? 1 : 0) + ($mapboxSearchState === 'warn' ? 1 : 0) + $statusWarnCount;

if ($criticalCount > 0) {
    $pillState = 'crit';
    $pillLabel = "{$criticalCount} alerta" . ($criticalCount > 1 ? 's' : '') . ' crítico' . ($criticalCount > 1 ? 's' : '');
} elseif ($warnCount > 0) {
    $pillState = 'warn';
    $pillLabel = "{$warnCount} alerta" . ($warnCount > 1 ? 's' : '') . ' de atenção';
} else {
    $pillState = 'ok';
    $pillLabel = 'Tudo normal';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stratelli · Monitor de APIs</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>
<div class="wrap">

  <header>
    <div class="brand">
      <img class="brand-logo" src="assets/img/logo-gray.png" alt="Stratelli">
      <div class="brand-text">
        <p>API MONITOR</p>
      </div>
    </div>
    <div class="header-right">
      <div class="clock"><span class="dot"></span> Última varredura: <?= $nowSp->format('H:i:s') ?></div>
      <div class="status-pill <?= $pillState ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.9L2.7 18a2 2 0 001.7 3h15.2a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
        <?= htmlspecialchars($pillLabel, ENT_QUOTES, 'UTF-8') ?>
      </div>
      <a href="hub.php" class="clock" style="text-decoration:none;">← Dashboards</a>
      <a href="logout.php" class="clock" style="text-decoration:none;">Sair</a>
    </div>
  </header>

  <div class="summary">
    <div class="summary-chip">
      <div class="label">Geocoding Opencage</div>
      <div class="value"><?= number_format($oc['used'], 0, ',', '.') ?> <span>/ <?= number_format($oc['limit'], 0, ',', '.') ?> <br>(OpenCage)</span></div>
    </div>
    <div class="summary-chip">
      <div class="label">Loads do Mapbox</div>
      <div class="value"><?= number_format($mb['used'], 0, ',', '.') ?> <span>/ <?= number_format($mb['limit'], 0, ',', '.') ?> <br>(Mapbox)</span></div>
    </div>
    <div class="summary-chip">
      <div class="label">Mapbox Search</div>
      <div class="value"><?= number_format($sr['used'], 0, ',', '.') ?> <span>/ <?= number_format($sr['limit'], 0, ',', '.') ?> <br>(Geocoding API)</span></div>
    </div>
    <div class="summary-chip">
      <div class="label">APIs monitoradas</div>
      <div class="value"><?= 3 + count($statusServices) ?> <span>ativas</span></div>
    </div>
    <div class="summary-chip<?= $criticalCount + $warnCount > 0 ? ' alert' : '' ?>">
      <div class="label">Alertas ativos</div>
      <div class="value"><?= $criticalCount + $warnCount ?> <span><?= $criticalCount > 0 ? 'crítico' : ($warnCount > 0 ? 'atenção' : 'nenhum') ?></span></div>
    </div>
  </div>

  <div class="section-label section-label-with-action">
    <div class="bar"></div><h2>Status por serviço</h2>
    <button type="button" class="view-all-btn" id="open-services-modal">Ver todos os serviços →</button>
  </div>

  <div class="cards-wrap">
    <button type="button" class="cards-arrow cards-arrow-left" aria-label="Ver card anterior" data-dir="-1">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    </button>
    <button type="button" class="cards-arrow cards-arrow-right" aria-label="Ver próximo card" data-dir="1">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
    </button>

  <div class="cards">

<?php ob_start(); ?>
    <!-- OPENCAGE CARD -->
    <div class="card<?= $ocCardStateClass ?>">
      <div class="card-top">
        <div class="service-id">
          <div class="service-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $ocColorVar ?>" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 3a15 15 0 010 18M3 12h18"/></svg>
          </div>
          <div>
            <div class="service-name">OpenCage</div>
            <div class="service-meta">Geocoding API · Plano Medium</div>
          </div>
        </div>
        <div class="mode-tag <?= $ocState ?>"><?= $ocStatusLabel ?></div>
      </div>

      <?php if ($ocError): ?>
        <div class="login-error" style="margin-bottom:16px;">Falha ao buscar uso: <?= htmlspecialchars($ocError, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <div class="gauge-row">
        <div class="gauge">
          <svg width="96" height="96" viewBox="0 0 96 96">
            <circle cx="48" cy="48" r="40" fill="none" stroke="var(--bg-raised)" stroke-width="9"/>
            <circle cx="48" cy="48" r="40" fill="none" stroke="<?= $ocColorVar ?>" stroke-width="9"
              stroke-linecap="round" stroke-dasharray="251.3" stroke-dashoffset="<?= $ocDashOffset ?>"/>
          </svg>
          <div class="gauge-center">
            <div class="pct"><?= $ocPctLabel ?>%</div>
            <div class="pct-label">usado</div>
          </div>
        </div>
        <div class="usage-detail">
          <div class="big-num"><?= number_format($oc['used'], 0, ',', '.') ?> <span class="of">/ <?= number_format($oc['limit'], 0, ',', '.') ?> req</span></div>
          <div class="caption">
            <?php if ($oc['hasRecentActivity']): ?>
             Plano Medium - 125.000 / dia
            <?php else: ?>
              Nenhuma requisição registrada nos últimos <?= count($oc['history']) ?> dias
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="stat-grid">
        <div class="stat-box"><div class="label">Restantes no dia</div><div class="val"><?= number_format($oc['remaining'], 0, ',', '.') ?> req</div></div>
        <div class="stat-box"><div class="label">Dia de referência</div><div class="val"><?= $ocRefLabel ?></div></div>
      </div>

      <div class="chart-toolbar">
        <div class="oc-chart-label" id="opencage-chart-label">Uso diário — últimos 30 dias</div>
        <div class="chart-filter" data-chart-filter="opencage-chart">
          <button type="button" class="active" data-range="day">Dias</button>
          <button type="button" data-range="month">Mês</button>
          <button type="button" data-range="year">Ano</button>
        </div>
      </div>
      <div class="oc-chart-wrap">
        <canvas
          id="opencage-chart"
          data-history="<?= htmlspecialchars(json_encode($oc['history']), ENT_QUOTES, 'UTF-8') ?>"
        ></canvas>
      </div>

      <div class="card-footer">
        <a href="https://opencagedata.com/dashboard" target="_blank">Ver dashboard →</a>
      </div>
    </div>
<?php $ocCardHtml = ob_get_clean(); ob_start(); ?>

    <!-- MAPBOX CARD -->
    <div class="card<?= $mbCardStateClass ?>">
      <div class="card-top">
        <div class="service-id">
          <div class="service-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $mbColorVar ?>" stroke-width="1.8"><path d="M12 21s7-6.5 7-12a7 7 0 10-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="9" r="2.4"/></svg>
          </div>
          <div>
            <div class="service-name">Mapbox</div>
            <div class="service-meta">Maps · Free Tier</div>
          </div>
        </div>
        <div class="mode-tag <?= $mapboxState ?>"><?= $mbStatusLabel ?></div>
      </div>

      <?php if ($mbError): ?>
        <div class="login-error" style="margin-bottom:16px;">Sem leitura registrada ainda. <a href="mapbox-update.php" style="color:inherit;text-decoration:underline;">Cadastrar uso →</a></div>
      <?php endif; ?>

      <div class="gauge-row">
        <div class="gauge">
          <svg width="96" height="96" viewBox="0 0 96 96">
            <circle cx="48" cy="48" r="40" fill="none" stroke="var(--bg-raised)" stroke-width="9"/>
            <circle cx="48" cy="48" r="40" fill="none" stroke="<?= $mbColorVar ?>" stroke-width="9"
              stroke-linecap="round" stroke-dasharray="251.3" stroke-dashoffset="<?= $mbDashOffset ?>"/>
          </svg>
          <div class="gauge-center">
            <div class="pct"><?= $mbPctLabel ?>%</div>
            <div class="pct-label">usado</div>
          </div>
        </div>
        <div class="usage-detail">
          <div class="big-num"><?= number_format($mb['used'], 0, ',', '.') ?> <span class="of">/ <?= number_format($mb['limit'], 0, ',', '.') ?> map loads</span></div>
          <div class="caption">Plano - Free Tier</div>
        </div>
      </div>

      <div class="stat-grid">
        <div class="stat-box"><div class="label">Restantes</div><div class="val"><?= number_format(max(0, $mb['limit'] - $mb['used']), 0, ',', '.') ?> loads</div></div>
        <div class="stat-box"><div class="label">Atualizado em</div><div class="val"><?= htmlspecialchars($mbUpdatedLabel, ENT_QUOTES, 'UTF-8') ?></div></div>
      </div>

      <?php if (count($mbHistory) > 0): ?>
        <div class="chart-toolbar">
          <div class="oc-chart-label" id="mapbox-chart-label">Loads registrados</div>
          <div class="chart-filter" data-chart-filter="mapbox-chart">
            <button type="button" class="active" data-range="day">Dias</button>
            <button type="button" data-range="month">Mês</button>
            <button type="button" data-range="year">Ano</button>
          </div>
        </div>
        <div class="oc-chart-wrap">
          <canvas
            id="mapbox-chart"
            data-history="<?= htmlspecialchars(json_encode($mbHistory), ENT_QUOTES, 'UTF-8') ?>"
          ></canvas>
        </div>
      <?php else: ?>
        <div class="oc-chart-label">Registre mais uma leitura pra ver a evolução aqui</div>
      <?php endif; ?>

      <div class="card-footer">
        <span><a href="mapbox-update.php" style="color:var(--signal);">Atualizar uso →</a></span>
        <a href="https://console.mapbox.com/account/statistics/" target="_blank">Abrir Statistics ↗</a>
      </div>
    </div>
<?php $mbCardHtml = ob_get_clean(); ob_start(); ?>

    <!-- MAPBOX SEARCH CARD -->
    <div class="card<?= $srCardStateClass ?>">
      <div class="card-top">
        <div class="service-id">
          <div class="service-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $srColorVar ?>" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
          </div>
          <div>
            <div class="service-name">Mapbox Search</div>
            <div class="service-meta">Geocoding API · Free Tier</div>
          </div>
        </div>
        <div class="mode-tag <?= $mapboxSearchState ?>"><?= $srStatusLabel ?></div>
      </div>

      <?php if ($srError): ?>
        <div class="login-error" style="margin-bottom:16px;">Sem leitura registrada ainda. <a href="mapbox-search-update.php" style="color:inherit;text-decoration:underline;">Cadastrar uso →</a></div>
      <?php endif; ?>

      <div class="gauge-row">
        <div class="gauge">
          <svg width="96" height="96" viewBox="0 0 96 96">
            <circle cx="48" cy="48" r="40" fill="none" stroke="var(--bg-raised)" stroke-width="9"/>
            <circle cx="48" cy="48" r="40" fill="none" stroke="<?= $srColorVar ?>" stroke-width="9"
              stroke-linecap="round" stroke-dasharray="251.3" stroke-dashoffset="<?= $srDashOffset ?>"/>
          </svg>
          <div class="gauge-center">
            <div class="pct"><?= $srPctLabel ?>%</div>
            <div class="pct-label">usado</div>
          </div>
        </div>
        <div class="usage-detail">
          <div class="big-num"><?= number_format($sr['used'], 0, ',', '.') ?> <span class="of">/ <?= number_format($sr['limit'], 0, ',', '.') ?> req</span></div>
          <div class="caption">Plano - Free Tier</div>
        </div>
      </div>

      <div class="stat-grid">
        <div class="stat-box"><div class="label">Restantes</div><div class="val"><?= number_format(max(0, $sr['limit'] - $sr['used']), 0, ',', '.') ?> req</div></div>
        <div class="stat-box"><div class="label">Atualizado em</div><div class="val"><?= htmlspecialchars($srUpdatedLabel, ENT_QUOTES, 'UTF-8') ?></div></div>
      </div>

      <?php if (count($srHistory) > 0): ?>
        <div class="chart-toolbar">
          <div class="oc-chart-label" id="mapbox-search-chart-label">Requisições registradas</div>
          <div class="chart-filter" data-chart-filter="mapbox-search-chart">
            <button type="button" class="active" data-range="day">Dias</button>
            <button type="button" data-range="month">Mês</button>
            <button type="button" data-range="year">Ano</button>
          </div>
        </div>
        <div class="oc-chart-wrap">
          <canvas
            id="mapbox-search-chart"
            data-history="<?= htmlspecialchars(json_encode($srHistory), ENT_QUOTES, 'UTF-8') ?>"
          ></canvas>
        </div>
      <?php else: ?>
        <div class="oc-chart-label">Registre mais uma leitura pra ver a evolução aqui</div>
      <?php endif; ?>

      <div class="card-footer">
        <span><a href="mapbox-search-update.php" style="color:var(--signal);">Atualizar uso →</a></span>
        <a href="https://console.mapbox.com/account/statistics/" target="_blank">Abrir Statistics ↗</a>
      </div>
    </div>
<?php
$srCardHtml = ob_get_clean();

// Ordena os cards: estados em alerta/crítico primeiro, depois por % do
// gauge (maior uso primeiro) dentro do mesmo estado.
$statePriority = ['crit' => 0, 'warn' => 1, 'ok' => 2];
$sortedCards = [
    ['state' => $ocState, 'pct' => $ocPct, 'html' => $ocCardHtml],
    ['state' => $mapboxState, 'pct' => $mbPct, 'html' => $mbCardHtml],
    ['state' => $mapboxSearchState, 'pct' => $srPct, 'html' => $srCardHtml],
];
usort($sortedCards, function (array $a, array $b) use ($statePriority): int {
    $stateCompare = $statePriority[$a['state']] <=> $statePriority[$b['state']];
    return $stateCompare !== 0 ? $stateCompare : ($b['pct'] <=> $a['pct']);
});
foreach ($sortedCards as $cardItem) {
    echo $cardItem['html'];
}
?>
  </div>
  </div>

  <div class="modal-overlay" id="services-modal" hidden>
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="services-modal-title">
      <div class="modal-header">
        <h2 id="services-modal-title">Todos os serviços</h2>
        <button type="button" class="modal-close" id="close-services-modal" aria-label="Fechar">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="modal-body">
        <!-- Preenchido via JS: os cards reais são movidos pra cá (não duplicados),
             pra não quebrar os IDs únicos usados pelos gráficos. -->
        <div class="modal-cards"></div>
      </div>
    </div>
  </div>

  <div class="section-label"><div class="bar"></div><h2>Status LLM</h2></div>

  <div class="status-tiles">
    <?php foreach ($statusServices as $svc): ?>
      <div class="status-tile<?= $svc['state'] === 'ok' ? '' : (' state-' . $svc['state']) ?>">
        <div class="status-tile-top">
          <div class="service-id">
            <div class="service-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="<?= $svc['colorVar'] ?>" stroke-width="1.8"><?= $svc['icon'] ?></svg>
            </div>
            <div>
              <div class="status-tile-name"><?= htmlspecialchars($svc['name'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="status-tile-meta"><?= htmlspecialchars($svc['meta'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          </div>
          <div class="mode-tag <?= $svc['state'] ?>"><?= $statusLabels[$svc['state']] ?></div>
        </div>

        <?php if ($svc['error']): ?>
          <div class="status-tile-caption">Falha ao buscar status: <?= htmlspecialchars($svc['error'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif (empty($svc['problemComponents'])): ?>
          <div class="status-tile-caption"><?= $svc['componentsTotal'] ?>/<?= $svc['componentsTotal'] ?> serviços operacionais</div>
        <?php else: ?>
          <div class="status-tile-caption"><?= count($svc['problemComponents']) ?> de <?= $svc['componentsTotal'] ?> com problema</div>
          <div class="status-list">
            <?php foreach ($svc['problemComponents'] as $component): ?>
              <?php $compState = $componentStateMap[$component['status']] ?? 'warn'; ?>
              <div class="status-row">
                <span class="status-dot <?= $compState ?>"></span>
                <span class="status-name"><?= htmlspecialchars($component['name'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="status-state <?= $compState ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $component['status'])), ENT_QUOTES, 'UTF-8') ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="card-footer">
          <a href="<?= htmlspecialchars($svc['link'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">Ver status →</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="section-label"><div class="bar"></div><h2>Registro de atividade</h2></div>

  <div class="log-panel">
    <div class="log-header">
      <h2 style="margin:0;">Eventos recentes</h2>
      <div class="live-tag"><span class="dot"></span> monitorando</div>
    </div>
    <div class="log-list">
      <?php if (empty($activityEntries)): ?>
        <div class="log-item">
          <div class="log-time">—</div>
          <div class="log-badge sys">sistema</div>
          <div class="log-text">Nenhum evento registrado ainda.</div>
        </div>
      <?php endif; ?>
      <?php foreach ($activityEntries as $entry): ?>
        <?php
          $entryAt = new \DateTimeImmutable($entry['time']);
          if ($entryAt->format('Y-m-d') === $nowSp->format('Y-m-d')) {
              $entryTimeLabel = $entryAt->format('H:i:s');
          } elseif ($entryAt->format('Y-m-d') === $nowSp->modify('-1 day')->format('Y-m-d')) {
              $entryTimeLabel = 'Ontem, ' . $entryAt->format('H:i');
          } else {
              $entryTimeLabel = $entryAt->format('d/m/Y H:i');
          }
          $entryBadgeLabel = ['crit' => 'crítico', 'warn' => 'atenção', 'ok' => 'normal', 'sys' => 'sistema'][$entry['level']] ?? $entry['level'];
        ?>
        <div class="log-item">
          <div class="log-time"><?= htmlspecialchars($entryTimeLabel, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="log-badge <?= htmlspecialchars($entry['level'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($entryBadgeLabel, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="log-text"><?= htmlspecialchars($entry['text'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <footer>
        Stratelli 2026
  </footer>

</div>
<script src="assets/js/vendor/chart.umd.js"></script>
<script src="assets/js/opencage-chart.js?v=<?= filemtime(__DIR__ . '/assets/js/opencage-chart.js') ?>"></script>
<script src="assets/js/mapbox-chart.js?v=<?= filemtime(__DIR__ . '/assets/js/mapbox-chart.js') ?>"></script>
<script src="assets/js/cards-carousel.js?v=<?= filemtime(__DIR__ . '/assets/js/cards-carousel.js') ?>"></script>
<script src="assets/js/services-modal.js?v=<?= filemtime(__DIR__ . '/assets/js/services-modal.js') ?>"></script>
<script src="assets/js/auto-refresh.js?v=<?= filemtime(__DIR__ . '/assets/js/auto-refresh.js') ?>"></script>
</body>
</html>
