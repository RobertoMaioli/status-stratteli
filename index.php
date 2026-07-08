<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

auth_check();

use DashStatus\Services\OpenCageService;
use DashStatus\Services\MapboxService;
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
    $oc = $opencage->getUsage();
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

// ---- Registro de atividade: detecta mudanca de estado dos servicos ----
$stateTracker = new StateTracker(__DIR__ . '/data/service-states.json');
$activityLog = new ActivityLog(__DIR__ . '/data/activity-log.json');

$trackedLabels = $statusLabels + ['error' => 'Erro'];
$trackedLevel = ['ok' => 'ok', 'warn' => 'warn', 'crit' => 'crit', 'error' => 'crit'];

$ocTrackedState = $ocError ? 'error' : $ocState;
$ocTransition = $stateTracker->checkTransition('opencage', $ocTrackedState);
if ($ocTransition['changed'] && $ocTransition['previous'] !== null) {
    $activityLog->log(
        $trackedLevel[$ocTrackedState],
        sprintf('OpenCage passou de %s para %s.', $trackedLabels[$ocTransition['previous']], $trackedLabels[$ocTrackedState])
    );
}

$mbTrackedState = $mbError ? 'error' : $mapboxState;
$mbTransition = $stateTracker->checkTransition('mapbox', $mbTrackedState);
if ($mbTransition['changed'] && $mbTransition['previous'] !== null) {
    $activityLog->log(
        $trackedLevel[$mbTrackedState],
        sprintf('Mapbox passou de %s para %s.', $trackedLabels[$mbTransition['previous']], $trackedLabels[$mbTrackedState])
    );
}

$activityEntries = $activityLog->recent(10);

$criticalCount = ($ocState === 'crit' ? 1 : 0) + ($mapboxState === 'crit' ? 1 : 0);
$warnCount = ($ocState === 'warn' ? 1 : 0) + ($mapboxState === 'warn' ? 1 : 0);

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
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="wrap">

  <header>
    <div class="brand">
      <div class="brand-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="1.8"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/></svg>
      </div>
      <div class="brand-text">
        <h1>Stratelli · API Monitor</h1>
        <p>PROJETO · GEOLOCALIZAÇÃO &nbsp;/&nbsp; 2 SERVIÇOS MONITORADOS</p>
      </div>
    </div>
    <div class="header-right">
      <div class="clock"><span class="dot"></span> Última varredura: <?= $nowSp->format('H:i:s') ?></div>
      <div class="status-pill <?= $pillState ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.9L2.7 18a2 2 0 001.7 3h15.2a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
        <?= htmlspecialchars($pillLabel, ENT_QUOTES, 'UTF-8') ?>
      </div>
      <a href="logout.php" class="clock" style="text-decoration:none;">Sair</a>
    </div>
  </header>

  <div class="summary">
    <div class="summary-chip">
      <div class="label">Requisições (<?= $ocRefLabel ?>)</div>
      <div class="value"><?= number_format($oc['used'], 0, ',', '.') ?> <span>/ <?= number_format($oc['limit'], 0, ',', '.') ?> (OpenCage)</span></div>
    </div>
    <div class="summary-chip">
      <div class="label">Loads do Mapbox</div>
      <div class="value"><?= number_format($mb['used'], 0, ',', '.') ?> <span>/ <?= number_format($mb['limit'], 0, ',', '.') ?> (Mapbox)</span></div>
    </div>
    <div class="summary-chip">
      <div class="label">APIs monitoradas</div>
      <div class="value">2 <span>ativas</span></div>
    </div>
    <div class="summary-chip<?= $criticalCount + $warnCount > 0 ? ' alert' : '' ?>">
      <div class="label">Alertas ativos</div>
      <div class="value"><?= $criticalCount + $warnCount ?> <span><?= $criticalCount > 0 ? 'crítico' : ($warnCount > 0 ? 'atenção' : 'nenhum') ?></span></div>
    </div>
  </div>

  <div class="section-label"><div class="bar"></div><h2>Status por serviço</h2></div>

  <div class="cards">

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
              Último dia com uso: <strong><?= $ocRefLabel ?></strong> · 125.000/dia inclusos no plano Medium
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

      <div class="oc-chart-label">Uso diário — últimos <?= count($oc['history']) ?> dias</div>
      <div class="oc-chart-wrap">
        <canvas
          id="opencage-chart"
          data-history="<?= htmlspecialchars(json_encode($oc['history']), ENT_QUOTES, 'UTF-8') ?>"
        ></canvas>
      </div>

      <div class="card-footer">
        <span>Fonte: CSV de uso (dashboard OpenCage)</span>
        <a href="https://opencagedata.com/dashboard" target="_blank">Ver dashboard →</a>
      </div>
    </div>

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
        <div class="stat-box"><div class="label">Restantes</div><div class="val"><?= number_format(max(0, $mb['limit'] - $mb['used']), 0, ',', '.') ?></div></div>
        <div class="stat-box"><div class="label">Atualizado em</div><div class="val"><?= htmlspecialchars($mbUpdatedLabel, ENT_QUOTES, 'UTF-8') ?></div></div>
      </div>

      <?php if (count($mbHistory) > 0): ?>
        <div class="oc-chart-label">Loads entre leituras registradas</div>
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
<script src="assets/js/opencage-chart.js"></script>
<script src="assets/js/mapbox-chart.js"></script>
</body>
</html>
