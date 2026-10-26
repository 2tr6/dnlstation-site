// DNL Station müşteri ekranı. Anahtar URL parçasında (#k=...) durur; sunucuya yalnızca başlıkta gider.
(function () {
  'use strict';
  var m = /(?:^|[#&])k=([A-Za-z0-9_-]{32})/.exec(location.hash);
  var key = m ? m[1] : null;
  try { if (key) sessionStorage.setItem('dnl_k', key); else key = sessionStorage.getItem('dnl_k'); } catch (e) { /* yok */ }
  var grid = document.getElementById('grid'), msg = document.getElementById('msg');
  var data = null, at = 0, failCount = 0;
  var fmt = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  function show(t) { msg.textContent = t; msg.hidden = false; }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
  function dur(s) { s = Math.max(0, Math.floor(s)); var h = Math.floor(s / 3600), mm = Math.floor(s % 3600 / 60), ss = s % 60; return (h ? h + ':' + String(mm).padStart(2, '0') : mm) + ':' + String(ss).padStart(2, '0'); }
  if (!key) { show('Ekran bağlantısı eksik. Panelden “Müşteri ekranı” bağlantısını açın.'); return; }
  function load() {
    fetch('/api/display', { headers: { 'X-Display-Key': key, Accept: 'application/json' }, credentials: 'omit', cache: 'no-store' })
      .then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
      .then(function (res) {
        if (res.s === 401) { show('Ekran bağlantısı geçersiz ya da yenilenmiş. Panelden yeni bağlantı oluşturun.'); try { sessionStorage.removeItem('dnl_k'); } catch (e) { /* yok */ } return; }
        if (!res.j || !res.j.ok) throw new Error('x');
        data = res.j.data; at = performance.now(); failCount = 0; msg.hidden = true;
        document.getElementById('biz').textContent = data.business;
        render();
      })
      .catch(function () { if (++failCount >= 3) show('Bağlantı bekleniyor…'); });
  }
  function render() {
    if (!data) return;
    var dt = (performance.now() - at) / 1000;
    while (grid.firstChild) grid.firstChild.remove();
    var free = 0;
    data.tables.forEach(function (t) {
      var card = el('div', 't');
      var name = el('div', 'name', t.name); name.appendChild(el('small', null, t.type)); card.appendChild(name);
      if (!t.busy) {
        free++; card.classList.add('free');
        card.appendChild(el('div', 'big', 'Boş'));
        card.appendChild(el('div', 'sub', ''));
      } else {
        card.classList.add('busy');
        var big, sub = el('div', 'sub');
        if (t.mode === 'match') { big = t.match_count + ' maç'; }
        else if (t.remaining !== null) {
          var rem = t.remaining - dt;
          big = rem > 0 ? dur(rem) : '+' + dur(-rem);
          card.classList.add(rem <= 0 ? 'over' : rem <= 300 ? 'warn' : 'busy');
          sub.appendChild(el('span', null, rem > 0 ? 'Kalan süre' : 'Süre doldu'));
        } else { big = dur(t.elapsed + dt); sub.appendChild(el('span', null, 'Geçen süre')); }
        card.appendChild(el('div', 'big mono', big));
        if (t.amount_cents !== null) sub.appendChild(el('span', 'mono', '₺' + fmt.format((t.amount_cents + Math.round((t.rate_per_hour || 0) * dt / 3600)) / 100)));
        card.appendChild(sub);
      }
      grid.appendChild(card);
    });
    document.getElementById('free').textContent = free + ' boş masa';
  }
  function clock() { document.getElementById('clock').textContent = new Intl.DateTimeFormat('tr-TR', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Istanbul' }).format(new Date()); }
  // Anahtarı adres çubuğundan temizle (ekran görüntüsü / paylaşımda görünmesin)
  if (location.hash) history.replaceState(null, '', location.pathname);
  load(); clock();
  setInterval(load, 5000); setInterval(render, 1000); setInterval(clock, 10000);
})();
