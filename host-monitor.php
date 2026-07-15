<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

auth_check();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stratelli · Host Monitor</title>
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
        <p>HOST MONITOR</p>
      </div>
    </div>
    <div class="header-right">
      <div class="clock"><span class="dot"></span> Última leitura: <span id="host-last-read">—</span></div>
      <div class="status-pill ok" id="host-pill">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.9L2.7 18a2 2 0 001.7 3h15.2a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
        <span id="host-pill-label">Conectando…</span>
      </div>
      <a href="hub.php" class="clock" style="text-decoration:none;">← Dashboards</a>
      <a href="logout.php" class="clock" style="text-decoration:none;">Sair</a>
    </div>
  </header>

  <div class="section-label">
    <div class="bar"></div><h2>Servidor</h2>
    <div class="live-tag" style="margin-left:auto;"><span class="dot"></span> ao vivo · a cada 8s</div>
  </div>

  <div id="host-error" class="login-error" style="margin-bottom:16px;" hidden></div>

  <div class="modal-cards">

    <!-- CPU -->
    <div class="card" id="cpu-card">
      <div class="card-top">
        <div class="service-id">
          <div class="service-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--ok)" stroke-width="1.8" id="cpu-icon"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/></svg>
          </div>
          <div>
            <div class="service-name">CPU</div>
            <div class="service-meta">Processamento do servidor</div>
          </div>
        </div>
        <div class="mode-tag ok" id="cpu-mode-tag">—</div>
      </div>

      <div class="gauge-row">
        <div class="gauge">
          <svg width="96" height="96" viewBox="0 0 96 96">
            <circle cx="48" cy="48" r="40" fill="none" stroke="var(--bg-raised)" stroke-width="9"/>
            <circle cx="48" cy="48" r="40" fill="none" stroke="var(--ok)" stroke-width="9"
              stroke-linecap="round" stroke-dasharray="251.3" stroke-dashoffset="251.3" id="cpu-gauge-fill"/>
          </svg>
          <div class="gauge-center">
            <div class="pct" id="cpu-pct">—</div>
            <div class="pct-label">usado</div>
          </div>
        </div>
        <div class="usage-detail">
          <div class="big-num" id="cpu-cores">—</div>
          <div class="caption">núcleos detectados</div>
        </div>
      </div>

      <div class="stat-grid">
        <div class="stat-box"><div class="label">Load (1 / 5 / 15min)</div><div class="val" id="cpu-load">— / — / —</div></div>
      </div>
    </div>

    <!-- RAM -->
    <div class="card" id="mem-card">
      <div class="card-top">
        <div class="service-id">
          <div class="service-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--ok)" stroke-width="1.8" id="mem-icon"><rect x="3" y="7" width="18" height="10" rx="1.5"/><path d="M7 7V5M11 7V5M15 7V5M17 7V5M7 17v2M11 17v2M15 17v2M17 17v2"/></svg>
          </div>
          <div>
            <div class="service-name">Memória RAM</div>
            <div class="service-meta">Uso atual do servidor</div>
          </div>
        </div>
        <div class="mode-tag ok" id="mem-mode-tag">—</div>
      </div>

      <div class="gauge-row">
        <div class="gauge">
          <svg width="96" height="96" viewBox="0 0 96 96">
            <circle cx="48" cy="48" r="40" fill="none" stroke="var(--bg-raised)" stroke-width="9"/>
            <circle cx="48" cy="48" r="40" fill="none" stroke="var(--ok)" stroke-width="9"
              stroke-linecap="round" stroke-dasharray="251.3" stroke-dashoffset="251.3" id="mem-gauge-fill"/>
          </svg>
          <div class="gauge-center">
            <div class="pct" id="mem-pct">—</div>
            <div class="pct-label">usado</div>
          </div>
        </div>
        <div class="usage-detail">
          <div class="big-num" id="mem-used">— <span class="of">/ — MB</span></div>
          <div class="caption">memória em uso</div>
        </div>
      </div>
    </div>

    <!-- DISCO -->
    <div class="card" id="disk-card">
      <div class="card-top">
        <div class="service-id">
          <div class="service-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--ok)" stroke-width="1.8" id="disk-icon"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
          </div>
          <div>
            <div class="service-name">Disco</div>
            <div class="service-meta" id="disk-path">Partição principal</div>
          </div>
        </div>
        <div class="mode-tag ok" id="disk-mode-tag">—</div>
      </div>

      <div class="gauge-row">
        <div class="gauge">
          <svg width="96" height="96" viewBox="0 0 96 96">
            <circle cx="48" cy="48" r="40" fill="none" stroke="var(--bg-raised)" stroke-width="9"/>
            <circle cx="48" cy="48" r="40" fill="none" stroke="var(--ok)" stroke-width="9"
              stroke-linecap="round" stroke-dasharray="251.3" stroke-dashoffset="251.3" id="disk-gauge-fill"/>
          </svg>
          <div class="gauge-center">
            <div class="pct" id="disk-pct">—</div>
            <div class="pct-label">usado</div>
          </div>
        </div>
        <div class="usage-detail">
          <div class="big-num" id="disk-used">— <span class="of">/ — GB</span></div>
          <div class="caption">espaço em disco</div>
        </div>
      </div>

      <div class="status-list" id="disk-others" style="margin-top:14px;"></div>
    </div>

    <!-- REDE -->
    <div class="card" id="net-card">
      <div class="card-top">
        <div class="service-id">
          <div class="service-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="1.8"><path d="M5 12.5a11 11 0 0114 0M8 16a6.5 6.5 0 018 0M12 19.5h.01"/></svg>
          </div>
          <div>
            <div class="service-name">Rede</div>
            <div class="service-meta">Tráfego em tempo real</div>
          </div>
        </div>
      </div>

      <div class="stat-grid">
        <div class="stat-box"><div class="label">↑ Upload</div><div class="val" id="net-up">—</div></div>
        <div class="stat-box"><div class="label">↓ Download</div><div class="val" id="net-down">—</div></div>
      </div>
    </div>

  </div>

  <footer>
        Stratelli 2026
  </footer>

</div>
<script src="assets/js/host-monitor.js?v=<?= filemtime(__DIR__ . '/assets/js/host-monitor.js') ?>"></script>
</body>
</html>
