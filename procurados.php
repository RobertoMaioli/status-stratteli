<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

auth_check();

$dataFile = __DIR__ . '/assets/data/procurados.json';
$dataFileExists = is_file($dataFile);
$procurados = $dataFileExists ? json_decode((string) file_get_contents($dataFile), true) : [];
if (!is_array($procurados)) {
    $procurados = [];
}
$updatedAt = 'ATUALIZADO ' . strtoupper(date('d M Y', $dataFileExists ? filemtime($dataFile) : time()));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stratelli · Painel de Procurados</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>
<div class="wrap wanted-wrap">

  <header>
    <div class="brand">
      <img class="brand-logo" src="assets/img/logo-gray.png" alt="Stratelli">
      <div class="brand-text">
        <p>PAINEL DE PROCURADOS · MARINGÁ/PR</p>
      </div>
    </div>
    <div class="header-right">
      <div class="clock"><span class="dot"></span> <?= htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8') ?></div>
      <a href="hub.php" class="clock" style="text-decoration:none;">← Dashboards</a>
      <a href="logout.php" class="clock" style="text-decoration:none;">Sair</a>
    </div>
  </header>

  <div class="summary" aria-label="Indicadores">
    <div class="summary-chip">
      <div class="label">Total de registros</div>
      <div class="value" id="wanted-stat-total">0</div>
    </div>
    <div class="summary-chip alert">
      <div class="label">Alta/Altíssima periculosidade</div>
      <div class="value" id="wanted-stat-alta">0</div>
    </div>
    <div class="summary-chip">
      <div class="label">Sem foto (não exibidos)</div>
      <div class="value" id="wanted-stat-foto">0</div>
    </div>
  </div>

  <div class="wanted-layout">

    <aside class="wanted-filters" aria-label="Filtros">
      <div class="wanted-filters-head">
        <span class="mono-label">Filtros</span>
        <button type="button" class="link-btn" id="wanted-clear-filters">limpar</button>
      </div>
      <div class="wanted-filters-body">
        <div class="filter-group">
          <p class="mono-label filter-group-title">Categoria</p>
          <div class="filter-list" id="wanted-filter-categoria"></div>
        </div>
        <div class="filter-group">
          <p class="mono-label filter-group-title">Periculosidade</p>
          <div class="pill-group" id="wanted-filter-risco"></div>
        </div>
      </div>
    </aside>

    <section class="wanted-results">
      <div class="wanted-toolbar">
        <div class="wanted-search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
          <input id="wanted-search" type="search" placeholder="Buscar por nome, alcunha ou mandado" aria-label="Buscar">
        </div>
        <div class="chart-filter" id="wanted-sort" role="group" aria-label="Ordenar"></div>
      </div>

      <div class="wanted-meta">
        <span class="mono-label" id="wanted-result-count">0 REGISTROS ENCONTRADOS</span>
        <span class="mono-label mono-label--dim">EXIBINDO SOMENTE REGISTROS COM FOTO — FONTE: BNMP/TJPR</span>
      </div>

      <div class="wanted-grid" id="wanted-grid"></div>
      <div class="wanted-empty" id="wanted-empty" hidden>NENHUM REGISTRO CORRESPONDE AOS FILTROS</div>
      <div class="log-pagination" id="wanted-pagination" style="margin-top:16px;" hidden>
        <button type="button" class="page-btn" id="wanted-page-prev" aria-label="Página anterior">‹</button>
        <span class="page-label" id="wanted-page-label">Página 1 de 1</span>
        <button type="button" class="page-btn" id="wanted-page-next" aria-label="Próxima página">›</button>
      </div>
    </section>
  </div>

  <footer>
        Stratelli 2026
  </footer>

</div>

<div class="wanted-drawer-overlay" id="wanted-drawer-overlay" hidden>
  <aside class="wanted-drawer" role="dialog" aria-modal="true" aria-labelledby="wanted-drawer-name">
    <div class="wanted-drawer-head">
      <span class="mono-label">Ficha <span id="wanted-drawer-id"></span></span>
      <button type="button" class="modal-close" id="wanted-drawer-close" aria-label="Fechar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>
    <div class="wanted-drawer-body">
      <div class="wanted-hero">
        <div class="wanted-hero-photo" id="wanted-drawer-photo"></div>
        <div class="wanted-hero-ident">
          <h2 class="wanted-hero-name" id="wanted-drawer-name"></h2>
          <p class="wanted-hero-vulgo" id="wanted-drawer-vulgo"></p>
          <span class="mode-tag" id="wanted-drawer-risco"></span>
          <div class="wanted-tags" id="wanted-drawer-tags"></div>
        </div>
      </div>
      <dl class="wanted-fields" id="wanted-drawer-fields"></dl>
    </div>
  </aside>
</div>

<script>
window.WANTED_RISCOS = {
  altissima: { label: "ALTÍSSIMA PERICULOSIDADE", curto: "ALTÍSSIMA", cls: "severe" },
  alta:      { label: "ALTA PERICULOSIDADE",      curto: "ALTA",      cls: "crit" },
  media:     { label: "MÉDIA PERICULOSIDADE",     curto: "MÉDIA",     cls: "warn" },
  baixa:     { label: "BAIXA PERICULOSIDADE",     curto: "BAIXA",     cls: "ok" },
  sem:       { label: "SEM PERICULOSIDADE",       curto: "SEM RISCO", cls: "neutral" }
};
window.WANTED_DADOS = <?= json_encode($procurados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="assets/js/procurados.js?v=<?= filemtime(__DIR__ . '/assets/js/procurados.js') ?>"></script>
</body>
</html>
