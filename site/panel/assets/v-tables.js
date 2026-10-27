// Masalar ekranı: canlı masa durumu, oturum açma, ürün ekleme, hesap kapama
import { state, h, $, $$, clear, icon, get, post, del, can, toast, fail, modal, confirmBox, money, dur, durShort, timeOnly, busy, seg, moneyInp, inputToCents, moneyStr, field, inp, beep, empty, METHOD_LABEL, ApiError, fill, add } from './core.js?v=1';
import { productPicker } from './v-cafe.js?v=1';
import { catalogEditors } from './editors.js?v=1';

const POLL_MS = 5000;

export function tablesView(ctx) {
  const { main } = ctx;
  let data = null;          // son dashboard yanıtı
  let fetchedAt = 0;        // yanıtın alındığı an (performance.now)
  let filter = 'all';
  let timer = null, ticker = null, stopped = false;
  const alerts = new Map(); // oturum -> uyarı durumu
  let openSheet = null;     // açık oturum penceresi { sid, update(s) }
  let editing = false;      // düzenleme modu (masa adı/türü/ücret buradan değişir)
  let cat = null;           // /catalog yanıtı (düzenleme modunda)
  const canEdit = can('settings.tables');
  const ed = catalogEditors({ get d() { return cat; }, set: (r) => { cat = r; load(false); } });
  async function loadCat() { cat = await get('/catalog'); return cat; }

  const kpiBox = h('div', { class: 'kpis' });
  const chipBox = h('div', { class: 'chips', role: 'tablist', 'aria-label': 'Masa türü' });
  const grid = h('div', { class: 'tgrid', 'aria-live': 'off' });
  const editBtn = canEdit ? h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: () => setEditing(!editing) }, icon('edit', 16), 'Düzenle') : null;
  const toolbar = h('div', { class: 'toolbar' }, chipBox,
    h('div', { class: 'tb-r' }, editBtn, h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: recentModal }, icon('undo', 16), 'Son hesaplar')));
  const editBar = h('div', { class: 'banner info edit-bar', hidden: true },
    icon('edit', 18), h('span', { class: 'grow' }, 'Düzenleme modu: masaya dokunarak adını, türünü ya da sırasını değiştirin.'),
    h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: async (ev) => busy(ev.currentTarget, async () => { try { await loadCat(); ed.addTables(cat.types.map((t) => [t.id, t.name])); } catch (e) { fail(e); } }) }, icon('plus', 16), 'Masa ekle'),
    h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: pricesModal }, 'Ücretler'),
    h('button', { class: 'btn btn-primary sm', type: 'button', onclick: () => setEditing(false) }, 'Bitti'));
  add(main, kpiBox, toolbar, editBar, grid);
  function setEditing(v) {
    editing = v;
    editBar.hidden = !v;
    if (editBtn) editBtn.classList.toggle('on', v);
    grid.classList.toggle('editing', v);
    if (v) loadCat().catch(fail);
    render();
  }
  function pricesModal() {
    const draw = () => fill(list, ...data.types.map((t) => h('button', { type: 'button', class: 'type-row', onclick: async () => { m.close(); try { await loadCat(); ed.editType(cat.types.find((x) => x.id === t.id)); } catch (e) { fail(e); } } },
      h('div', null, h('b', null, t.name)), h('div', { class: 'type-prices mono' }, h('span', null, money(t.hour_price) + '/sa' + (t.uses_controllers && t.hour_price_4 ? ' · 4 kol ' + money(t.hour_price_4) : '')), t.match_price !== null ? h('span', null, 'Maç ' + money(t.match_price)) : null), icon('edit', 16))));
    const list = h('div', { class: 'type-list' });
    const m = modal({ title: 'Ücretler', body: h('div', { class: 'form' }, h('p', { class: 'muted sm' }, 'Düzenlemek istediğiniz türe dokunun. Yeni ücretler yeni açılan masalara uygulanır.'), list),
      actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: async () => { m.close(); try { await loadCat(); ed.editType(null); } catch (e) { fail(e); } } }, icon('plus', 16), 'Yeni tür'), h('button', { class: 'btn btn-primary', type: 'button', onclick: () => m.close() }, 'Kapat')] });
    draw();
  }
  async function editTableQuick(t) {
    try {
      await loadCat();
      const ct = cat.tables.find((x) => x.id === t.id);
      if (ct) ed.editTable(ct, cat.types.map((x) => [x.id, x.name]));
    } catch (e) { fail(e); }
  }

  async function load(first) {
    try {
      const d = await get('/dashboard');
      data = d; fetchedAt = performance.now();
      if (first || chipBox.childElementCount !== d.types.length + 1) renderChips();
      ctx.updateShiftChip(d.shift ? d.shift.id : null);
      render();
    } catch (e) {
      if (first) { fill(grid, empty(e.message || 'Masalar yüklenemedi.')); }
    }
  }
  function schedule() {
    clearTimeout(timer);
    if (stopped) return;
    timer = setTimeout(async () => { if (!document.hidden) await load(false); schedule(); }, POLL_MS);
  }
  const onVis = () => { if (!document.hidden && !stopped) { load(false); schedule(); } };
  document.addEventListener('visibilitychange', onVis);

  function renderChips() {
    const all = [['all', 'Tümü'], ...data.types.filter((t) => data.tables.some((x) => x.type_id === t.id)).map((t) => [String(t.id), t.name])];
    fill(chipBox, ...all.map(([v, l]) => h('button', {
      type: 'button', role: 'tab', class: 'chip-btn' + (filter === v ? ' on' : ''), 'aria-selected': String(filter === v),
      onclick: () => { filter = v; renderChips(); render(); },
    }, l)));
  }

  function live(s) {
    const dt = (performance.now() - fetchedAt) / 1000;
    const elapsed = s.elapsed + dt;
    const remaining = s.remaining !== null ? s.remaining - dt : null;
    const amount = s.subtotal_cents + Math.round((s.rate_per_hour || 0) * dt / 3600);
    return { elapsed, remaining, amount };
  }
  function status(s, l) {
    if (!s) return 'free';
    if (l.remaining !== null) {
      if (l.remaining <= 0) return 'over';
      if (l.remaining <= (state.me.business.warn_minutes || 5) * 60) return 'warn';
    }
    return 'busy';
  }

  function render() {
    if (!data) return;
    const typeById = Object.fromEntries(data.types.map((t) => [t.id, t]));
    const list = data.tables.filter((t) => filter === 'all' || String(t.type_id) === filter);
    // KPI
    const k = data.kpi;
    fill(kpiBox, 
      kpi('Dolu masa', `${k.active_tables}/${k.total_tables}`, 'grid'),
      kpi('Açık hesaplar', money(k.open_amount_cents), 'clock', 'live-open'),
      k.revenue_today_cents !== null ? kpi('Bugünkü tahsilat', money(k.revenue_today_cents), 'wallet') : null,
      k.low_stock > 0 && can('stock.manage') ? kpi('Kritik stok', String(k.low_stock), 'alert', null, '#/urunler', true) : null,
    );
    if (!data.tables.length) {
      fill(grid, empty('Henüz masa tanımlanmamış.', can('settings.tables') ? h('a', { class: 'btn btn-primary', href: '#/ayarlar' }, 'Masaları tanımlayın') : h('p', { class: 'muted' }, 'Yöneticinizin masaları tanımlaması gerekiyor.')));
      return;
    }
    if (!data.shift) {
      if (!$('.shift-warn', main)) toolbar.after(h('div', { class: 'banner warn shift-warn' }, icon('wallet', 18), h('span', null, 'Kasa kapalı. Masa açabilirsiniz ama hesap almak için önce kasayı açın.'), h('a', { href: '#/vardiya' }, 'Kasayı aç')));
    } else { const w = $('.shift-warn', main); if (w) w.remove(); }

    const cards = list.map((t) => {
      const s = t.session; const l = s ? live(s) : null; const st = status(s, l); const type = typeById[t.type_id] || {};
      const go = () => editing ? editTableQuick(t) : (s ? sessionSheet(s.id) : openModal(t, type));
      const card = h('div', { role: 'button', tabindex: '0', class: 'tcard ' + st, 'data-tid': t.id, onclick: go, onkeydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } } },
        h('div', { class: 'tc-top' }, h('b', { class: 'tc-name' }, t.name), h('span', { class: 'tc-type' }, type.name || '')),
        s ? [
          h('div', { class: 'tc-time mono', 'data-time': '' }, timeText(s, l)),
          h('div', { class: 'tc-sub' }, h('span', { class: 'mono', 'data-amt': '' }, money(l.amount)),
            h('span', { class: 'tc-tags' }, modeTag(s), s.controllers ? h('span', { class: 'tag' }, s.controllers + ' kol') : null, s.items_cents ? h('span', { class: 'tag' }, icon('cup', 12)) : null)),
          editing ? null : h('div', { class: 'tc-acts' },
            h('button', { type: 'button', class: 'tc-btn', 'aria-label': t.name + ' ürün ekle', onclick: (e) => { e.stopPropagation(); quickProducts(s, t); } }, icon('cup', 16), 'Ürün'),
            h('button', { type: 'button', class: 'tc-btn pay', 'aria-label': t.name + ' hesap al', onclick: (e) => { e.stopPropagation(); quickCheckout(s.id); } }, icon('wallet', 16), 'Hesap')),
        ] : [
          h('div', { class: 'tc-free' }, 'Boş'),
          h('div', { class: 'tc-sub' }, h('span', { class: 'muted sm' }, priceHint(type)), editing ? null : h('span', { class: 'tc-open' }, icon('plus', 16), 'Aç')),
        ], editing ? h('span', { class: 'tc-edit' }, icon('edit', 16), 'Düzenle') : null);
      return card;
    });
    if (editing) cards.push(h('button', { type: 'button', class: 'tcard add-card', onclick: async () => { try { await loadCat(); ed.addTables(cat.types.map((x) => [x.id, x.name])); } catch (e) { fail(e); } } }, icon('plus', 22), h('b', null, 'Masa ekle')));
    fill(grid, ...cards);
    checkAlerts();
    if (openSheet) {
      const t = data.tables.find((x) => x.session && x.session.id === openSheet.sid);
      if (t) openSheet.update(t.session);
    }
  }
  function kpi(label, value, ic, id, href, warn) {
    const inner = [h('span', { class: 'k-ic' }, icon(ic, 18)), h('div', null, h('small', null, label), h('b', { class: 'mono', id: id || null }, value))];
    return href ? h('a', { class: 'kpi' + (warn ? ' warn' : ''), href }, inner) : h('div', { class: 'kpi' }, inner);
  }
  function timeText(s, l) {
    if (s.mode === 'match') return s.match_count + ' maç';
    if (l.remaining !== null) return l.remaining > 0 ? dur(l.remaining) : '+' + dur(-l.remaining);
    return dur(l.elapsed);
  }
  function modeTag(s) {
    return h('span', { class: 'tag' }, s.mode === 'fixed' ? 'Süreli' : s.mode === 'match' ? 'Maç' : 'Açık');
  }
  function priceHint(t) {
    if (!t || !t.hour_price) return '';
    return money(t.hour_price).replace(',00', '') + '/sa';
  }

  // Saniyelik yerel sayaç (sunucu verisinden ileri hesap)
  function tick() {
    if (!data) return;
    let open = 0;
    for (const t of data.tables) {
      if (!t.session) continue;
      const s = t.session; const l = live(s);
      open += l.amount;
      const card = grid.querySelector(`[data-tid="${t.id}"]`);
      if (!card) continue;
      const te = card.querySelector('[data-time]'); if (te) te.textContent = timeText(s, l);
      const ae = card.querySelector('[data-amt]'); if (ae) ae.textContent = money(l.amount);
      const st = status(s, l);
      if (!card.classList.contains(st)) { card.classList.remove('busy', 'warn', 'over'); card.classList.add(st); }
    }
    const lo = $('#live-open'); if (lo) lo.textContent = money(open);
    checkAlerts();
    if (openSheet) { const t = data.tables.find((x) => x.session && x.session.id === openSheet.sid); if (t) openSheet.tick(live(t.session)); }
  }
  function checkAlerts() {
    if (!data) return;
    const warnSec = (state.me.business.warn_minutes || 5) * 60;
    for (const t of data.tables) {
      const s = t.session; if (!s || s.remaining === null) continue;
      const l = live(s); const key = s.id + ':' + s.planned_minutes; const a = alerts.get(key) || {};
      if (l.remaining <= 0 && !a.over) { a.over = a.warn = true; beep(3); toast(`${t.name}: süre doldu.`, 'bad', 8000); }
      else if (warnSec > 0 && l.remaining <= warnSec && l.remaining > 0 && !a.warn) { a.warn = true; beep(1); toast(`${t.name}: ${durShort(l.remaining)} kaldı.`, 'info', 6000); }
      alerts.set(key, a);
    }
  }

  /* ---------- Masa açma ---------- */
  function openModal(t, type) {
    const memKey = 'dnl_open_' + type.id;
    let mem = {};
    try { mem = JSON.parse(localStorage.getItem(memKey) || '{}') || {}; } catch { /* yok */ }
    let mode = ['open', 'fixed', 'match'].includes(mem.mode) ? mem.mode : 'open', ctrl = mem.ctrl === 4 ? 4 : 2, minutes = mem.minutes >= 5 && mem.minutes <= 1440 ? mem.minutes : 60;
    const usesCtrl = !!type.uses_controllers; const hasMatch = type.match_price !== null && type.match_price !== undefined;
    const note = inp('note', { maxlength: 200, placeholder: 'İsteğe bağlı (ör. müşteri adı)' });
    const custom = inp('custom', { inputmode: 'numeric', placeholder: 'dk', class: 'inp mono', style: { maxWidth: '110px' } });
    const durBox = h('div', { class: 'field' });
    const info = h('p', { class: 'hint price-info' });
    const renderInfo = () => {
      const four = usesCtrl && ctrl === 4;
      if (mode === 'match') info.textContent = 'Maç başı ' + money(four && type.match_price_4 ? type.match_price_4 : type.match_price);
      else {
        const rate = four && type.hour_price_4 ? type.hour_price_4 : type.hour_price;
        info.textContent = 'Saatlik ' + money(rate) + (mode === 'fixed' ? ` · ${minutes} dk = ${money(Math.round(rate * minutes / 60))} (peşin)` : '') + (type.min_minutes && mode === 'open' ? ` · en az ${type.min_minutes} dk` : '') + (type.night_enabled ? ' · gece tarifesi uygulanır' : '');
      }
    };
    const renderDur = () => {
      clear(durBox);
      if (mode !== 'fixed') return;
      durBox.append(h('span', { class: 'lbl' }, 'Süre'), h('div', { class: 'row-wrap' },
        seg([[30, '30 dk'], [60, '1 sa'], [90, '1,5 sa'], [120, '2 sa'], [180, '3 sa']], minutes, (v) => { minutes = v; custom.value = ''; renderInfo(); }), custom));
    };
    custom.addEventListener('input', () => { const v = parseInt(custom.value, 10); if (v >= 5 && v <= 1440) { minutes = v; $$('.seg button', durBox).forEach((b) => { b.classList.remove('on'); b.setAttribute('aria-checked', 'false'); }); renderInfo(); } });
    const modes = [['open', 'Açık süre'], ['fixed', 'Sabit süre']]; if (hasMatch) modes.push(['match', 'Maç başı']);
    if (mode === 'match' && !hasMatch) mode = 'open';
    const body = h('div', { class: 'form' },
      h('div', { class: 'field' }, h('span', { class: 'lbl' }, 'Ücretlendirme'), seg(modes, mode, (v) => { mode = v; renderDur(); renderInfo(); })),
      usesCtrl ? h('div', { class: 'field' }, h('span', { class: 'lbl' }, 'Kol sayısı'), seg([[2, '2 kol'], [4, '4 kol']], ctrl, (v) => { ctrl = v; renderInfo(); })) : null,
      durBox, info, field('Not', note));
    renderDur(); renderInfo();
    const ok = h('button', { class: 'btn btn-primary', type: 'button' }, icon('pad', 18), 'Masayı aç');
    const m = modal({ title: t.name + ' · Masa aç', body, actions: [
      canEdit ? h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => { m.close(); editTableQuick(t); } }, icon('edit', 16), 'Masayı düzenle') : null,
      h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), ok] });
    ok.onclick = () => busy(ok, async () => {
      if (mode === 'fixed' && !(minutes >= 5 && minutes <= 1440)) { toast('Süre 5 dakika ile 24 saat arasında olmalı.', 'bad'); return; }
      try {
        const s = await post('/sessions', { table_id: t.id, mode, controllers: usesCtrl ? ctrl : null, planned_minutes: mode === 'fixed' ? minutes : null, note: note.value });
        try { localStorage.setItem(memKey, JSON.stringify({ mode, ctrl, minutes })); } catch { /* yok */ }
        m.close(); toast(`${t.name} açıldı.`, 'good'); await load(false);
        if (s && s.id) { /* kart güncellendi */ }
      } catch (e) { fail(e); if (e.code === 'occupied') { m.close(); load(false); } }
    });
  }

  /* ---------- Karttan hızlı işlemler ---------- */
  function quickProducts(s, t) {
    productPicker({
      title: t.name + ' · Ürün ekle',
      onPick: async (p, qty) => { try { await post(`/sessions/${s.id}/items`, { product_id: p.id, qty }); toast(`${qty}× ${p.name} eklendi.`, 'good', 1800); load(false); return true; } catch (e) { fail(e); return false; } },
    });
  }
  async function quickCheckout(sid) {
    let d;
    try { d = await get('/sessions/' + sid); } catch (e) { fail(e); load(false); return; }
    const at = performance.now();
    const dt = () => (performance.now() - at) / 1000;
    checkoutModal({
      title: d.table_name + ' · Hesap',
      lines: () => [['Oyun', d.game_cents + Math.round((d.rate_per_hour || 0) * dt() / 3600)], ['Ürünler', d.items_cents]],
      subtotal: () => d.subtotal_cents + Math.round((d.rate_per_hour || 0) * dt() / 3600), live: d.rate_per_hour > 0,
      submit: (payload) => post(`/sessions/${sid}/close`, payload),
      done: (r) => { toast(`Hesap kapatıldı · ${money(r.total_cents)}`, 'good', 5000); load(false); },
      onStale: () => load(false),
    });
  }

  /* ---------- Oturum penceresi ---------- */
  async function sessionSheet(sid) {
    let d;
    try { d = await get('/sessions/' + sid); } catch (e) { fail(e); load(false); return; }
    let detailAt = performance.now();
    const timeEl = h('div', { class: 'ss-time mono' });
    const amtEl = h('div', { class: 'ss-amt mono' });
    const metaEl = h('div', { class: 'ss-meta' });
    const actionsEl = h('div', { class: 'ss-actions' });
    const itemsEl = h('div', { class: 'ss-items' });
    const body = h('div', { class: 'ss' }, h('div', { class: 'ss-hero' }, h('div', null, h('small', { class: 'muted' }, 'Süre'), timeEl), h('div', { class: 'r' }, h('small', { class: 'muted' }, 'Tutar'), amtEl)), metaEl, actionsEl,
      h('div', { class: 'ss-sec' }, h('div', { class: 'ss-sec-h' }, h('h3', null, 'Adisyon'), h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: addProducts }, icon('plus', 16), 'Ürün ekle')), itemsEl));
    const closeBtn = h('button', { class: 'btn btn-primary', type: 'button', onclick: () => checkout() }, icon('wallet', 18), 'Hesap al');
    const foot = [
      can('session.cancel') ? h('button', { class: 'btn btn-ghost danger-t', type: 'button', onclick: cancel }, 'İptal') : null,
      h('button', { class: 'btn btn-ghost', type: 'button', onclick: move }, icon('move', 16), 'Taşı'),
      closeBtn,
    ];
    const m = modal({ title: `${d.table_name} · ${d.type_name}`, body, actions: foot, wide: true, onClose: () => { openSheet = null; } });

    const liveD = () => {
      const dt = (performance.now() - detailAt) / 1000;
      return { elapsed: d.elapsed + dt, remaining: d.remaining !== null ? d.remaining - dt : null, amount: d.subtotal_cents + Math.round((d.rate_per_hour || 0) * dt / 3600) };
    };
    const paint = (l) => {
      timeEl.textContent = d.mode === 'match' ? d.match_count + ' maç' : (l.remaining !== null ? (l.remaining > 0 ? dur(l.remaining) : '+' + dur(-l.remaining)) : dur(l.elapsed));
      timeEl.classList.toggle('over', l.remaining !== null && l.remaining <= 0);
      amtEl.textContent = money(l.amount);
    };
    const renderAll = () => {
      paint(liveD());
      fill(metaEl, 
        h('span', { class: 'tag' }, d.mode === 'fixed' ? `Süreli · ${d.planned_minutes} dk` : d.mode === 'match' ? 'Maç başı · ' + money(d.match_rate) : 'Açık süre'),
        d.controllers ? h('span', { class: 'tag' }, d.controllers + ' kol') : null,
        h('span', { class: 'muted sm' }, 'Başlangıç ' + timeOnly(d.started_at) + (d.opened_by ? ' · ' + d.opened_by : '')),
        d.note ? h('span', { class: 'muted sm' }, '“' + d.note + '”') : null,
        h('span', { class: 'muted sm' }, `Oyun ${money(d.game_cents)} · Ürün ${money(d.items_cents)}`));
      const acts = [];
      if (d.mode !== 'match') {
        acts.push(h('div', { class: 'act' }, h('span', { class: 'lbl' }, d.mode === 'open' ? 'Süre sınırı koy' : 'Süre ekle'),
          h('div', { class: 'row-wrap' }, [15, 30, 60].map((mn) => h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: (ev) => act(ev.currentTarget, 'extend', { minutes: mn }) }, '+' + mn + ' dk')))));
      } else {
        acts.push(h('div', { class: 'act' }, h('span', { class: 'lbl' }, 'Maç'),
          h('div', { class: 'stepper' },
            h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Maç azalt', onclick: (ev) => act(ev.currentTarget, 'match', { delta: -1 }) }, icon('minus')),
            h('b', { class: 'mono' }, String(d.match_count)),
            h('button', { class: 'icon-btn primary', type: 'button', 'aria-label': 'Maç ekle', onclick: (ev) => act(ev.currentTarget, 'match', { delta: 1 }) }, icon('plus')))));
      }
      if (d.uses_controllers) {
        acts.push(h('div', { class: 'act' }, h('span', { class: 'lbl' }, 'Kol sayısı'),
          seg([[2, '2 kol'], [4, '4 kol']], d.controllers, (v) => act(null, 'controllers', { controllers: v }))));
      }
      fill(actionsEl, ...acts);
      fill(itemsEl, d.items.length ? h('ul', { class: 'items' }, d.items.map((it) => h('li', null,
        h('span', null, h('b', null, it.qty + '×'), ' ', it.name), h('span', { class: 'mono' }, money(it.total_cents)),
        h('button', { class: 'icon-btn sm', type: 'button', 'aria-label': it.name + ' kalemini sil', onclick: (ev) => removeItem(ev.currentTarget, it) }, icon('trash', 16))))) : h('p', { class: 'muted sm' }, 'Adisyonda ürün yok.'));
    };
    const refresh = (nd) => { d = nd; detailAt = performance.now(); renderAll(); load(false); };
    async function act(btn, kind, body) {
      const run = async () => { try { refresh(await post(`/sessions/${sid}/${kind}`, body)); } catch (e) { fail(e); if (e.code === 'not_active') m.close(); } };
      return btn ? busy(btn, run) : run();
    }
    async function removeItem(btn, it) {
      if (!await confirmBox('Kalemi sil', `${it.qty}× ${it.name} adisyondan çıkarılsın mı?`, 'Sil', true)) return;
      busy(btn, async () => { try { refresh(await del(`/sessions/${sid}/items/${it.id}`)); } catch (e) { fail(e); } });
    }
    function addProducts() {
      productPicker({
        title: d.table_name + ' · Ürün ekle',
        onPick: async (p, qty) => { try { refresh(await post(`/sessions/${sid}/items`, { product_id: p.id, qty })); toast(`${qty}× ${p.name} eklendi.`, 'good', 1800); return true; } catch (e) { fail(e); return false; } },
      });
    }
    async function move() {
      const free = data.tables.filter((t) => !t.session && t.type_id === data.tables.find((x) => x.session && x.session.id === sid)?.type_id);
      if (!free.length) { toast('Aynı türde boş masa yok.', 'bad'); return; }
      const mm = modal({ title: 'Masayı taşı', body: h('div', { class: 'pick-grid' }, free.map((t) => h('button', { class: 'pick', type: 'button', onclick: async (ev) => {
        await busy(ev.currentTarget, async () => { try { refresh(await post(`/sessions/${sid}/move`, { table_id: t.id })); mm.close(); m.close(); toast(`${t.name} masasına taşındı.`, 'good'); } catch (e) { fail(e); } });
      } }, t.name))) });
    }
    async function cancel() {
      const reason = inp('reason', { maxlength: 120, placeholder: 'ör. Yanlış masa açıldı' });
      const ok = h('button', { class: 'btn btn-danger', type: 'button' }, 'Oturumu iptal et');
      const cm = modal({ title: 'Oturumu iptal et', body: h('div', { class: 'form' }, h('p', { class: 'muted' }, 'Ücret alınmadan masa kapatılır, ürünler stoğa geri eklenir. Bu işlem kayda geçer.'), field('İptal nedeni', reason)), actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => cm.close() }, 'Vazgeç'), ok] });
      ok.onclick = () => busy(ok, async () => {
        try { await post(`/sessions/${sid}/cancel`, { reason: reason.value }); cm.close(); m.close(); toast('Oturum iptal edildi.', 'good'); load(false); } catch (e) { fail(e); }
      });
    }
    function checkout() {
      checkoutModal({
        title: d.table_name + ' · Hesap', lines: () => [['Oyun', d.game_cents + Math.round((d.rate_per_hour || 0) * (performance.now() - detailAt) / 3600000)], ['Ürünler', d.items_cents]],
        subtotal: () => liveD().amount, live: d.rate_per_hour > 0,
        submit: (payload) => post(`/sessions/${sid}/close`, payload),
        done: (r) => { m.close(); toast(`Hesap kapatıldı · ${money(r.total_cents)}`, 'good', 5000); load(false); },
        onStale: () => { m.close(); load(false); },
      });
    }
    renderAll();
    openSheet = { sid, update: (s) => { if (s.match_count !== d.match_count || s.planned_minutes !== d.planned_minutes || s.items_cents !== d.items_cents || s.controllers !== d.controllers) get('/sessions/' + sid).then((nd) => { d = nd; detailAt = performance.now(); renderAll(); }).catch(() => {}); else { d.elapsed = s.elapsed; d.remaining = s.remaining; d.subtotal_cents = s.subtotal_cents; d.game_cents = s.game_cents; d.rate_per_hour = s.rate_per_hour; detailAt = performance.now(); paint(liveD()); } }, tick: () => paint(liveD()) };
    void closeBtn;
  }

  async function recentModal() {
    let r;
    try { r = await get('/sessions/recent'); } catch (e) { fail(e); return; }
    const m = modal({ title: 'Son hesaplar', wide: true, body: r.items.length ? h('ul', { class: 'list' }, r.items.map((it) => h('li', null,
      h('div', null, h('b', null, it.table_name), h('small', { class: 'muted' }, ' · ' + timeOnly(it.ended_at) + (it.closed_by ? ' · ' + it.closed_by : ''))),
      h('div', { class: 'r' }, it.status === 'cancelled' ? h('span', { class: 'tag bad' }, 'İptal') : h('b', { class: 'mono' }, money(it.total_cents)),
        it.can_reopen && can('session.reopen') ? h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: async (ev) => {
          if (!await confirmBox('Hesabı geri al', `${it.table_name} hesabı yeniden açılsın mı? Alınan ödemeler iptal edilir ve masa tekrar dolu görünür.`, 'Geri al')) return;
          busy(ev.currentTarget, async () => { try { await post(`/sessions/${it.id}/reopen`, {}); m.close(); toast('Hesap geri alındı.', 'good'); load(false); } catch (e) { fail(e); } });
        } }, icon('undo', 14), 'Geri al') : null)))) : empty('Henüz kapatılan hesap yok.') });
  }

  load(true); schedule();
  ticker = setInterval(tick, 1000);
  return () => { stopped = true; clearTimeout(timer); clearInterval(ticker); document.removeEventListener('visibilitychange', onVis); };
}

