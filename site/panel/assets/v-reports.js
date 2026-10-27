// Raporlar: tarih aralığı, özet, günlük grafik, kırılımlar, CSV
import { h, clear, icon, get, fail, money, money0, localDate, empty, seg, fill, add } from './core.js?v=1';

function addDays(ymd, n) {
  const [y, m, d] = ymd.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + n));
  return dt.toISOString().slice(0, 10);
}
function presetRange(p) {
  const today = localDate();
  switch (p) {
    case 'today': return [today, today];
    case 'yesterday': return [addDays(today, -1), addDays(today, -1)];
    case '7': return [addDays(today, -6), today];
    case '30': return [addDays(today, -29), today];
    case 'month': return [today.slice(0, 8) + '01', today];
    default: return [today, today];
  }
}

function barChart(series) {
  const W = 720, H = 220, P = { l: 8, r: 8, t: 14, b: 26 };
  const max = Math.max(1, ...series.map((s) => s.total_cents));
  const n = series.length; const bw = (W - P.l - P.r) / n;
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`); svg.setAttribute('class', 'chart'); svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', 'Günlük tahsilat grafiği');
  const mk = (tag, attrs, text) => { const e = document.createElementNS(ns, tag); for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v); if (text !== undefined) e.textContent = text; svg.append(e); return e; };
  for (let i = 0; i <= 3; i++) { const y = P.t + (H - P.t - P.b) * i / 3; mk('line', { x1: P.l, x2: W - P.r, y1: y, y2: y, class: 'grid' }); }
  const every = Math.ceil(n / 10);
  series.forEach((s, i) => {
    const hgt = (H - P.t - P.b) * s.total_cents / max; const x = P.l + i * bw + bw * 0.18; const w = Math.max(2, bw * 0.64);
    const r = mk('rect', { x, y: H - P.b - hgt, width: w, height: Math.max(hgt, s.total_cents ? 2 : 0), rx: Math.min(4, w / 3), class: 'bar' });
    const t = document.createElementNS(ns, 'title'); t.textContent = `${s.date.split('-').reverse().join('.')}: ${money(s.total_cents)}`; r.append(t);
    if (i % every === 0) mk('text', { x: x + w / 2, y: H - 8, 'text-anchor': 'middle', class: 'lbl' }, s.date.slice(8) + '.' + s.date.slice(5, 7));
  });
  mk('text', { x: W - P.r, y: P.t - 2, 'text-anchor': 'end', class: 'lbl' }, money0(max));
  return svg;
}

/** Haftanın günü × saat yoğunluk haritası (başlayan masa sayısı) */
function heatmap(m) {
  if (!m) return null;
  const max = Math.max(0, ...m.flat());
  const days = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];
  let best = null;
  m.forEach((row, d) => row.forEach((v, hr) => { if (v > 0 && (!best || v > best.v)) best = { v, d, hr }; }));
  const grid = h('div', { class: 'heat', role: 'img', 'aria-label': 'Saatlere göre yoğunluk haritası' },
    h('span'), ...Array.from({ length: 24 }, (_, i) => h('small', { class: 'hh' }, i % 3 === 0 ? String(i).padStart(2, '0') : '')),
    ...m.flatMap((row, d) => [h('small', { class: 'hd' }, days[d]), ...row.map((v, hr) => h('i', { title: `${days[d]} ${String(hr).padStart(2, '0')}:00 · ${v} masa`, style: { opacity: max ? String(v ? 0.18 + 0.82 * v / max : 0.05) : '0.05' } }))]));
  return h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'En yoğun saatler'),
    best ? h('span', { class: 'tag ok' }, `En yoğun: ${days[best.d]} ${String(best.hr).padStart(2, '0')}:00`) : null),
    max ? h('div', { class: 'heat-wrap' }, grid) : h('p', { class: 'muted sm' }, 'Bu aralıkta kapanmış masa oturumu yok.'),
    h('p', { class: 'hint' }, 'Koyu kutular, o gün ve saatte daha çok masa açıldığını gösterir. Personel ve kampanya planlamasında kullanın.'));
}

function breakdown(title, rows, cols) {
  return h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, title)),
    rows.length ? h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl compact' },
      h('thead', null, h('tr', null, cols.map((c) => h('th', { class: c.num ? 'num' : '' }, c.label)))),
      h('tbody', null, rows.map((r) => h('tr', null, cols.map((c) => h('td', { class: c.num ? 'num mono' : '', 'data-l': c.label }, c.get(r)))))))) : h('p', { class: 'muted sm' }, 'Bu aralıkta kayıt yok.'));
}

export function reportsView(ctx) {
  const { main } = ctx;
  let preset = 'today';
  let [from, to] = presetRange(preset);
  const fromI = h('input', { class: 'inp mono', type: 'date', value: from, 'aria-label': 'Başlangıç' });
  const toI = h('input', { class: 'inp mono', type: 'date', value: to, 'aria-label': 'Bitiş' });
  const csv = h('a', { class: 'btn btn-ghost', href: '#', download: '' }, icon('download', 16), 'CSV indir');
  const out = h('div', { class: 'stack' });
  const custom = h('div', { class: 'row-wrap date-range' }, fromI, h('span', { class: 'muted' }, '—'), toI);
  const presets = seg([['today', 'Bugün'], ['yesterday', 'Dün'], ['7', '7 gün'], ['30', '30 gün'], ['month', 'Bu ay']], preset, (v) => { preset = v; [from, to] = presetRange(v); fromI.value = from; toI.value = to; load(); });
  add(main, h('div', { class: 'toolbar wrap' }, presets, custom, h('div', { class: 'tb-r' }, csv)), out);
  const onDate = () => { if (fromI.value && toI.value) { from = fromI.value; to = toI.value; presets.querySelectorAll('button').forEach((b) => b.classList.remove('on')); load(); } };
  fromI.addEventListener('change', onDate); toI.addEventListener('change', onDate);

  async function load() {
    csv.href = `/api/reports/tickets.csv?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
    let r;
    try { r = await get(`/reports/summary?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`); } catch (e) { fail(e); return; }
    const t = r.totals;
    const k = (l, v, sub) => h('div', { class: 'kpi' }, h('div', null, h('small', null, l), h('b', { class: 'mono' }, v), sub ? h('em', null, sub) : null));
    const pv = r.previous || { revenue: 0, tickets: 0 };
    const delta = pv.revenue > 0 ? Math.round((t.revenue - pv.revenue) * 100 / pv.revenue) : null;
    const deltaTxt = delta === null ? 'Önceki dönem: veri yok' : `Önceki döneme göre ${delta > 0 ? '+' : ''}${delta}%`;
    const mt = r.methods; const all = mt.cash + mt.card + mt.other || 1;
    fill(out, 
      h('div', { class: 'kpis' }, k('Toplam tahsilat', money(t.revenue), `${t.tickets} hesap · ${deltaTxt}`), k('Oyun', money(t.game)), k('Ürün', money(t.items)),
        k('Ortalama hesap', money(t.avg_ticket)), k('İndirim', money(t.discount)), k('İptal', String(t.voids))),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Günlük tahsilat')), r.series.length > 1 ? barChart(r.series) : h('p', { class: 'muted sm' }, 'Tek günlük aralıkta grafik gösterilmez; daha geniş bir aralık seçin.'),
        h('div', { class: 'split-bar', 'aria-label': 'Ödeme yöntemleri' },
          h('i', { class: 'cash', style: { flexGrow: String(mt.cash) } }), h('i', { class: 'card', style: { flexGrow: String(mt.card) } }), h('i', { class: 'other', style: { flexGrow: String(mt.other) } })),
        h('div', { class: 'legend' },
          h('span', null, h('i', { class: 'cash' }), `Nakit ${money(mt.cash)} (${Math.round(mt.cash * 100 / all)}%)`),
          h('span', null, h('i', { class: 'card' }), `Kart ${money(mt.card)} (${Math.round(mt.card * 100 / all)}%)`),
          mt.other ? h('span', null, h('i', { class: 'other' }), `Diğer ${money(mt.other)}`) : null)),
      heatmap(r.heatmap),
      h('div', { class: 'grid2' },
        breakdown('Oyun türüne göre', r.by_type, [{ label: 'Tür', get: (x) => x.name }, { label: 'Oturum', num: 1, get: (x) => String(x.count) }, { label: 'Oyun', num: 1, get: (x) => money(x.game_cents) }, { label: 'Ürün', num: 1, get: (x) => money(x.items_cents) }]),
        breakdown('En çok satan ürünler', r.top_products, [{ label: 'Ürün', get: (x) => x.name }, { label: 'Adet', num: 1, get: (x) => String(x.qty) }, { label: 'Tutar', num: 1, get: (x) => money(x.total_cents) }])),
      h('div', { class: 'grid2' },
        breakdown('Masalara göre', r.by_table, [{ label: 'Masa', get: (x) => x.name }, { label: 'Oturum', num: 1, get: (x) => String(x.count) }, { label: 'Oyun', num: 1, get: (x) => money(x.game_cents) }]),
        breakdown('Personele göre', r.by_staff, [{ label: 'Personel', get: (x) => x.name }, { label: 'Tahsilat', num: 1, get: (x) => money(x.total_cents) }])));
    if (!t.tickets) out.prepend(empty('Seçilen aralıkta kapatılmış hesap yok.'));
  }
  load();
}
