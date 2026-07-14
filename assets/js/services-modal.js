(function () {
  var STORAGE_KEY = 'dashstatus:servicesModalOpen';

  var overlay = document.getElementById('services-modal');
  var openBtn = document.getElementById('open-services-modal');
  var closeBtn = document.getElementById('close-services-modal');
  var modalCards = overlay ? overlay.querySelector('.modal-cards') : null;
  var cardsTrack = document.querySelector('.cards-wrap .cards');
  if (!overlay || !openBtn || !closeBtn || !modalCards || !cardsTrack) {
    return;
  }

  // Os cards são movidos (não clonados) entre o carrossel e o modal, pra
  // preservar os IDs únicos e as instâncias de Chart.js já criadas pelos
  // scripts opencage-chart.js / mapbox-chart.js.
  function openModal(persist) {
    while (cardsTrack.firstElementChild) {
      modalCards.appendChild(cardsTrack.firstElementChild);
    }
    overlay.hidden = false;
    document.body.classList.add('modal-open');
    if (persist) {
      sessionStorage.setItem(STORAGE_KEY, '1');
    }
  }

  function closeModal() {
    while (modalCards.firstElementChild) {
      cardsTrack.appendChild(modalCards.firstElementChild);
    }
    overlay.hidden = true;
    document.body.classList.remove('modal-open');
    sessionStorage.removeItem(STORAGE_KEY);
  }

  openBtn.addEventListener('click', function () { openModal(true); });
  closeBtn.addEventListener('click', closeModal);

  overlay.addEventListener('click', function (ev) {
    if (ev.target === overlay) {
      closeModal();
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !overlay.hidden) {
      closeModal();
    }
  });

  // O auto-refresh recarrega a página inteira a cada 2min; se o modal
  // estava aberto antes do reload, reabre automaticamente. Só fecha
  // quando o usuário fecha manualmente (o que limpa a flag acima).
  if (sessionStorage.getItem(STORAGE_KEY) === '1') {
    openModal(false);
  }
})();
