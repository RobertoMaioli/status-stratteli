(function () {
  var toggle = document.getElementById('password-toggle');
  var input = document.getElementById('login-password');
  if (!toggle || !input) {
    return;
  }

  toggle.addEventListener('click', function () {
    var willShow = input.type === 'password';
    input.type = willShow ? 'text' : 'password';
    toggle.classList.toggle('active', willShow);
    toggle.setAttribute('aria-pressed', String(willShow));
    toggle.setAttribute('aria-label', willShow ? 'Ocultar senha' : 'Mostrar senha');
  });
})();
