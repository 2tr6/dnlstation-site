/* DNL Station — main.js */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fmtTL = function (n) { return n.toLocaleString('tr-TR'); };
  var pad = function (n) { return String(n).padStart(2, '0'); };
  var hms = function (s) { s = Math.max(0, Math.floor(s)); return Math.floor(s / 3600) + ':' + pad(Math.floor(s % 3600 / 60)) + ':' + pad(s % 60); };

  /* ---------- Mobile menu ---------- */
  var hb = $('.hb'), menu = $('#mmenu'), html = document.documentElement, body = document.body, lockY = 0;
  function jumpTo(y) {
    var prev = html.style.scrollBehavior;
    html.style.scrollBehavior = 'auto';
    window.scrollTo(0, y);
    html.style.scrollBehavior = prev;
  }
  function lockScroll() {
    lockY = window.scrollY || window.pageYOffset || 0;
    body.style.top = -lockY + 'px';
    html.classList.add('menu-open');
  }
  function unlockScroll() {
    html.classList.remove('menu-open');
    body.style.top = '';
    jumpTo(lockY);
  }
  function isOpen() { return menu && !menu.hidden; }
  function setMenu(open) {
    if (!hb || !menu || open === isOpen()) return;
    menu.hidden = !open;
    hb.setAttribute('aria-expanded', open ? 'true' : 'false');
    hb.setAttribute('aria-label', open ? 'Menüyü kapat' : 'Menüyü aç');
    if (open) lockScroll(); else unlockScroll();
  }
  if (hb && menu) {
    hb.addEventListener('click', function () { setMenu(!isOpen()); });
    // Menü linkleri: önce kilidi aç, sonra hedefe kaydır
    $$('a', menu).forEach(function (a) {
      a.addEventListener('click', function (e) {
        var href = a.getAttribute('href') || '';
        if (href.charAt(0) !== '#') { setMenu(false); return; }
        var target = document.querySelector(href);
        e.preventDefault();
        setMenu(false);
        if (target) {
          requestAnimationFrame(function () {
            target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
            if (history.replaceState) history.replaceState(null, '', href);
          });
        }
      });
    });
    // Klavye: Esc kapatır, Tab menü içinde döner
    document.addEventListener('keydown', function (e) {
      if (!isOpen()) return;
      if (e.key === 'Escape') { setMenu(false); hb.focus(); return; }
      if (e.key === 'Tab') {
        var items = [hb].concat($$('a, button', menu));
        var first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
    window.addEventListener('resize', function () { if (window.innerWidth > 900) setMenu(false); });
    window.addEventListener('pageshow', function () { if (isOpen()) setMenu(false); });
  }

  /* ---------- Panel gallery ---------- */
  var gal = $('[data-gallery]');
  if (gal) {
    var tabs = $$('[role="tab"]', gal), imgs = $$('.shot-view img', gal), cap = $('.shot-cap', gal), gi = 0, auto = null;
    var mobileMq = window.matchMedia('(max-width: 760px)');
    var onMq = function () { show(gi); };
    if (mobileMq.addEventListener) mobileMq.addEventListener('change', onMq); else if (mobileMq.addListener) mobileMq.addListener(onMq);
    function show(i, focus) {
      gi = (i + tabs.length) % tabs.length;
      tabs.forEach(function (t, k) { t.setAttribute('aria-selected', k === gi ? 'true' : 'false'); t.tabIndex = k === gi ? 0 : -1; });
      imgs.forEach(function (im, k) { im.classList.toggle('on', k === gi); });
      if (cap) cap.innerHTML = (mobileMq.matches && tabs[gi].getAttribute('data-cap-m')) || tabs[gi].getAttribute('data-cap');
      if (focus) tabs[gi].focus();
    }
    function stopAuto() { if (auto) { clearInterval(auto); auto = null; } }
    tabs.forEach(function (t, k) {
      t.addEventListener('click', function () { stopAuto(); show(k); });
      t.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight') { e.preventDefault(); stopAuto(); show(gi + 1, true); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); stopAuto(); show(gi - 1, true); }
      });
    });
    show(0);
    // Görsele tıklayınca tam boy aç
    var view = $('.shot-view', gal);
    if (view) view.addEventListener('click', function () {
      stopAuto(); gal.setAttribute('data-touched', '1');
      var on = $('img.on', view); if (on) window.open(on.currentSrc || on.src, '_blank', 'noopener');
    });
    if (!reduce && 'IntersectionObserver' in window) {
      new IntersectionObserver(function (en) {
        en.forEach(function (x) {
          if (x.isIntersecting && !auto && gal.getAttribute('data-touched') !== '1') auto = setInterval(function () { show(gi + 1); }, 5000);
          if (!x.isIntersecting) stopAuto();
        });
      }, { threshold: 0.35 }).observe(gal);
      gal.addEventListener('pointerdown', function () { gal.setAttribute('data-touched', '1'); stopAuto(); });
    }
  }

  /* ---------- Rotating hero word ---------- */
  var word = $('#word');
  if (word && !reduce) {
    var words = ['PlayStation', 'Bilardo', 'Okey', 'Oyun'], wi = 0;
    setInterval(function () {
      wi = (wi + 1) % words.length;
      word.classList.remove('swap'); void word.offsetWidth;
      word.textContent = words[wi];
      word.classList.add('swap');
    }, 3000);
  }

  /* ---------- Pixel field ---------- */
  var field = $('#pixfield');
  if (field) {
    var frag = document.createDocumentFragment(), seed = 7;
    var rnd = function () { seed = (seed * 9301 + 49297) % 233280; return seed / 233280; };
    for (var i = 0; i < 40 * 14; i++) {
      var sp = document.createElement('span');
      if (rnd() > 0.86) sp.className = 'c';
      sp.style.setProperty('--d', (4 + rnd() * 6).toFixed(1) + 's');
      sp.style.setProperty('--dl', (-rnd() * 10).toFixed(1) + 's');
      frag.appendChild(sp);
    }
    field.appendChild(frag);
  }

  /* ---------- Pixel D logo ---------- */
  var rows = { 0: [8], 1: [0,1,2,3,4,5], 2: [0,1,2,3,4,5,6,7], 3: [0,1,6,7,8], 4: [0,1,7,8], 5: [0,1,7,8], 6: [0,1,7,8], 7: [0,1,7,8], 8: [0,1,7,8], 9: [0,1,6,7,8], 10: [0,1,2,3,4,5,6,7], 11: [0,1,2,3,4,5] };
  var lerp = function (a, b, k) { return Math.round(a + (b - a) * k); };
  $$('[data-pixel-d]').forEach(function (box) {
    var f = document.createDocumentFragment();
    for (var r = 0; r < 12; r++) {
      for (var c = 0; c < 9; c++) {
        var s = document.createElement('span'), on = rows[r].indexOf(c) !== -1, cy = r === 0 && c === 8, k = (r + c) / 19;
        if (on) {
          s.className = 'on';
          s.style.background = cy ? '#2ED3E6' : 'rgb(' + lerp(184, 98, k) + ',' + lerp(170, 66, k) + ',' + lerp(252, 226, k) + ')';
          s.style.animationDelay = ((r + c) * 0.12).toFixed(2) + 's';
        }
        f.appendChild(s);
      }
    }
    box.appendChild(f);
  });

  /* ---------- Live station timers ---------- */
  var stations = $$('.st[data-start], .st[data-left]');
  var revenueEl = $('#revenue');
  var minis = $$('[data-mini-start], [data-mini-left]');
  var t0 = Date.now();
  function tick() {
    var t = Math.floor((Date.now() - t0) / 1000), live = 0;
    stations.forEach(function (st) {
      var timeEl = $('.st-t', st), subEl = $('.st-s', st);
      if (st.hasAttribute('data-left')) {
        var left = Math.max(0, +st.getAttribute('data-left') - t);
        timeEl.textContent = '-' + hms(left).slice(2);
        return;
      }
      var el = +st.getAttribute('data-start') + t, rate = +st.getAttribute('data-rate'), kol = st.getAttribute('data-controllers');
      var amt = Math.round(el / 3600 * rate); live += amt;
      timeEl.textContent = hms(el);
      subEl.textContent = (kol ? kol + ' kol · ' : 'Saatlik · ') + '₺' + amt;
    });
    if (revenueEl) revenueEl.textContent = fmtTL(3720 + live);
    minis.forEach(function (m) {
      if (m.hasAttribute('data-mini-left')) m.textContent = '-' + hms(Math.max(0, +m.getAttribute('data-mini-left') - t)).slice(2);
      else m.textContent = hms(+m.getAttribute('data-mini-start') + t).slice(0, -3);
    });
  }
  if (stations.length || minis.length) { tick(); setInterval(tick, 1000); }

  /* ---------- Tariff calculator ---------- */
  var tariff = $('#tariff');
  if (tariff) {
    var T = { hour: { 2: 100, 4: 160 }, match: { 2: 30, 4: 45 } };
    var state = { kol: 4, mode: 'hour', qty: 2 };
    var qtyBox = $('#qty', tariff), totalEl = $('#total', tariff), bdEl = $('#breakdown', tariff), qtyLbl = $('#l-qty', tariff);
    function renderQty() {
      var list = state.mode === 'hour' ? [1, 2, 3, 4] : [1, 3, 5, 10];
      qtyLbl.textContent = state.mode === 'hour' ? 'Süre' : 'Maç sayısı';
      qtyBox.innerHTML = '';
      list.forEach(function (q) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'opt mono';
        b.textContent = q + (state.mode === 'hour' ? ' sa' : ' maç');
        b.setAttribute('aria-pressed', q === state.qty ? 'true' : 'false');
        b.addEventListener('click', function () { state.qty = q; update(); });
        qtyBox.appendChild(b);
      });
    }
    function update() {
      var unit = T[state.mode][state.kol];
      $$('[data-kol]', tariff).forEach(function (b) { b.setAttribute('aria-pressed', +b.getAttribute('data-kol') === state.kol ? 'true' : 'false'); });
      $$('[data-mode]', tariff).forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-mode') === state.mode ? 'true' : 'false'); });
      renderQty();
      totalEl.textContent = '₺' + fmtTL(unit * state.qty);
      totalEl.classList.remove('pop'); void totalEl.offsetWidth; totalEl.classList.add('pop');
      bdEl.textContent = state.kol + ' kol · ₺' + unit + (state.mode === 'hour' ? '/sa' : '/maç') + ' × ' + state.qty;
    }
    $$('[data-kol]', tariff).forEach(function (b) { b.addEventListener('click', function () { state.kol = +b.getAttribute('data-kol'); update(); }); });
    $$('[data-mode]', tariff).forEach(function (b) {
      b.addEventListener('click', function () {
        var m = b.getAttribute('data-mode');
        if (m !== state.mode) { state.mode = m; state.qty = m === 'hour' ? 2 : 3; }
        update();
      });
    });
    update();
  }

  /* ---------- Billing toggle ---------- */
  var billBtns = $$('[data-bill]');
  function setBill(y) {
    billBtns.forEach(function (b) { b.setAttribute('aria-pressed', (b.getAttribute('data-bill') === 'y') === y ? 'true' : 'false'); });
    $$('.plan[data-m]').forEach(function (p) {
      var m = +p.getAttribute('data-m'), yr = +p.getAttribute('data-y');
      var priceEl = $('.price strong', p), perEl = $('.price span', p), noteEl = $('.price-note', p);
      priceEl.textContent = '₺' + fmtTL(y ? yr : m);
      perEl.textContent = y ? '/ yıl' : '/ ay';
      noteEl.textContent = y ? 'Aylık ₺' + Math.round(yr / 12) + ' · 2 ay ücretsiz' : 'Yıllık ödemede 2 ay ücretsiz';
      priceEl.classList.remove('pop'); void priceEl.offsetWidth; priceEl.classList.add('pop');
      var link = $('a.btn', p);
      if (link) link.setAttribute('href', link.getAttribute('href').split('&')[0] + '&donem=' + (y ? 'yillik' : 'aylik'));
    });
  }
  billBtns.forEach(function (b) { b.addEventListener('click', function () { setBill(b.getAttribute('data-bill') === 'y'); }); });

  /* ---------- FAQ: one open at a time (fallback for browsers without details[name]) ---------- */
  var faqs = $$('.faq details');
  faqs.forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (d.open) faqs.forEach(function (o) { if (o !== d) o.open = false; });
    });
  });

  /* ---------- Reveal on scroll ---------- */
  var rv = $$('.rv');
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    rv.forEach(function (el) { io.observe(el); });
  } else {
    rv.forEach(function (el) { el.classList.add('in'); });
  }
})();
