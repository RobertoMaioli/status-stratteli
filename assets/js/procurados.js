/* Painel de Procurados — filtros, busca, ordenação, paginação e ficha lateral.
   Dependência: window.WANTED_DADOS / window.WANTED_RISCOS (embutidos pelo procurados.php
   a partir de assets/data/procurados.json, gerado por bin/import-procurados.php). */
(function () {
  const DADOS = window.WANTED_DADOS || [];
  const RISCOS = window.WANTED_RISCOS || {};
  const PAGE_SIZE = 24;

  // O painel só exibe registros com foto cadastrada — sem foto não entra na
  // grade, nos filtros nem nas contagens de categoria/risco (só no total
  // geral e no card "sem foto", que contam DADOS por inteiro).
  const VISIVEIS = DADOS.filter((p) => p.foto);

  const DEFAULTS = { cat: "todas", risco: "todos" };
  const state = Object.assign({}, DEFAULTS, { ordem: "recentes", query: "", sel: null, page: 1 });

  const $ = (s) => document.querySelector(s);
  const el = (tag, cls, txt) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (txt != null) n.textContent = txt;
    return n;
  };
  const uniq = (k) => Array.from(new Set(VISIVEIS.map((p) => p[k]).filter(Boolean)));

  function filtrados() {
    const q = state.query.trim().toLowerCase();
    let out = VISIVEIS.filter((p) =>
      (state.cat === "todas" || p.categoria === state.cat) &&
      (state.risco === "todos" || p.risco === state.risco) &&
      (!q || (p.nome + p.vulgo + p.mandado + p.categoria).toLowerCase().includes(q))
    );
    if (state.ordem === "risco") {
      const w = { altissima: 0, alta: 1, media: 2, baixa: 3, sem: 4 };
      out = out.slice().sort((a, b) => w[a.risco] - w[b.risco]);
    }
    if (state.ordem === "nome") out = out.slice().sort((a, b) => a.nome.localeCompare(b.nome, "pt-BR"));
    return out;
  }

  function setFilter(key, value) {
    state[key] = state[key] === value ? DEFAULTS[key] : value;
    state.page = 1;
    render();
  }

  function renderRows(container, key, values, countOf) {
    container.innerHTML = "";
    values.forEach((v) => {
      const b = el("button", "filter-row");
      b.type = "button";
      b.setAttribute("aria-pressed", String(state[key] === v.value));
      b.appendChild(el("span", null, v.label));
      b.appendChild(el("span", "filter-row-count", String(countOf(v.value))));
      b.addEventListener("click", () => setFilter(key, v.value));
      container.appendChild(b);
    });
  }

  function renderPills(container, key, values) {
    container.innerHTML = "";
    values.forEach(([value, label]) => {
      const b = el("button", "pill", label);
      b.type = "button";
      b.setAttribute("aria-pressed", String(state[key] === value));
      b.addEventListener("click", () => setFilter(key, value));
      container.appendChild(b);
    });
  }

  function renderSort() {
    const box = $("#wanted-sort");
    box.innerHTML = "";
    [["recentes", "Recentes"], ["risco", "Periculosidade"], ["nome", "A–Z"]].forEach(([value, label]) => {
      const b = el("button", null, label);
      b.type = "button";
      b.setAttribute("aria-pressed", String(state.ordem === value));
      if (state.ordem === value) b.classList.add("active");
      b.addEventListener("click", () => { state.ordem = value; state.page = 1; render(); });
      box.appendChild(b);
    });
  }

  function card(p) {
    const r = RISCOS[p.risco] || RISCOS.sem;
    const c = el("button", "wanted-card");
    c.type = "button";

    const photo = el("div", "wanted-photo");
    if (p.foto) {
      const img = el("img");
      img.src = p.foto; img.alt = "Foto de " + p.nome; img.loading = "lazy";
      photo.appendChild(img);
    } else {
      photo.appendChild(el("span", "wanted-photo-label", "SEM FOTO"));
    }
    const badges = el("div", "wanted-badges");
    badges.appendChild(el("span", "mode-tag " + r.cls, r.curto));
    photo.appendChild(badges);

    const body = el("div", "wanted-body");
    body.appendChild(el("h3", "wanted-name", p.nome));
    body.appendChild(el("p", "wanted-vulgo", p.vulgo));

    const tags = el("div", "wanted-tags");
    tags.appendChild(el("span", "tag", p.categoria));
    if (p.especie_prisao) tags.appendChild(el("span", "tag", p.especie_prisao));
    body.appendChild(tags);

    const foot = el("div", "wanted-foot");
    foot.appendChild(el("span", null, p.mandado));
    body.appendChild(foot);

    c.appendChild(photo);
    c.appendChild(body);
    c.addEventListener("click", () => openDrawer(p.id));
    return c;
  }

  function openDrawer(id) {
    const p = DADOS.find((x) => x.id === id);
    if (!p) return;
    state.sel = id;
    const r = RISCOS[p.risco] || RISCOS.sem;

    $("#wanted-drawer-id").textContent = p.id;
    $("#wanted-drawer-name").textContent = p.nome;
    $("#wanted-drawer-vulgo").textContent = p.vulgo;

    const photoBox = $("#wanted-drawer-photo");
    photoBox.innerHTML = "";
    if (p.foto) {
      const img = el("img");
      img.src = p.foto; img.alt = "Foto de " + p.nome;
      photoBox.appendChild(img);
    } else {
      photoBox.appendChild(el("span", "wanted-photo-label", "SEM FOTO"));
    }

    const badge = $("#wanted-drawer-risco");
    badge.className = "mode-tag " + r.cls;
    badge.textContent = r.label;

    const tags = $("#wanted-drawer-tags");
    tags.innerHTML = "";
    (p.tags || []).forEach((t) => tags.appendChild(el("span", "tag", t)));

    const fields = $("#wanted-drawer-fields");
    fields.innerHTML = "";
    [
      ["SITUAÇÃO", p.situacao],
      ["CLASSIFICAÇÃO", p.categoria],
      ["TIPO DE PRISÃO", p.especie_prisao || "Não informado"],
      ["IDADE", p.idade ? p.idade + " anos" : "Não informada"],
      ["DATA DE EXPEDIÇÃO", p.expedicao || "Não informada"],
      ["MANDADO", p.mandado],
      ["COMARCA / VARA", p.vara || "Não informada"]
    ].forEach(([k, v]) => {
      const w = el("div", "wanted-field");
      w.appendChild(el("dt", null, k));
      w.appendChild(el("dd", null, v));
      fields.appendChild(w);
    });

    $("#wanted-drawer-overlay").hidden = false;
    document.body.style.overflow = "hidden";
    $("#wanted-drawer-close").focus();
  }

  function closeDrawer() {
    state.sel = null;
    $("#wanted-drawer-overlay").hidden = true;
    document.body.style.overflow = "";
  }

  function renderPagination(total) {
    const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    if (state.page > totalPages) state.page = totalPages;
    const box = $("#wanted-pagination");
    box.hidden = totalPages <= 1;
    $("#wanted-page-label").textContent = "Página " + state.page + " de " + totalPages;
    $("#wanted-page-prev").disabled = state.page <= 1;
    $("#wanted-page-next").disabled = state.page >= totalPages;
  }

  function render() {
    const lista = filtrados();

    $("#wanted-stat-total").textContent = DADOS.length;
    $("#wanted-stat-alta").textContent = VISIVEIS.filter((p) => p.risco === "alta" || p.risco === "altissima").length;
    $("#wanted-stat-foto").textContent = DADOS.length - VISIVEIS.length;

    renderRows(
      $("#wanted-filter-categoria"), "cat",
      [{ value: "todas", label: "Todas as categorias" }].concat(uniq("categoria").sort().map((c) => ({ value: c, label: c }))),
      (v) => (v === "todas" ? VISIVEIS.length : VISIVEIS.filter((p) => p.categoria === v).length)
    );
    renderPills($("#wanted-filter-risco"), "risco", [["todos", "Todas"], ["altissima", "Altíssima"], ["alta", "Alta"], ["media", "Média"], ["baixa", "Baixa"], ["sem", "Sem risco"]]);
    renderSort();

    $("#wanted-result-count").textContent = lista.length + (lista.length === 1 ? " REGISTRO ENCONTRADO" : " REGISTROS ENCONTRADOS");

    const start = (state.page - 1) * PAGE_SIZE;
    const pagina = lista.slice(start, start + PAGE_SIZE);

    const grid = $("#wanted-grid");
    grid.innerHTML = "";
    pagina.forEach((p) => grid.appendChild(card(p)));
    $("#wanted-empty").hidden = lista.length !== 0;
    renderPagination(lista.length);
  }

  $("#wanted-search").addEventListener("input", (e) => { state.query = e.target.value; state.page = 1; render(); });
  $("#wanted-clear-filters").addEventListener("click", () => {
    Object.assign(state, DEFAULTS, { query: "", page: 1 });
    $("#wanted-search").value = "";
    render();
  });
  $("#wanted-page-prev").addEventListener("click", () => { state.page--; render(); window.scrollTo({ top: 0, behavior: "smooth" }); });
  $("#wanted-page-next").addEventListener("click", () => { state.page++; render(); window.scrollTo({ top: 0, behavior: "smooth" }); });
  $("#wanted-drawer-close").addEventListener("click", closeDrawer);
  $("#wanted-drawer-overlay").addEventListener("click", (e) => { if (e.target.id === "wanted-drawer-overlay") closeDrawer(); });
  document.addEventListener("keydown", (e) => { if (e.key === "Escape" && state.sel) closeDrawer(); });

  render();
})();
