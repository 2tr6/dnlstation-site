/* DNL Station — auth.js (giriş, üye ol, şifremi unuttum, şifre yenile, e-posta doğrula) */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------- API istemcisi (CSRF + aynı köken) ---------- */
  var csrf = null;
  function getCsrf() {
    if (csrf) return Promise.resolve(csrf);
    return fetch('/api/auth/csrf', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        if (!res.body || !res.body.ok) { var e = new Error(res.body && res.body.error ? res.body.error.message : 'Bağlantı hatası'); e.code = res.body && res.body.error ? res.body.error.code : 'network'; throw e; }
        csrf = res.body.data.csrf;
        return csrf;
      });
  }
  function api(path, body) {
    return getCsrf().then(function (token) {
      return fetch('/api' + path, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify(body || {})
      });
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: { code: 'bad_response', message: 'Sunucudan beklenmeyen yanıt geldi.' } }; })
        .then(function (j) {
          if (j && j.error && j.error.code === 'csrf') csrf = null; // bir sonraki denemede yenilenir
          return { status: r.status, body: j };
        });
    }).catch(function (e) {
      return { status: 0, body: { ok: false, error: { code: e.code || 'network', message: e.message && e.code ? e.message : 'Sunucuya ulaşılamadı. İnternet bağlantınızı kontrol edin.' } } };
    });
  }

  /* ---------- Ortak arayüz yardımcıları ---------- */
  function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
  function showNotice(form, text, kind) {
    var n = $('#notice', form) || $('#notice'); if (!n) return;
    n.textContent = text; n.hidden = false;
    n.className = 'notice' + (kind ? ' ' + kind : '');
  }
  function hideNotice(form) { var n = $('#notice', form) || $('#notice'); if (n) n.hidden = true; }
  function setBusy(form, busy, label) {
    var b = $('button[type="submit"]', form); if (!b) return;
    if (busy) { b.dataset.label = b.textContent; b.textContent = label || 'Lütfen bekleyin…'; b.disabled = true; }
    else { b.textContent = b.dataset.label || b.textContent; b.disabled = false; }
  }
  function clearFieldErrors(form) {
    $$('.inp.bad', form).forEach(function (i) { i.classList.remove('bad'); });
    $$('.field-err', form).forEach(function (e) { e.remove(); });
  }
  function fieldError(input, msg) {
    if (!input) return;
    input.classList.add('bad');
    var host = input.closest('.pw') || input;
    var p = document.createElement('p'); p.className = 'hint bad field-err'; p.textContent = msg;
    host.insertAdjacentElement('afterend', p);
  }
  function applyServerErrors(form, err, map) {
    var fields = (err && err.fields) || {}, first = null;
    Object.keys(fields).forEach(function (k) {
      var el = map[k] ? $(map[k], form) : null;
      if (el) { fieldError(el, fields[k]); if (!first) first = el; }
    });
    showNotice(form, (err && err.message) || 'İşlem tamamlanamadı.', 'bad');
    if (first) first.focus();
  }
  function notConfigured(err) { return err && (err.code === 'not_configured' || err.code === 'maintenance' || err.code === 'db_unavailable'); }

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

  /* ---------- Şifre gücü + eşleşme (kayıt ve şifre yenileme) ---------- */
  var pw = $('#u-pw'), pw2 = $('#u-pw2'), meter = $('#pw-meter'), hint = $('#pw2-hint');
  var colors = ['', '#F87171', '#FBBF24', '#8B6CFF', '#2ED3E6'], labels = ['', 'Zayıf', 'Orta', 'İyi', 'Güçlü'];
  function strength(v) {
    var s = 0;
    if (v.length >= 8) s++;
    if (/[0-9]/.test(v)) s++;
    if (/[A-ZÇĞİÖŞÜ]/.test(v)) s++;
    if (/[^A-Za-z0-9ÇĞİÖŞÜçğıöşü]/.test(v)) s++;
    if (v.length && !s) s = 1;
    if (v.length && v.length < 8) s = Math.min(s, 1);
    return s;
  }
  function renderMeter() {
    if (!pw || !meter) return;
    var s = strength(pw.value), out = $('output', meter);
    $$('.meter-bars i', meter).forEach(function (b, i) { b.style.background = i < s ? colors[s] : ''; });
    out.textContent = labels[s];
    out.style.color = s ? colors[s] : '';
  }
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
  $$('input.inp').forEach(function (i) {
    i.addEventListener('input', function () {
      if (i !== pw2) i.classList.remove('bad');
      var n = i.closest('.pw') || i; var e = n.nextElementSibling;
      if (e && e.classList.contains('field-err')) e.remove();
    });
  });

  function markInvalid(form) {
    var first = null;
    $$('input[required]', form).forEach(function (i) {
      var ok = i.type === 'checkbox' ? i.checked : i.checkValidity();
      if (!ok && i.type !== 'checkbox') i.classList.add('bad');
      if (!ok && !first) first = i;
    });
    return first;
  }

  /* Giriş/kayıt sayfasında zaten oturum varsa panele yönlendir */
  if ($('#login-form') || $('#signup-form')) {
    fetch('/api/auth/csrf', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.ok) { csrf = j.data.csrf; if (j.data.authenticated) location.replace('/panel/'); }
      else if (j && j.error && notConfigured(j.error)) {
        var f = $('#login-form') || $('#signup-form');
        showNotice(f, j.error.message);
      }
    }).catch(function () {});
  }

  /* ---------- Üye ol ---------- */
  var m = location.search.match(/[?&]paket=([a-z]+)/);
  if (m) { var r = $('input[name="paket"][value="' + m[1] + '"]'); if (r) r.checked = true; }
  var d = location.search.match(/[?&]donem=(aylik|yillik)/);
  if (d) { var rd = $('input[name="donem"][value="' + d[1] + '"]'); if (rd) rd.checked = true; }
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
        ? '<b>' + esc(name) + '</b> · yıllık ' + tl(y) + ' · aylık ortalama ' + tl(Math.round(y / 12))
        : '<b>' + esc(name) + '</b> · aylık ' + tl(mo) + ' · yıllıkta 2 ay ücretsiz';
    }
  }
  $$('input[name="donem"], input[name="paket"]').forEach(function (i) { i.addEventListener('change', renderPlans); });
  renderPlans();

  var signup = $('#signup-form');
  if (signup) {
    signup.addEventListener('submit', function (e) {
      e.preventDefault();
      clearFieldErrors(signup); hideNotice(signup);
      var bad = markInvalid(signup), match = checkMatch(true);
      if (bad || !match) {
        showNotice(signup, 'Lütfen işaretli alanları kontrol edin' + (!match ? ' ve şifrelerin eşleştiğinden emin olun' : '') + '. Kullanım şartlarının onaylanması zorunludur.', 'bad');
        (bad || pw2).focus();
        return;
      }
      var body = {
        name: $('#u-name').value, business: $('#u-biz').value, phone: $('#u-tel').value, email: $('#u-mail').value,
        password: pw.value, password_confirm: pw2.value,
        types: $$('input[name="tur"]:checked').map(function (i) { return i.value; }),
        plan: ($('input[name="paket"]:checked') || {}).value, period: ($('input[name="donem"]:checked') || {}).value,
        accept: $('input[name="onay"]').checked
      };
      setBusy(signup, true, 'Hesabınız oluşturuluyor…');
      api('/auth/register', body).then(function (res) {
        if (res.body.ok) { location.href = res.body.data.redirect || '/panel/'; return; }
        setBusy(signup, false);
        applyServerErrors(signup, res.body.error, { name: '#u-name', business: '#u-biz', phone: '#u-tel', email: '#u-mail', password: '#u-pw', password_confirm: '#u-pw2' });
      });
    });
  }

  /* ---------- Giriş ---------- */
  var login = $('#login-form');
  if (login) {
    if (/[?&]sifre=yenilendi/.test(location.search)) showNotice(login, 'Şifreniz güncellendi. Yeni şifrenizle giriş yapabilirsiniz.', 'good');
    if (/[?&]oturum=bitti/.test(location.search)) showNotice(login, 'Oturumunuz sona erdi. Lütfen tekrar giriş yapın.');
    login.addEventListener('submit', function (e) {
      e.preventDefault();
      clearFieldErrors(login); hideNotice(login);
      var bad = markInvalid(login);
      if (bad) { showNotice(login, 'Lütfen e-posta adresinizi ve şifrenizi girin.', 'bad'); bad.focus(); return; }
      setBusy(login, true, 'Giriş yapılıyor…');
      api('/auth/login', { email: $('#g-user').value, password: $('#g-pw').value, remember: $('input[name="remember"]').checked }).then(function (res) {
        if (res.body.ok) { location.href = res.body.data.redirect || '/panel/'; return; }
        setBusy(login, false);
        applyServerErrors(login, res.body.error, { email: '#g-user', password: '#g-pw' });
        $('#g-pw').value = '';
      });
    });
  }

  /* ---------- Şifremi unuttum ---------- */
  var forgot = $('#forgot-form');
  if (forgot) {
    forgot.addEventListener('submit', function (e) {
      e.preventDefault();
      clearFieldErrors(forgot); hideNotice(forgot);
      var bad = markInvalid(forgot);
      if (bad) { showNotice(forgot, 'Lütfen geçerli bir e-posta adresi girin.', 'bad'); bad.focus(); return; }
      setBusy(forgot, true, 'Gönderiliyor…');
      api('/auth/forgot', { email: $('#f-mail').value }).then(function (res) {
        setBusy(forgot, false);
        if (res.body.ok) { showNotice(forgot, res.body.data.message + ' Gelen kutunuzu ve spam klasörünüzü kontrol edin.', 'good'); return; }
        applyServerErrors(forgot, res.body.error, { email: '#f-mail' });
      });
    });
  }

  /* Bağlantı anahtarı adres çubuğunun # kısmından okunur (sunucu günlüklerine düşmez) ve hemen silinir */
  function takeToken() {
    var mm = location.hash.match(/^#t=([a-f0-9]{24}\.[A-Za-z0-9_-]{43})$/);
    if (location.hash && history.replaceState) history.replaceState(null, '', location.pathname);
    return mm ? mm[1] : null;
  }

  /* ---------- Şifre yenile ---------- */
  var reset = $('#reset-form');
  if (reset) {
    var rtoken = takeToken();
    if (!rtoken) { showNotice(reset, 'Bağlantı geçersiz ya da eksik. Lütfen e-postadaki bağlantıyı yeniden açın veya yeni bir sıfırlama bağlantısı isteyin.', 'bad'); $('button[type="submit"]', reset).disabled = true; }
    reset.addEventListener('submit', function (e) {
      e.preventDefault();
      clearFieldErrors(reset); hideNotice(reset);
      var bad = markInvalid(reset), match = checkMatch(true);
      if (bad || !match) { showNotice(reset, 'Lütfen yeni şifrenizi iki kez aynı şekilde girin.', 'bad'); (bad || pw2).focus(); return; }
      setBusy(reset, true, 'Kaydediliyor…');
      api('/auth/reset', { token: rtoken, password: pw.value, password_confirm: pw2.value }).then(function (res) {
        if (res.body.ok) { location.href = res.body.data.redirect || '/giris/?sifre=yenilendi'; return; }
        setBusy(reset, false);
        applyServerErrors(reset, res.body.error, { password: '#u-pw', password_confirm: '#u-pw2' });
      });
    });
  }

  /* ---------- E-posta doğrula ---------- */
  var vbox = $('#verify-box');
  if (vbox) {
    var vtoken = takeToken(), title = $('#verify-title'), text = $('#verify-text');
    if (!vtoken) { title.textContent = 'Bağlantı geçersiz.'; text.textContent = 'E-postanızdaki doğrulama bağlantısını yeniden açın. Gerekirse panelden yeni bağlantı isteyebilirsiniz.'; }
    else {
      api('/auth/verify', { token: vtoken }).then(function (res) {
        if (res.body.ok) { title.textContent = 'E-posta adresiniz doğrulandı.'; text.textContent = 'Teşekkürler. Panelinize dönüp kullanmaya devam edebilirsiniz.'; }
        else { title.textContent = 'Doğrulama tamamlanamadı.'; text.textContent = res.body.error.message; }
      });
    }
  }
})();
