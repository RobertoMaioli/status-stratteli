<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

auth_check();

use DashStatus\ActivityLog;
use DashStatus\Services\MapboxService;

$mapboxSearchConfig = $config['services']['mapbox_search'];
$mapboxSearch = new MapboxService(
    monthlyLimit: $mapboxSearchConfig['monthly_limit'],
    storageFile: __DIR__ . '/data/mapbox-search-usage.json'
);

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Sessão expirada, tente novamente.';
    } else {
        $used = filter_var($_POST['used'] ?? '', FILTER_VALIDATE_INT);
        if ($used === false || $used < 0) {
            $error = 'Informe um número inteiro válido de requisições.';
        } else {
            $delta = $mapboxSearch->setUsage($used);
            $activityLog = new ActivityLog(__DIR__ . '/data/activity-log.json');
            if ($delta === null) {
                $success = 'Primeira leitura registrada com sucesso.';
                $activityLog->log('sys', sprintf('Mapbox Search: primeira leitura registrada — %s requisições.', number_format($used, 0, ',', '.')));
            } else {
                $success = sprintf('Uso atualizado: %s requisições (%s%s desde a leitura anterior).', number_format($used, 0, ',', '.'), $delta >= 0 ? '+' : '', number_format($delta, 0, ',', '.'));
                $activityLog->log('sys', sprintf('Mapbox Search: leitura registrada — %s requisições (%s%s desde a última).', number_format($used, 0, ',', '.'), $delta >= 0 ? '+' : '', number_format($delta, 0, ',', '.')));
            }
        }
    }
}

$current = null;
try {
    $current = $mapboxSearch->getUsage();
} catch (\Throwable $e) {
    // nenhuma leitura registrada ainda
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sentinel · Atualizar uso do Mapbox Search</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/login.css">
</head>
<body class="login-body">
  <form class="login-card" method="post" action="mapbox-search-update.php">
    <div class="brand">
      <div class="brand-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--crit)" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      </div>
      <div class="brand-text">
        <h1>Mapbox Search</h1>
        <p>ATUALIZAR USO MANUAL</p>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="login-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="login-error" style="background:var(--ok-glow);border-color:rgba(52,211,153,0.35);color:var(--ok);">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($current): ?>
      <div class="login-error" style="background:var(--bg-panel-alt);border-color:var(--line-soft);color:var(--text-muted);">
        Última leitura: <?= number_format($current['used'], 0, ',', '.') ?> / <?= number_format($current['limit'], 0, ',', '.') ?>
        em <?= htmlspecialchars((new \DateTimeImmutable($current['updated_at']))->format('d/m/Y H:i'), ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <label>
      Requisições de Search usadas neste período
      <input type="number" name="used" min="0" required autofocus value="<?= $current ? (int) $current['used'] : '' ?>">
    </label>

    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <button type="submit">Salvar</button>
    <a href="index.php" style="text-align:center;color:var(--text-muted);font-size:12.5px;text-decoration:none;">← Voltar ao dashboard</a>
  </form>
</body>
</html>
