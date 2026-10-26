/* DNL Station — auth.js (giriş / üye ol) */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* Şifre göster / gizle */
  $$('[data-toggle-pw]').forEach(function (btn) {
    var input = document.getElementById(btn.getAttribute('data-toggle-pw'));
    if (!input) return;
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Şifreyi gizle' : 'Şifreyi göster');
    });
  });

  /* Şifre gücü */
  var pw = $('#u-pw'), pw2 = $('#u-pw2'), meter = $('#pw-meter'), hint = $('#pw2-hint');
  var colors = ['', '#F87171', '#FBBF24', '#8B6CFF', '#22D3EE'], labels = ['', 'Zayıf', 'Orta', 'İyi', 'Güçlü'];
  function strength(v) {
    var s = 0;
    if (v.length >= 8) s++;
    if (/[0-9]/.test(v)) s++;
    if (/[A-ZÇĞİÖŞÜ]/.test(v)) s++;
    if (/[^A-Za-z0-9ÇĞİÖŞÜçğıöşü]/.test(v)) s++;
    if (v.length && !s) s = 1;
    return s;
  }
  function renderMeter() {
    if (!pw || !meter) return;
    var s = strength(pw.value), out = $('output', meter);
    $$('.meter-bars i', meter).forEach(function (b, i) { b.style.background = i < s ? colors[s] : ''; });
    out.textContent = labels[s];
    out.style.color = s ? colors[s] : '';
  }
  /* Şifre eşleşme kontrolü */
  function checkMatch(force) {
    if (!pw || !pw2 || !hint) return true;
    var a = pw.value, b = pw2.value;
    pw2.classList.remove('bad', 'good'); hint.className = 'hint'; hint.textContent = '';
    if (!b && !force) return false;
    if (!b) { pw2.classList.add('bad'); hint.classList.add('bad'); hint.textContent = 'Lütfen şifrenizi tekrar girin.'; return false; }
    if (a === b) { pw2.classList.add('good'); hint.classList.add('good'); hint.textContent = 'Şifreler eşleşiyor.'; return true; }
    if (force || b.length >= a.length) { pw2.classList.add('bad'); hint.classList.add('bad'); hint.textContent = 'Şifreler eşleşmiyor.'; }
    return false;
  }
  if (pw) pw.addEventListener('input', function () { renderMeter(); if (pw2 && pw2.value) checkMatch(false); });
  if (pw2) pw2.addEventListener('input', function () { checkMatch(false); });

  /* URL'den paket ve dönem ön seçimi (?paket=pro&donem=aylik) */
  var m = location.search.match(/[?&]paket=([a-z]+)/);
  if (m) { var r = $('input[name="paket"][value="' + m[1] + '"]'); if (r) r.checked = true; }
  var d = location.search.match(/[?&]donem=(aylik|yillik)/);
  if (d) { var rd = $('input[name="donem"][value="' + d[1] + '"]'); if (rd) rd.checked = true; }

  /* Paket fiyatlarını seçili döneme göre güncelle */
  var tl = function (n) { return '₺' + Number(n).toLocaleString('tr-TR'); };
  function renderPlans() {
    var per = $('input[name="donem"]:checked'); if (!per) return;
    var yearly = per.value === 'yillik';
    $$('input[name="paket"]').forEach(function (inp) {
      var sm = inp.parentNode.querySelector('small');
      if (sm) sm.textContent = yearly ? tl(inp.getAttribute('data-y')) + '/yıl' : tl(inp.getAttribute('data-m')) + '/ay';
    });
    var sel = $('input[name="paket"]:checked'), sum = $('#plan-sum');
    if (sel && sum) {
      var name = sel.parentNode.querySelector('b').textContent, y = +sel.getAttribute('data-y'), mo = +sel.getAttribute('data-m');
      sum.innerHTML = yearly
        ? '<b>' + name + '</b> · yıllık ' + tl(y) + ' · aylık ortalama ' + tl(Math.round(y / 12))
        : '<b>' + name + '</b> · aylık ' + tl(mo) + ' · yıllıkta 2 ay ücretsiz';
    }
  }
  $$('input[name="donem"], input[name="paket"]').forEach(function (i) { i.addEventListener('change', renderPlans); });
  renderPlans();

  function showNotice(form, html) {
    var n = $('#notice', form); if (!n) return;
    n.innerHTML = html; n.hidden = false;
  }
  function markInvalid(form) {
    var first = null;
    $$('input[required]', form).forEach(function (i) {
      var ok = i.type === 'checkbox' ? i.checked : i.checkValidity();
      i.classList.toggle('bad', !ok && i.type !== 'checkbox');
      if (!ok && !first) first = i;
    });
    return first;
  }
  $$('input.inp', document).forEach(function (i) { i.addEventListener('input', function () { if (i !== pw2) i.classList.remove('bad'); }); });

  /* Kayıt formu — backend bağlanana kadar bilgi mesajı gösterir, veri göndermez */
  var signup = $('#signup-form');
  if (signup) {
    signup.addEventListener('submit', function (e) {
      e.preventDefault();
      var bad = markInvalid(signup);
      var match = checkMatch(true);
      if (bad || !match) {
        showNotice(signup, 'Lütfen işaretli alanları kontrol edin' + (!match ? ' ve şifrelerin eşleştiğinden emin olun' : '') + '. Kullanım şartlarının onaylanması zorunludur.');
        (bad || pw2).focus();
        return;
      }
      showNotice(signup, '<b>Kayıtlar çok yakında açılıyor.</b> Bilgileriniz şu an kaydedilmedi. Açılıştan ilk siz haberdar olmak için Instagram\'da <a href="https://www.instagram.com/dnlstation/" target="_blank" rel="noopener">@dnlstation</a> hesabını takip edebilirsiniz.');
    });
  }

  /* Giriş formu */
  var login = $('#login-form');
  if (login) {
    login.addEventListener('submit', function (e) {
      e.preventDefault();
      var bad = markInvalid(login);
      if (bad) { showNotice(login, 'Lütfen e-posta/telefon ve şifrenizi girin.'); bad.focus(); return; }
      showNotice(login, '<b>Panel çok yakında açılıyor.</b> Giriş henüz aktif değil; açılış duyurusu için Instagram\'da <a href="https://www.instagram.com/dnlstation/" target="_blank" rel="noopener">@dnlstation</a> hesabını takip edebilirsiniz.');
    });
  }
  $$('[data-soon]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var f = $('#login-form'); if (f) showNotice(f, 'Şifre sıfırlama, panel kullanıma açıldığında aktif olacaktır.');
    });
  });
})();
