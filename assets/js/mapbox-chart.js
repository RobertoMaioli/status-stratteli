(function () {
  var canvas = document.getElementById('mapbox-chart');
  if (!canvas || typeof Chart === 'undefined') {
    return;
  }

  var history = JSON.parse(canvas.dataset.history || '[]');
  var lastIndex = history.length - 1;

  var styles = getComputedStyle(document.documentElement);
  var signal = styles.getPropertyValue('--signal').trim() || '#4fd1ff';
  var crit = styles.getPropertyValue('--crit').trim() || '#f87171';
  var textMuted = styles.getPropertyValue('--text-muted').trim() || '#8894a3';
  var textDim = styles.getPropertyValue('--text-dim').trim() || '#525d6b';
  var lineSoft = styles.getPropertyValue('--line-soft').trim() || '#1a222b';
  var bgPanel = styles.getPropertyValue('--bg-panel').trim() || '#12181f';
  var textPrimary = styles.getPropertyValue('--text-primary').trim() || '#e8edf2';

  function hexToRgba(hex, alpha) {
    var clean = hex.replace('#', '');
    var bigint = parseInt(clean, 16);
    var r = (bigint >> 16) & 255;
    var g = (bigint >> 8) & 255;
    var b = bigint & 255;
    return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
  }

  function formatDate(isoDate) {
    var parts = isoDate.split('-');
    return parts[2] + '/' + parts[1] + '/' + parts[0];
  }

  var labels = history.map(function (entry) {
    var parts = entry.date.split('-');
    return parts[2] + '/' + parts[1];
  });

  var values = history.map(function (entry) {
    return entry.delta;
  });

  var backgroundColors = history.map(function (entry, i) {
    if (entry.delta < 0) {
      return hexToRgba(crit, 0.6);
    }
    return i === lastIndex ? signal : hexToRgba(signal, 0.28);
  });

  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        data: values,
        backgroundColor: backgroundColors,
        borderRadius: 4,
        maxBarThickness: 22,
        categoryPercentage: 0.6,
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
              return formatDate(history[items[0].dataIndex].date);
            },
            label: function (item) {
              var entry = history[item.dataIndex];
              var sign = entry.delta >= 0 ? '+' : '';
              return sign + entry.delta.toLocaleString('pt-BR') + ' requisições (total: ' + entry.used.toLocaleString('pt-BR') + ')';
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
})();