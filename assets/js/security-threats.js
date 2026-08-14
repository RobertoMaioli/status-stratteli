(function () {
  var summaryEl = document.getElementById('threat-summary');
  var POLL_INTERVAL_MS = parseInt((summaryEl && summaryEl.dataset.pollInterval) || '30000', 10);
  var EVENTS_PAGE_SIZE = 10;
  var MAX_SCENARIO_BARS = 8;

  var errorBox = document.getElementById('threat-error');
  var pill = document.getElementById('threat-pill');
  var pillLabel = document.getElementById('threat-pill-label');
  var lastRead = document.getElementById('threat-last-read');

  var styles = getComputedStyle(document.documentElement);
  var signal = styles.getPropertyValue('--signal').trim() || '#F97316';
  var crit = styles.getPropertyValue('--crit').trim() || '#f87171';
  var textMuted = styles.getPropertyValue('--text-muted').trim() || '#8894a3';
  var textDim = styles.getPropertyValue('--text-dim').trim() || '#525d6b';
  var lineSoft = styles.getPropertyValue('--line-soft').trim() || '#1a222b';
  var bgPanel = styles.getPropertyValue('--bg-panel').trim() || '#12181f';
  var textPrimary = styles.getPropertyValue('--text-primary').trim() || '#e8edf2';

  function pad2(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function formatBrDateTime(date) {
    return pad2(date.getDate()) + '/' + pad2(date.getMonth() + 1) +
      ' ' + pad2(date.getHours()) + ':' + pad2(date.getMinutes());
  }

  // ---------- MAPA ----------
  var map = null;
  var markerLayer = null;
  var mapEl = document.getElementById('threat-map');
  var serverLat = mapEl ? parseFloat(mapEl.dataset.serverLat) : NaN;
  var serverLng = mapEl ? parseFloat(mapEl.dataset.serverLng) : NaN;
  var hasServerLocation = !isNaN(serverLat) && !isNaN(serverLng);
  var seenEventIds = {};
  var firstMapRender = true;
  var ok = styles.getPropertyValue('--ok').trim() || '#34d399';

  if (mapEl && typeof L !== 'undefined') {
    map = L.map(mapEl, { worldCopyJump: true }).setView([20, 0], 2);
    // CARTO "dark matter" — mesmos dados do OpenStreetMap, estilo escuro
    // pronto (sem precisar de filtro CSS gambiarra em cima do tile claro).
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
      subdomains: 'abcd',
      maxZoom: 19,
    }).addTo(map);
    markerLayer = L.layerGroup().addTo(map);

    if (hasServerLocation) {
      L.circleMarker([serverLat, serverLng], {
        radius: 8,
        color: ok,
        fillColor: ok,
        fillOpacity: 0.9,
        weight: 2,
        className: 'threat-server-marker',
      })
        .bindPopup('<b>Nosso servidor</b>')
        .addTo(map);
    }
  }

  // Linha + pulso animados do IP atacante ate o servidor — só disparado pra
  // eventos que ainda nao tinham aparecido em nenhum poll anterior (ver
  // seenEventIds), pra dar a sensacao de "ataque ao vivo" sem lotar o mapa
  // de linha permanente pra cada alerta ja conhecido.
  function spawnAttackAnimation(event) {
    if (!map || !hasServerLocation) {
      return;
    }

    var line = L.polyline([[event.lat, event.lng], [serverLat, serverLng]], {
      color: crit,
      weight: 1.6,
      opacity: 0.85,
      className: 'threat-attack-line',
    }).addTo(map);

    var pulse = L.circleMarker([event.lat, event.lng], {
      radius: 6,
      color: crit,
      weight: 2,
      fill: false,
      className: 'threat-attack-pulse',
    }).addTo(map);

    setTimeout(function () {
      map.removeLayer(line);
      map.removeLayer(pulse);
    }, 2500);
  }

  function renderMap(events) {
    if (!map || !markerLayer) {
      return;
    }
    markerLayer.clearLayers();

    events.forEach(function (event) {
      if (typeof event.lat !== 'number' || typeof event.lng !== 'number') {
        return;
      }

      L.circleMarker([event.lat, event.lng], {
        radius: 6,
        color: crit,
        fillColor: crit,
        fillOpacity: 0.55,
        weight: 1,
      })
        .bindPopup(
          '<b>' + (event.ip || '—') + '</b><br>' +
          (event.scenarioLabel || event.scenario || '—') + '<br>' +
          (event.country || '—') + (event.asName ? ' · ' + event.asName : '')
        )
        .addTo(markerLayer);

      // No primeiro carregamento da pagina, so registra o que ja existe
      // (sem animar tudo de uma vez) — a animacao acontece a partir do
      // segundo poll, só pro que for genuinamente novo.
      var key = event.id || (event.ip + '|' + event.createdAt);
      if (!seenEventIds[key]) {
        seenEventIds[key] = true;
        if (!firstMapRender) {
          spawnAttackAnimation(event);
        }
      }
    });

    firstMapRender = false;
  }

  // ---------- GRAFICOS ----------
  var scenarioChart = null;
  var timelineChart = null;

  function initCharts() {
    var scenarioCanvas = document.getElementById('threat-scenario-chart');
    var timelineCanvas = document.getElementById('threat-timeline-chart');
    if (typeof Chart === 'undefined') {
      return;
    }

    if (scenarioCanvas) {
      scenarioChart = new Chart(scenarioCanvas, {
        type: 'bar',
        data: { labels: [], datasets: [{ data: [], backgroundColor: signal, borderRadius: 4, maxBarThickness: 26 }] },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          indexAxis: 'y',
          plugins: { legend: { display: false } },
          scales: {
            x: { beginAtZero: true, grid: { color: lineSoft }, ticks: { color: textDim, font: { size: 10 }, precision: 0 } },
            y: { grid: { display: false }, ticks: { color: textMuted, font: { size: 10.5 } } },
          },
        },
      });
    }

    if (timelineCanvas) {
      timelineChart = new Chart(timelineCanvas, {
        type: 'line',
        data: {
          labels: [],
          datasets: [{
            data: [], borderColor: signal, backgroundColor: 'rgba(249,115,22,0.15)',
            fill: true, tension: 0.3, pointRadius: 2,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { grid: { display: false }, ticks: { color: textDim, font: { size: 10 }, maxRotation: 0, autoSkip: true } },
            y: { beginAtZero: true, grid: { color: lineSoft }, ticks: { color: textDim, font: { size: 10 }, precision: 0 } },
          },
        },
      });
    }
  }

  function formatBucketLabel(bucket) {
    // bucket vem como "2026-08-14T13:00"
    var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):/.exec(bucket);
    if (!m) {
      return bucket;
    }
    return m[3] + '/' + m[2] + ' ' + m[4] + 'h';
  }

  function renderCharts(byScenario, timeseries) {
    if (scenarioChart) {
      var top = byScenario.slice(0, MAX_SCENARIO_BARS);
      var restCount = byScenario.slice(MAX_SCENARIO_BARS).reduce(function (sum, s) { return sum + s.count; }, 0);
      var labels = top.map(function (s) { return s.label; });
      var values = top.map(function (s) { return s.count; });
      if (restCount > 0) {
        labels.push('Outros');
        values.push(restCount);
      }
      scenarioChart.data.labels = labels;
      scenarioChart.data.datasets[0].data = values;
      scenarioChart.update();
    }

    if (timelineChart) {
      timelineChart.data.labels = timeseries.map(function (t) { return formatBucketLabel(t.bucket); });
      timelineChart.data.datasets[0].data = timeseries.map(function (t) { return t.count; });
      timelineChart.update();
    }
  }

  // ---------- TOP PAISES ----------
  function renderCountries(byCountry) {
    var container = document.getElementById('threat-country-list');
    if (!container) {
      return;
    }
    container.innerHTML = '';

    if (byCountry.length === 0) {
      container.innerHTML = '<div class="status-row"><span class="status-name">Nenhum país registrado ainda.</span></div>';
      return;
    }

    byCountry.slice(0, 10).forEach(function (entry) {
      var row = document.createElement('div');
      row.className = 'status-row';
      row.innerHTML =
        '<span class="status-dot ok"></span>' +
        '<span class="status-name"></span>' +
        '<span class="status-state ok"></span>';
      row.querySelector('.status-name').textContent = entry.country || '—';
      row.querySelector('.status-state').textContent = entry.count;
      container.appendChild(row);
    });
  }

  // ---------- TABELA DE EVENTOS (paginada) ----------
  var eventsPage = 1;
  var lastEvents = [];
  var eventsPrevBtn = document.getElementById('threat-events-prev');
  var eventsNextBtn = document.getElementById('threat-events-next');
  var eventsPageLabel = document.getElementById('threat-events-page-label');
  var eventsPagination = document.getElementById('threat-events-pagination');

  function setEventsPagination(totalPages) {
    if (!eventsPagination) {
      return;
    }
    eventsPagination.hidden = totalPages <= 1;
    if (eventsPageLabel) {
      eventsPageLabel.textContent = 'Página ' + eventsPage + ' de ' + totalPages;
    }
    if (eventsPrevBtn) {
      eventsPrevBtn.disabled = eventsPage <= 1;
    }
    if (eventsNextBtn) {
      eventsNextBtn.disabled = eventsPage >= totalPages;
    }
  }

  function renderEvents(events, error) {
    var list = document.getElementById('threat-events-list');
    var countEl = document.getElementById('threat-events-count');
    if (!list) {
      return;
    }

    if (countEl) {
      countEl.textContent = events.length;
    }

    if (error) {
      if (eventsPagination) {
        eventsPagination.hidden = true;
      }
      list.innerHTML = '<div class="log-item"><div class="log-time">—</div><div class="log-badge warn">indisponível</div><div class="log-text"></div></div>';
      list.querySelector('.log-text').textContent = 'Falha ao buscar alertas do CrowdSec: ' + error;
      return;
    }

    if (events.length === 0) {
      if (eventsPagination) {
        eventsPagination.hidden = true;
      }
      list.innerHTML = '<div class="log-item"><div class="log-time">—</div><div class="log-badge sys">sistema</div><div class="log-text">Nenhum alerta registrado.</div></div>';
      return;
    }

    var totalPages = Math.max(1, Math.ceil(events.length / EVENTS_PAGE_SIZE));
    eventsPage = Math.min(Math.max(eventsPage, 1), totalPages);
    setEventsPagination(totalPages);

    var start = (eventsPage - 1) * EVENTS_PAGE_SIZE;
    var pageEvents = events.slice(start, start + EVENTS_PAGE_SIZE);

    list.innerHTML = '';
    pageEvents.forEach(function (event) {
      var timeLabel = event.createdAt ? formatBrDateTime(new Date(event.createdAt)) : '—';

      var row = document.createElement('div');
      row.className = 'log-item';
      row.innerHTML =
        '<div class="log-time"></div>' +
        '<div class="log-badge sys"></div>' +
        '<div class="log-text"></div>';
      row.querySelector('.log-time').textContent = timeLabel;
      row.querySelector('.log-badge').textContent = event.country || '—';

      var text = row.querySelector('.log-text');
      var ipEl = document.createElement('b');
      ipEl.textContent = event.ip || '—';
      text.appendChild(ipEl);
      text.appendChild(document.createTextNode(
        ' — ' + (event.scenarioLabel || '—') + (event.banDuration ? ' · ban ' + event.banDuration : '')
      ));

      list.appendChild(row);
    });
  }

  if (eventsPrevBtn) {
    eventsPrevBtn.addEventListener('click', function () {
      eventsPage -= 1;
      renderEvents(lastEvents, null);
    });
  }
  if (eventsNextBtn) {
    eventsNextBtn.addEventListener('click', function () {
      eventsPage += 1;
      renderEvents(lastEvents, null);
    });
  }

  // ---------- POLLING ----------
  function render(data) {
    errorBox.hidden = true;

    document.getElementById('threat-total-alerts').textContent = data.totals.alerts;
    document.getElementById('threat-total-events').textContent = data.totals.events;
    document.getElementById('threat-total-countries').textContent = data.totals.countries;
    document.getElementById('threat-total-ips').textContent = data.totals.uniqueIps;

    renderMap(data.events);
    renderCharts(data.byScenario, data.timeseries);
    renderCountries(data.byCountry);

    lastEvents = data.events;
    renderEvents(lastEvents, null);

    pill.classList.remove('warn', 'crit');
    pill.classList.add('ok');
    pillLabel.textContent = 'Monitorando';

    if (data.updatedAt) {
      lastRead.textContent = new Date(data.updatedAt).toLocaleTimeString('pt-BR');
    }
  }

  function renderError(message) {
    pill.classList.remove('ok', 'warn');
    pill.classList.add('crit');
    pillLabel.textContent = 'Sem conexão';
    errorBox.hidden = false;
    errorBox.textContent = 'Falha ao buscar dados do CrowdSec: ' + message;
    renderEvents([], message);
  }

  // Mesmo motivo do host-monitor: se a config estiver errada, nao adianta
  // continuar tentando logar na LAPI a cada intervalo indefinidamente —
  // para depois de algumas falhas seguidas e deixa o erro visivel.
  var MAX_CONSECUTIVE_FAILURES = 3;
  var consecutiveFailures = 0;
  var pollTimer = null;

  function stopPolling(message) {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
    renderError(message + ' Polling pausado — corrija a configuração e recarregue a página.');
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
    fetch('api/security-threats.php', { credentials: 'same-origin' })
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

  initCharts();
  poll();
  pollTimer = setInterval(poll, POLL_INTERVAL_MS);
})();
