(function () {
  var canvas = document.getElementById('net-chart');
  if (!canvas || typeof Chart === 'undefined') {
    return;
  }

  var MAX_POINTS = 30;

  var styles = getComputedStyle(document.documentElement);
  var ok = styles.getPropertyValue('--ok').trim() || '#34d399';
  var signal = styles.getPropertyValue('--signal').trim() || '#F97316';
  var textDim = styles.getPropertyValue('--text-dim').trim() || '#5b7997';
  var textMuted = styles.getPropertyValue('--text-muted').trim() || '#9fb3c8';
  var textPrimary = styles.getPropertyValue('--text-primary').trim() || '#eef3f8';
  var lineSoft = styles.getPropertyValue('--line-soft').trim() || '#123150';
  var bgPanel = styles.getPropertyValue('--bg-panel').trim() || '#002140';

  function hexToRgba(hex, alpha) {
    var clean = hex.replace('#', '');
    var bigint = parseInt(clean, 16);
    var r = (bigint >> 16) & 255;
    var g = (bigint >> 8) & 255;
    var b = bigint & 255;
    return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
  }

  function formatTime(date) {
    return date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  var chart = new Chart(canvas, {
    type: 'line',
    data: {
      labels: [],
      datasets: [
        {
          label: 'Upstream',
          data: [],
          borderColor: ok,
          backgroundColor: hexToRgba(ok, 0.18),
          pointRadius: 0,
          borderWidth: 2,
          tension: 0.35,
          fill: true,
        },
        {
          label: 'Downstream',
          data: [],
          borderColor: signal,
          backgroundColor: hexToRgba(signal, 0.18),
          pointRadius: 0,
          borderWidth: 2,
          tension: 0.35,
          fill: true,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 250 },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: bgPanel,
          borderColor: lineSoft,
          borderWidth: 1,
          titleColor: textPrimary,
          bodyColor: textMuted,
          padding: 10,
          callbacks: {
            label: function (item) {
              return item.dataset.label + ': ' + item.formattedValue + ' KB/s';
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
          ticks: { color: textDim, font: { size: 10 } },
        },
      },
    },
  });

  window.netChartPush = function (date, upKbps, downKbps) {
    chart.data.labels.push(formatTime(date));
    chart.data.datasets[0].data.push(upKbps);
    chart.data.datasets[1].data.push(downKbps);

    if (chart.data.labels.length > MAX_POINTS) {
      chart.data.labels.shift();
      chart.data.datasets[0].data.shift();
      chart.data.datasets[1].data.shift();
    }

    chart.update('none');
  };
})();
