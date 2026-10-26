// İlk kurulum sihirbazı: oyun türleri, masa sayıları ve ücretler
import { state, h, clear, icon, get, post, can, toast, fail, busy, moneyInp, inputToCents, moneyStr, empty, fill, add } from './core.js?v=1';

export function setupView(ctx) {
  const { main } = ctx;
  if (state.me.business.setup_done) { location.replace('#/masalar'); return; }
  if (!can('settings.tables')) { add(main, empty('İşletme kurulumu henüz tamamlanmadı. Lütfen işletme sahibinin ya da müdürün kurulumu tamamlamasını bekleyin.')); return; }
  const wrap = h('div', { class: 'setup' });
  add(main, wrap);
  (async () => {
    let presets;
    try { presets = (await get('/setup/presets')).presets; } catch (e) { fail(e); return; }
    const chosen = new Set((state.me.business.types || []).filter((k) => presets.some((p) => p.kind === k)));
    if (!chosen.size) chosen.add('playstation');
    const rows = {};
    const list = h('div', { class: 'setup-list' });
    const render = () => {
      fill(list, ...presets.map((p) => {
        const on = chosen.has(p.kind);
        if (!rows[p.kind]) {
          rows[p.kind] = {
            count: h('input', { class: 'inp mono', inputmode: 'numeric', value: String(p.count), 'aria-label': p.name + ' masa sayısı' }),
            hour: moneyInp('hour', p.hour_price, { 'aria-label': p.name + ' saatlik ücret' }),
            hour4: p.uses_controllers ? moneyInp('hour4', p.hour_price_4, { 'aria-label': p.name + ' 4 kol saatlik ücret' }) : null,
            match: moneyInp('match', p.match_price, { 'aria-label': p.name + ' maç ücreti', placeholder: 'Yok' }),
          };
        }
        const r = rows[p.kind];
        return h('div', { class: 'setup-item' + (on ? ' on' : '') },
          h('button', { type: 'button', class: 'si-head', 'aria-pressed': String(on), onclick: () => { on ? chosen.delete(p.kind) : chosen.add(p.kind); render(); } },
            h('span', { class: 'si-check' }, on ? icon('check', 16) : null), h('b', null, p.name), h('small', { class: 'muted' }, p.uses_controllers ? '2 / 4 kol fiyatı' : 'Saatlik / maç başı')),
          on ? h('div', { class: 'si-body' },
            h('label', { class: 'mini' }, h('span', null, 'Masa sayısı'), r.count),
            h('label', { class: 'mini' }, h('span', null, p.uses_controllers ? 'Saatlik (2 kol) ₺' : 'Saatlik ₺'), r.hour),
            r.hour4 ? h('label', { class: 'mini' }, h('span', null, 'Saatlik (4 kol) ₺'), r.hour4) : null,
            h('label', { class: 'mini' }, h('span', null, 'Maç başı ₺'), r.match)) : null);
      }));
    };
    render();
    const go = h('button', { class: 'btn btn-primary btn-lg', type: 'button' }, 'Kurulumu tamamla', icon('check', 18));
    const skip = h('button', { class: 'btn btn-ghost', type: 'button' }, 'Atla, masaları kendim tanımlayacağım');
    fill(wrap, 
      h('div', { class: 'setup-head' }, h('p', { class: 'eyebrow' }, 'Hoş geldiniz'), h('h2', null, 'Salonunuzu 1 dakikada kuralım.'),
        h('p', { class: 'muted' }, 'Salonunuzdaki oyun türlerini seçin, masa sayılarını ve ücretleri girin. Tüm ayarları daha sonra Ayarlar bölümünden değiştirebilirsiniz.')),
      list, h('div', { class: 'setup-foot' }, skip, go));
    go.onclick = () => busy(go, async () => {
      const items = [];
      for (const p of presets) {
        if (!chosen.has(p.kind)) continue;
        const r = rows[p.kind];
        const count = parseInt(r.count.value, 10);
        const hour = inputToCents(r.hour.value), hour4 = r.hour4 ? inputToCents(r.hour4.value) : null, match = inputToCents(r.match.value);
        if (!(count >= 0 && count <= 200)) { toast(`${p.name}: masa sayısı 0-200 arasında olmalı.`, 'bad'); return; }
        if (hour === null || !Number.isFinite(hour)) { toast(`${p.name}: saatlik ücreti girin.`, 'bad'); return; }
        if (Number.isNaN(hour4) || Number.isNaN(match)) { toast(`${p.name}: ücretleri kontrol edin.`, 'bad'); return; }
        items.push({ kind: p.kind, count, hour_price: moneyStr(hour), hour_price_4: hour4 !== null ? moneyStr(hour4) : null, match_price: match !== null ? moneyStr(match) : null });
      }
      if (!items.length) { toast('En az bir oyun türü seçin.', 'bad'); return; }
      try { await post('/setup/quick', { items }); state.me.business.setup_done = true; toast('Kurulum tamamlandı. İyi çalışmalar!', 'good'); location.hash = '#/masalar'; }
      catch (e) { fail(e); if (e.code === 'already_setup') { state.me.business.setup_done = true; location.hash = '#/masalar'; } }
    });
    skip.onclick = () => busy(skip, async () => {
      try { await post('/setup/skip', {}); state.me.business.setup_done = true; location.hash = '#/ayarlar'; } catch (e) { fail(e); }
    });
  })();
}