/**
 * Ortak ödeme penceresi (masa ve hızlı satış).
 * opts: title, lines() -> [[label, cents]], subtotal() -> cents, live, submit(payload) -> Promise, done(result)
 */
export function checkoutModal(opts) {
  const canDisc = can('discount.apply');
  let dtype = 'none', method = 'cash';
  const dval = h('input', { class: 'inp mono', inputmode: 'decimal', placeholder: '0', autocomplete: 'off', name: 'discount_value' });
  const dnote = inp('discount_note', { maxlength: 80, placeholder: 'İndirim notu (isteğe bağlı)' });
  const cash = h('input', { class: 'inp mono', inputmode: 'decimal', placeholder: '0,00', autocomplete: 'off', name: 'cash' });
  const given = h('input', { class: 'inp mono', inputmode: 'decimal', placeholder: 'Alınan nakit', autocomplete: 'off', name: 'given' });
  const linesEl = h('div', { class: 'co-lines' });
  const totalEl = h('b', { class: 'co-total mono' });
  const splitEl = h('div', { class: 'co-split' });
  const changeEl = h('p', { class: 'hint mono' });
  const discBox = h('div', { class: 'field' });

  const calc = () => {
    const sub = opts.subtotal();
    let d = 0;
    if (dtype === 'percent') { const p = parseInt(dval.value, 10); d = p >= 0 && p <= 100 ? Math.round(sub * p / 100) : 0; }
    if (dtype === 'amount') { const c = inputToCents(dval.value); d = Number.isFinite(c) && c !== null ? Math.min(c, sub) : 0; }
    return { sub, d, total: sub - d };
  };
  const paint = () => {
    const c = calc();
    fill(linesEl, ...opts.lines().map(([l, v]) => h('div', { class: 'co-line' }, h('span', null, l), h('span', { class: 'mono' }, money(v)))),
      c.d ? h('div', { class: 'co-line disc' }, h('span', null, 'İndirim'), h('span', { class: 'mono' }, '−' + money(c.d))) : null);
    totalEl.textContent = money(c.total);
    if (method === 'split') {
      const x = inputToCents(cash.value);
      const rest = Number.isFinite(x) && x !== null ? c.total - x : c.total;
      splitEl.querySelector('[data-rest]').textContent = rest >= 0 ? 'Kart: ' + money(rest) : 'Nakit tutar hesabı aşıyor';
    }
    if (method === 'cash') {
      const g = inputToCents(given.value);
      changeEl.textContent = Number.isFinite(g) && g !== null && g >= c.total ? 'Para üstü: ' + money(g - c.total) : '';
    }
  };
  const renderSplit = () => {
    clear(splitEl);
    if (method === 'split') splitEl.append(field('Nakit tutar', cash), h('p', { class: 'hint mono', 'data-rest': '' }));
    if (method === 'cash') splitEl.append(field('Alınan nakit (para üstü için)', given), changeEl);
  };
  if (canDisc) {
    discBox.append(h('span', { class: 'lbl' }, 'İndirim'), seg([['none', 'Yok'], ['percent', '%'], ['amount', '₺']], dtype, (v) => {
      dtype = v; dval.value = ''; dval.disabled = v === 'none'; dval.placeholder = v === 'percent' ? 'Yüzde (0-100)' : 'Tutar'; paint();
    }), h('div', { class: 'row-wrap', style: { marginTop: '8px' } }, dval, dnote));
    dval.disabled = true;
  }
  [dval, cash, given].forEach((i) => i.addEventListener('input', paint));
  const body = h('div', { class: 'form co' }, linesEl, h('div', { class: 'co-sum' }, h('span', null, 'Ödenecek'), totalEl),
    canDisc ? discBox : null,
    h('div', { class: 'field' }, h('span', { class: 'lbl' }, 'Ödeme'), seg([['cash', 'Nakit'], ['card', 'Kart'], ['split', 'Nakit + Kart'], ['other', 'Diğer']], method, (v) => { method = v; renderSplit(); paint(); })),
    splitEl, opts.live ? h('p', { class: 'hint' }, 'Süre işlemeye devam ediyor; kesin tutar onayladığınız anda hesaplanır.') : null);
  renderSplit(); paint();
  const iv = setInterval(paint, 1000);
  const ok = h('button', { class: 'btn btn-primary', type: 'button' }, icon('check', 18), 'Ödemeyi al ve kapat');
  const m = modal({ title: opts.title, body, actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), ok], onClose: () => clearInterval(iv) });
  ok.onclick = () => busy(ok, async () => {
    const payload = { discount_type: dtype, discount_note: dnote.value };
    if (dtype === 'percent') { const p = parseInt(dval.value, 10); if (!(p >= 0 && p <= 100)) { toast('İndirim yüzdesi 0 ile 100 arasında olmalı.', 'bad'); return; } payload.discount_value = p; }
    if (dtype === 'amount') { const c = inputToCents(dval.value); if (!Number.isFinite(c) || c === null) { toast('İndirim tutarı geçersiz.', 'bad'); return; } payload.discount_value = moneyStr(c); }
    if (method === 'split') {
      const x = inputToCents(cash.value);
      if (!Number.isFinite(x) || !x) { toast('Nakit tutarı girin.', 'bad'); return; }
      payload.payments = [{ method: 'cash', amount: x }, { method: 'card', amount: 'rest' }];
    } else {
      payload.payments = [{ method, amount: 'rest' }];
    }
    try {
      const r = await opts.submit(payload);
      m.close(); opts.done(r);
    } catch (e) {
      fail(e);
      if (e instanceof ApiError && (e.code === 'not_active' || e.code === 'conflict')) { m.close(); opts.onStale && opts.onStale(); }
    }
  });
  return m;
}
void METHOD_LABEL;
