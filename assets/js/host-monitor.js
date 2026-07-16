(function () {
  var POLL_INTERVAL_MS = 8000;
  var CIRCUMFERENCE = 251.3;
  // Anel do Security Risk usa um raio maior (r=52) pra dar mais destaque
  // visual ao card — circunferencia propria, nao reaproveita a constante
  // acima (calculada pro raio 40 dos outros gauges).
  var SECURITY_CIRCUMFERENCE = 326.7;
  var STATE_LABELS = { ok: 'Operacional', warn: 'Alerta', crit: 'Crítico' };
  var STATE_COLOR_VAR = { ok: 'var(--ok)', warn: 'var(--warn)', crit: 'var(--crit)' };
  var SECURITY_LEVEL_LABELS = { Good: 'Bom', Fair: 'Regular', Moderate: 'Regular', Poor: 'Fraco', Danger: 'Perigo', Critical: 'Crítico', Secure: 'Seguro', secure: 'Seguro', Safe: 'Seguro' };
  var SECURITY_BADGE_MAP = { high: 'crit', medium: 'warn', low: 'ok' };
  var SECURITY_BADGE_LABEL = { high: 'alto', medium: 'médio', low: 'baixo' };
  var SECURITY_SEVERITY_PRIORITY = { high: 0, medium: 1, low: 2 };
  var SECURITY_NEWS_PAGE_SIZE = 10;

  var errorBox = document.getElementById('host-error');
  var pill = document.getElementById('host-pill');
  var pillLabel = document.getElementById('host-pill-label');
  var lastRead = document.getElementById('host-last-read');

  function pad2(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function formatBrDateTime(date) {
    return pad2(date.getDate()) + '/' + pad2(date.getMonth() + 1) + '/' + date.getFullYear() +
      ' ' + pad2(date.getHours()) + ':' + pad2(date.getMinutes());
  }

  function setGaugeState(prefix, pct, state) {
    var fill = document.getElementById(prefix + '-gauge-fill');
    var pctEl = document.getElementById(prefix + '-pct');
    var tag = document.getElementById(prefix + '-mode-tag');
    var card = document.getElementById(prefix + '-card');

    if (fill) {
      var offset = CIRCUMFERENCE * (1 - Math.max(0, Math.min(100, pct)) / 100);
      fill.setAttribute('stroke-dashoffset', offset.toFixed(1));
      fill.style.stroke = STATE_COLOR_VAR[state] || STATE_COLOR_VAR.ok;
    }
    if (pctEl) {
      pctEl.textContent = Math.round(pct) + '%';
    }
    if (tag) {
      tag.classList.remove('ok', 'warn', 'crit');
      tag.classList.add(state);
      tag.textContent = STATE_LABELS[state] || state;
    }
    if (card) {
      card.classList.remove('state-warn', 'state-crit');
      if (state !== 'ok') {
        card.classList.add('state-' + state);
      }
    }
  }

  function formatMb(mb) {
    if (mb >= 1024) {
      return (mb / 1024).toFixed(1) + ' GB';
    }
    return Math.round(mb) + ' MB';
  }

  function formatGb(gb) {
    return gb.toFixed(1) + ' GB';
  }

  function formatRateKbps(kbps) {
    if (kbps >= 1024) {
      return (kbps / 1024).toFixed(1) + ' MB/s';
    }
    return kbps.toFixed(1) + ' KB/s';
  }

  function formatBytesTotal(bytes) {
    if (bytes >= 1024 * 1024 * 1024) {
      return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    }
    if (bytes >= 1024 * 1024) {
      return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }
    if (bytes >= 1024) {
      return (bytes / 1024).toFixed(2) + ' KB';
    }
    return Math.round(bytes) + ' B';
  }

  // Acumulado desde que a pagina foi carregada — o aaPanel so expoe taxa
  // instantanea (KB/s), entao o "total" so existe integrando a taxa a cada
  // poll; nao reflete o total real do host, so o total desta sessao aberta.
  var totalUpBytes = 0;
  var totalDownBytes = 0;
  var lastSampleAt = null;

  function accumulateNetworkTotals(upKbps, downKbps) {
    var now = Date.now();
    var elapsedSec = lastSampleAt ? Math.min((now - lastSampleAt) / 1000, POLL_INTERVAL_MS / 1000 * 3) : POLL_INTERVAL_MS / 1000;
    lastSampleAt = now;

    totalUpBytes += upKbps * 1024 * elapsedSec;
    totalDownBytes += downKbps * 1024 * elapsedSec;
  }

  function worstState(states) {
    if (states.indexOf('crit') !== -1) {
      return 'crit';
    }
    if (states.indexOf('warn') !== -1) {
      return 'warn';
    }
    return 'ok';
  }

  function renderDiskOthers(others) {
    var container = document.getElementById('disk-others');
    if (!container) {
      return;
    }
    container.innerHTML = '';
    others.forEach(function (part) {
      var row = document.createElement('div');
      row.className = 'status-row';
      row.innerHTML =
        '<span class="status-dot ' + part.state + '"></span>' +
        '<span class="status-name"></span>' +
        '<span class="status-state ' + part.state + '"></span>';
      row.querySelector('.status-name').textContent = part.path;
      row.querySelector('.status-state').textContent = Math.round(part.pct) + '%';
      container.appendChild(row);
    });
  }

  function renderSecurity(security, error) {
    var tag = document.getElementById('security-mode-tag');
    var description = document.getElementById('security-description');
    if (!tag || !description) {
      return;
    }

    if (error || !security) {
      tag.classList.remove('ok', 'warn', 'crit');
      tag.classList.add('warn');
      tag.textContent = 'Indisponível';
      description.textContent = error || 'Sem leitura do Servidor.';
      lastSecurityEvents = [];
      renderSecurityNews([], error || 'sem leitura do Servidor');
      return;
    }

    var state = security.state;
    var fill = document.getElementById('security-gauge-fill');
    if (fill) {
      var offset = SECURITY_CIRCUMFERENCE * (1 - Math.max(0, Math.min(100, security.score)) / 100);
      fill.setAttribute('stroke-dashoffset', offset.toFixed(1));
      fill.style.stroke = STATE_COLOR_VAR[state] || STATE_COLOR_VAR.ok;
    }

    document.getElementById('security-score').textContent = security.score;
    document.getElementById('security-level').textContent = SECURITY_LEVEL_LABELS[security.level] || security.level || '—';

    tag.classList.remove('ok', 'warn', 'crit');
    tag.classList.add(state);
    tag.textContent = STATE_LABELS[state] || state;

    var card = document.getElementById('security-card');
    if (card) {
      card.classList.remove('state-warn', 'state-crit');
      if (state !== 'ok') {
        card.classList.add('state-' + state);
      }
    }

    document.getElementById('security-risk-count').innerHTML =
      security.riskCount + ' <span class="of">riscos encontrados</span>';
    description.textContent = security.levelDescription || 'Sem detalhes.';
    document.getElementById('security-protect-days').textContent =
      security.protectDays + (security.protectDays === 1 ? ' dia' : ' dias');
    document.getElementById('security-scan-time').textContent = security.riskScanTime || '—';
    document.getElementById('security-high').textContent = security.severity.high;
    document.getElementById('security-medium').textContent = security.severity.medium;
    document.getElementById('security-low').textContent = security.severity.low;

    lastSecurityEvents = security.events || [];
    renderSecurityNews(lastSecurityEvents, null);
  }

  var securityNewsPage = 1;
  var securityNewsPrevBtn = document.getElementById('security-news-prev');
  var securityNewsNextBtn = document.getElementById('security-news-next');
  var securityNewsPageLabel = document.getElementById('security-news-page-label');
  var securityNewsPagination = document.getElementById('security-news-pagination');

  function setPagination(totalPages) {
    if (!securityNewsPagination) {
      return;
    }
    securityNewsPagination.hidden = totalPages <= 1;
    if (securityNewsPageLabel) {
      securityNewsPageLabel.textContent = 'Página ' + securityNewsPage + ' de ' + totalPages;
    }
    if (securityNewsPrevBtn) {
      securityNewsPrevBtn.disabled = securityNewsPage <= 1;
    }
    if (securityNewsNextBtn) {
      securityNewsNextBtn.disabled = securityNewsPage >= totalPages;
    }
  }

  function renderSecurityNews(events, error) {
    var list = document.getElementById('security-news-list');
    var countEl = document.getElementById('security-news-count');
    if (!list) {
      return;
    }

    if (countEl) {
      countEl.textContent = events.length;
    }

    if (error) {
      if (securityNewsPagination) {
        securityNewsPagination.hidden = true;
      }
      list.innerHTML =
        '<div class="log-item"><div class="log-time">—</div><div class="log-badge warn">indisponível</div><div class="log-text"></div></div>';
      list.querySelector('.log-text').textContent = 'Falha ao buscar Security News: ' + error;
      return;
    }

    if (events.length === 0) {
      if (securityNewsPagination) {
        securityNewsPagination.hidden = true;
      }
      list.innerHTML =
        '<div class="log-item"><div class="log-time">—</div><div class="log-badge sys">sistema</div><div class="log-text">Nenhum risco pendente encontrado.</div></div>';
      return;
    }

    // Mais grave primeiro (high > medium > low); dentro da mesma
    // severidade, o mais recente primeiro.
    var sorted = events.slice().sort(function (a, b) {
      var pa = SECURITY_SEVERITY_PRIORITY[a.severity];
      var pb = SECURITY_SEVERITY_PRIORITY[b.severity];
      pa = pa === undefined ? 1 : pa;
      pb = pb === undefined ? 1 : pb;
      return pa !== pb ? pa - pb : (b.time || 0) - (a.time || 0);
    });

    var totalPages = Math.max(1, Math.ceil(sorted.length / SECURITY_NEWS_PAGE_SIZE));
    securityNewsPage = Math.min(Math.max(securityNewsPage, 1), totalPages);
    setPagination(totalPages);

    var start = (securityNewsPage - 1) * SECURITY_NEWS_PAGE_SIZE;
    var pageEvents = sorted.slice(start, start + SECURITY_NEWS_PAGE_SIZE);

    list.innerHTML = '';
    pageEvents.forEach(function (event) {
      var badge = SECURITY_BADGE_MAP[event.severity] || 'warn';
      var label = SECURITY_BADGE_LABEL[event.severity] || event.severity;
      var timeLabel = event.time ? formatBrDateTime(new Date(event.time * 1000)) : '—';

      var row = document.createElement('div');
      row.className = 'log-item';
      row.innerHTML =
        '<div class="log-time"></div>' +
        '<div class="log-badge ' + badge + '"></div>' +
        '<div class="log-text"></div>';
      row.querySelector('.log-time').textContent = timeLabel;
      row.querySelector('.log-badge').textContent = label;
      row.querySelector('.log-text').textContent = event.description;
      list.appendChild(row);
    });
  }

  var lastSecurityEvents = [];

  if (securityNewsPrevBtn) {
    securityNewsPrevBtn.addEventListener('click', function () {
      securityNewsPage -= 1;
      renderSecurityNews(lastSecurityEvents, null);
    });
  }
  if (securityNewsNextBtn) {
    securityNewsNextBtn.addEventListener('click', function () {
      securityNewsPage += 1;
      renderSecurityNews(lastSecurityEvents, null);
    });
  }

  function render(data) {
    errorBox.hidden = true;

    renderSecurity(data.security, data.securityError);

    var cpuState = data.cpu.state;
    var memState = data.mem.state;
    var diskState = data.disk.state;

    setGaugeState('cpu', data.cpu.pct, cpuState);
    document.getElementById('cpu-cores').textContent = data.cpu.cores ? (data.cpu.cores + ' núcleos') : '—';
    document.getElementById('cpu-load').textContent =
      data.load.one.toFixed(2) + ' / ' + data.load.five.toFixed(2) + ' / ' + data.load.fifteen.toFixed(2);

    setGaugeState('mem', data.mem.pct, memState);
    document.getElementById('mem-used').innerHTML =
      formatMb(data.mem.usedMb) + ' <span class="of">/ ' + formatMb(data.mem.totalMb) + '</span>';

    setGaugeState('disk', data.disk.pct, diskState);
    document.getElementById('disk-path').textContent = 'Partição ' + data.disk.path;
    document.getElementById('disk-used').innerHTML =
      formatGb(data.disk.usedGb) + ' <span class="of">/ ' + formatGb(data.disk.totalGb) + '</span>';
    renderDiskOthers(data.disk.others.map(function (part) {
      return Object.assign({}, part, { state: part.pct >= 90 ? 'crit' : (part.pct >= 75 ? 'warn' : 'ok') });
    }));

    document.getElementById('server-uptime').textContent = data.server.uptime;
    document.getElementById('server-os').textContent = data.server.os;
    document.getElementById('server-sites').textContent = data.server.sites;
    document.getElementById('server-databases').textContent = data.server.databases;

    document.getElementById('net-up').textContent = formatRateKbps(data.network.upKbps);
    document.getElementById('net-down').textContent = formatRateKbps(data.network.downKbps);

    accumulateNetworkTotals(data.network.upKbps, data.network.downKbps);
    document.getElementById('net-total-up').textContent = formatBytesTotal(totalUpBytes);
    document.getElementById('net-total-down').textContent = formatBytesTotal(totalDownBytes);

    if (window.netChartPush) {
      window.netChartPush(new Date(data.updatedAt), data.network.upKbps, data.network.downKbps);
    }

    var overall = worstState([cpuState, memState, diskState]);
    pill.classList.remove('ok', 'warn', 'crit');
    pill.classList.add(overall);
    pillLabel.textContent = STATE_LABELS[overall];

    lastRead.textContent = new Date(data.updatedAt).toLocaleTimeString('pt-BR');
  }

  function renderError(message) {
    pill.classList.remove('ok', 'warn');
    pill.classList.add('crit');
    pillLabel.textContent = 'Sem conexão';
    errorBox.hidden = false;
    errorBox.textContent = 'Falha ao buscar dados do host: ' + message;
  }

  // Se a config estiver errada (ex: assinatura de request invalida), cada
  // tentativa conta como uma falha de autenticacao no aaPanel — o painel
  // bane o IP depois de poucas falhas seguidas. Por isso paramos de tentar
  // sozinhos apos MAX_CONSECUTIVE_FAILURES em vez de continuar batendo nele
  // a cada 8s; o usuario precisa corrigir a config e recarregar a pagina.
  var MAX_CONSECUTIVE_FAILURES = 3;
  var consecutiveFailures = 0;
  var pollTimer = null;

  function stopPolling(message) {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
    renderError(message + ' Polling pausado pra não arriscar bloqueio de IP no painel — corrija a configuração e recarregue a página.');
  }

  function handleFailure(message) {
    consecutiveFailures += 1;
    if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
      stopPolling(message);
    } else {
      renderError(message);
    }
  }

  function poll() {
    fetch('api/host-status.php', { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.ok) {
          consecutiveFailures = 0;
          render(data);
        } else {
          handleFailure(data.error || 'erro desconhecido');
        }
      })
      .catch(function (err) {
        handleFailure(err.message || 'falha de rede');
      });
  }

  poll();
  pollTimer = setInterval(poll, POLL_INTERVAL_MS);
})();
