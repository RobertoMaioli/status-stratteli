(function () {
  var wrap = document.querySelector('.cards-wrap');
  var track = wrap ? wrap.querySelector('.cards') : null;
  if (!wrap || !track) {
    return;
  }

  var leftBtn = wrap.querySelector('.cards-arrow-left');
  var rightBtn = wrap.querySelector('.cards-arrow-right');

  function updateArrows() {
    var maxScroll = track.scrollWidth - track.clientWidth;
    leftBtn.classList.toggle('is-active', track.scrollLeft > 4);
    rightBtn.classList.toggle('is-active', track.scrollLeft < maxScroll - 4);
  }

  function scrollByCard(dir) {
    var card = track.querySelector('.card');
    var amount = card ? card.getBoundingClientRect().width + 18 : track.clientWidth * 0.8;
    track.scrollBy({ left: dir * amount, behavior: 'smooth' });
  }

  leftBtn.addEventListener('click', function () { scrollByCard(-1); });
  rightBtn.addEventListener('click', function () { scrollByCard(1); });
  track.addEventListener('scroll', updateArrows);
  window.addEventListener('resize', updateArrows);
  track.addEventListener('dragstart', function (ev) { ev.preventDefault(); });

  // Arrasto com o mouse (clique e arraste pra rolar) — toque/trackpad já
  // funcionam nativamente via overflow-x, isso só cobre o caso do mouse.
  var isDown = false;
  var startX = 0;
  var scrollStart = 0;
  var moved = 0;

  track.addEventListener('mousedown', function (ev) {
    isDown = true;
    moved = 0;
    startX = ev.pageX;
    scrollStart = track.scrollLeft;
    track.classList.add('dragging');
  });

  window.addEventListener('mouseup', function () {
    if (!isDown) {
      return;
    }
    isDown = false;
    track.classList.remove('dragging');
  });

  window.addEventListener('mousemove', function (ev) {
    if (!isDown) {
      return;
    }
    ev.preventDefault();
    var dx = ev.pageX - startX;
    moved = Math.max(moved, Math.abs(dx));
    track.scrollLeft = scrollStart - dx;
  });

  // Se o mouse se moveu o suficiente pra contar como arrasto, suprime o
  // click que o navegador dispara ao soltar — evita ativar sem querer um
  // link/botão do card (ex: "Atualizar uso →") ao só arrastar o carrossel.
  track.addEventListener('click', function (ev) {
    if (moved > 6) {
      ev.preventDefault();
      ev.stopPropagation();
    }
  }, true);

  updateArrows();
})();
