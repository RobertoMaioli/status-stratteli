<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

auth_check();

$dashboards = require __DIR__ . '/config/dashboards.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stratelli · Dashboards</title>
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
        <p>DASHBOARDS</p>
      </div>
    </div>
    <div class="header-right">
      <a href="logout.php" class="clock" style="text-decoration:none;">Sair</a>
    </div>
  </header>

  <div class="section-label"><div class="bar"></div><h2>Escolha um dashboard</h2></div>

  <div class="hub-cards">
    <?php foreach ($dashboards as $dash): ?>
      <a class="hub-card" href="<?= htmlspecialchars($dash['link'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="service-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="1.8"><?= $dash['icon'] ?></svg>
        </div>
        <div class="hub-card-text">
          <div class="hub-card-name"><?= htmlspecialchars($dash['name'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="hub-card-desc"><?= htmlspecialchars($dash['description'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="hub-card-arrow">→</div>
      </a>
    <?php endforeach; ?>
  </div>

  <footer>
        Stratelli 2026
  </footer>

</div>
</body>
</html>
