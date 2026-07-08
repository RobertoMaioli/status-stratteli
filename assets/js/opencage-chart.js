(function () {
  var canvas = document.getElementById('opencage-chart');
  if (!canvas || typeof Chart === 'undefined') {
    return;
  }

  var fullHistory = JSON.parse(canvas.dataset.history || '[]');
  var labelEl = document.getElementById('opencage-chart-label');
  var filterEl = document.querySelector('[data-chart-filter="opencage-chart"]');

  var styles = getComputedStyle(document.documentElement);
  var signal = styles.getPropertyValue('--signal').trim() || '#F97316';
  var textMuted = styles.getPropertyValue('--text-muted').trim() || '#8894a3';
  var textDim = styles.getPropertyValue('--text-dim').trim() || '#525d6b';
  var lineSoft = styles.getPropertyValue('--line-soft').trim() || '#1a222b';
  var bgPanel = styles.getPropertyValue('--bg-panel').trim() || '#12181f';
  var textPrimary = styles.getPropertyValue('--text-primary').trim() || '#e8edf2';

  var MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

  function hexToRgba(hex, alpha) {
    var clean = hex.replace('#', '');
    var bigint = parseInt(clean, 16);
    var r = (bigint >> 16) & 255;
    var g = (bigint >> 8) & 255;
    var b = bigint & 255;
    return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
  }

  function formatDayLabel(iso) {
    var p = iso.split('-');
    return p[2] + '/' + p[1];
  }
  function formatDayTitle(iso) {
    var p = iso.split('-');
    return p[2] + '/' + p[1] + '/' + p[0];
  }
  function formatMonthLabel(key) {
    var p = key.split('-');
    return MONTHS[parseInt(p[1], 10) - 1] + '/' + p[0].slice(2);
  }
  function formatMonthTitle(key) {
    var p = key.split('-');
    return MONTHS[parseInt(p[1], 10) - 1] + '/' + p[0];
  }

  function buildView(range) {
    if (range === 'month' || range === 'year') {
      var keyLength = range === 'month' ? 7 : 4;
      var totals = {};
      var order = [];

      fullHistory.forEach(function (day) {
        var key = day.date.slice(0, keyLength);
        if (!(key in totals)) {
          totals[key] = 0;
          order.push(key);
        }
        totals[key] += day.total;
      });

      if (range === 'month') {
        order = order.slice(-6);
      }

      return {
        entries: order.map(function (key) { return { key: key, value: totals[key] }; }),
        labelFor: range === 'month' ? formatMonthLabel : function (key) { return key; },
        titleFor: range === 'month' ? formatMonthTitle : function (key) { return key; },
        caption: range === 'month'
          ? ('Uso mensal — últimos ' + order.length + ' meses')
          : ('Uso anual — últimos ' + order.length + ' anos'),
      };
    }

    var days = fullHistory.slice(-30);
    return {
      entries: days.map(function (day) { return { key: day.date, value: day.total }; }),
      labelFor: formatDayLabel,
      titleFor: formatDayTitle,
      caption: 'Uso diário — últimos ' + days.length + ' dias',
    };
  }

  var currentView = null;

  var chart = new Chart(canvas, {
    type: 'bar',
    data: {
      labels: [],
      datasets: [{
        data: [],
        backgroundColor: [],
        borderRadius: 4,
        maxBarThickness: 14,
        categoryPercentage: 0.7,
        barPercentage: 0.9,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'nearest', intersect: true },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: bgPanel,
          borderColor: lineSoft,
          borderWidth: 1,
          titleColor: textPrimary,
          bodyColor: textMuted,
          padding: 10,
          displayColors: false,
          callbacks: {
            title: function (items) {
              var entry = currentView.entries[items[0].dataIndex];
              return currentView.titleFor(entry.key);
            },
            label: function (item) {
              return item.raw.toLocaleString('pt-BR') + ' requisições';
            },
          },
        },
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: textDim, autoSkip: true, maxRotation: 0, font: { size: 10 } },
        },
        y: {
          beginAtZero: true,
          grid: { color: lineSoft },
          border: { display: false },
          ticks: { color: textDim, font: { size: 10 }, precision: 0 },
        },
      },
    },
  });

  function render(range) {
    currentView = buildView(range);
    var lastIndex = currentView.entries.length - 1;

    chart.data.labels = currentView.entries.map(function (e) { return currentView.labelFor(e.key); });
    chart.data.datasets[0].data = currentView.entries.map(function (e) { return e.value; });
    chart.data.datasets[0].backgroundColor = currentView.entries.map(function (_, i) {
      return i === lastIndex ? signal : hexToRgba(signal, 0.28);
    });
    chart.update();

    if (labelEl) {
      labelEl.textContent = currentView.caption;
    }
  }

  if (filterEl) {
    filterEl.addEventListener('click', function (ev) {
      var btn = ev.target.closest('button[data-range]');
      if (!btn) {
        return;
      }
      filterEl.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      render(btn.dataset.range);
    });
  }

  render('day');
})();
