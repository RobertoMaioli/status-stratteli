<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

auth_check();

$crowdsecConfig = $config['services']['crowdsec'];
$pollIntervalMs = (int) ($crowdsecConfig['poll_interval_ms'] ?? 30000);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stratelli · Ameaças (CrowdSec)</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/vendor/leaflet.css">
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>
<div class="wrap">

  <header>
    <div class="brand">
      <img class="brand-logo" src="assets/img/logo-gray.png" alt="Stratelli">
      <div class="brand-text">
        <p>AMEAÇAS · CROWDSEC</p>
      </div>
    </div>
    <div class="header-right">
      <div class="clock"><span class="dot"></span> Última leitura: <span id="threat-last-read">—</span></div>
      <div class="status-pill ok" id="threat-pill">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.9L2.7 18a2 2 0 001.7 3h15.2a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
        <span id="threat-pill-label">Conectando…</span>
      </div>
      <a href="hub.php" class="clock" style="text-decoration:none;">← Dashboards</a>
      <a href="logout.php" class="clock" style="text-decoration:none;">Sair</a>
    </div>
  </header>

  <div class="section-label">
    <div class="bar"></div><h2>Ameaças detectadas</h2>
    <div class="live-tag" style="margin-left:auto;"><span class="dot"></span> ao vivo · a cada <?= (int) round($pollIntervalMs / 1000) ?>s · atualizado às <span id="threat-live-updated">—</span></div>
  </div>

  <div id="threat-error" class="login-error" style="margin-bottom:16px;" hidden></div>

  <div class="summary" id="threat-summary" data-poll-interval="<?= $pollIntervalMs ?>">
    <div class="summary-chip">
      <div class="label">Total de alertas</div>
      <div class="value" id="threat-total-alerts">—</div>
    </div>
    <div class="summary-chip">
      <div class="label">Eventos bloqueados</div>
      <div class="value" id="threat-total-events">—</div>
    </div>
    <div class="summary-chip">
      <div class="label">Países de origem</div>
      <div class="value" id="threat-total-countries">—</div>
    </div>
    <div class="summary-chip">
      <div class="label">IPs únicos</div>
      <div class="value" id="threat-total-ips">—</div>
    </div>
  </div>

  <!-- MAPA -->
  <div class="card" style="margin-bottom:32px;">
    <div class="card-top">
      <div class="service-id">
        <div class="service-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.8 5.7 3.8 9s-1.3 6.5-3.8 9c-2.5-2.5-3.8-5.7-3.8-9s1.3-6.5 3.8-9z"/></svg>
        </div>
        <div>
          <div class="service-name">Mapa de origem dos ataques</div>
          <div class="service-meta">Geolocalização dos IPs bloqueados pelo CrowdSec</div>
        </div>
      </div>
    </div>
    <div
      id="threat-map"
      style="height:420px;border-radius:10px;overflow:hidden;"
      data-server-lat="<?= htmlspecialchars((string) $crowdsecConfig['server_lat'], ENT_QUOTES, 'UTF-8') ?>"
      data-server-lng="<?= htmlspecialchars((string) $crowdsecConfig['server_lng'], ENT_QUOTES, 'UTF-8') ?>"
    ></div>
  </div>

  <!-- GRAFICOS + RANKING -->
  <div class="modal-cards">

    <div class="card">
      <div class="card-top">
        <div class="service-id">
          <div>
            <div class="service-name">Por tipo de ataque</div>
            <div class="service-meta">Cenários mais detectados</div>
          </div>
        </div>
      </div>
      <div style="position:relative;height:240px;">
        <canvas id="threat-scenario-chart"></canvas>
      </div>
    </div>

    <div class="card">
      <div class="card-top">
        <div class="service-id">
          <div>
            <div class="service-name">Linha do tempo</div>
            <div class="service-meta">Alertas por hora</div>
          </div>
        </div>
      </div>
      <div style="position:relative;height:240px;">
        <canvas id="threat-timeline-chart"></canvas>
      </div>
    </div>

    <div class="card">
      <div class="card-top">
        <div class="service-id">
          <div>
            <div class="service-name">Top países</div>
            <div class="service-meta">Origem dos ataques</div>
          </div>
        </div>
      </div>
      <div class="status-list" id="threat-country-list">
        <div class="status-row"><span class="status-name">Carregando…</span></div>
      </div>
    </div>

  </div>

  <div class="section-label"><div class="bar"></div><h2>Eventos recentes</h2></div>

  <div class="log-panel">
    <div class="log-header">
      <h2 style="margin:0;">Últimos alertas do CrowdSec</h2>
      <div class="live-tag"><span class="dot"></span> <span id="threat-events-count">0</span> itens</div>
    </div>
    <div class="log-list" id="threat-events-list">
      <div class="log-item">
        <div class="log-time">—</div>
        <div class="log-badge sys">—</div>
        <div class="log-text">Carregando…</div>
      </div>
    </div>
    <div class="log-pagination" id="threat-events-pagination" hidden>
      <button type="button" class="page-btn" id="threat-events-prev" aria-label="Página anterior">‹</button>
      <span class="page-label" id="threat-events-page-label">Página 1 de 1</span>
      <button type="button" class="page-btn" id="threat-events-next" aria-label="Próxima página">›</button>
    </div>
  </div>

  <footer>
        Stratelli 2026
  </footer>

</div>
<script src="assets/js/vendor/chart.umd.js"></script>
<script src="assets/js/vendor/leaflet.js"></script>
<script src="assets/js/security-threats.js?v=<?= filemtime(__DIR__ . '/assets/js/security-threats.js') ?>"></script>
</body>
</html>
