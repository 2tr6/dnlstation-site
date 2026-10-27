// Kasa / vardiya: açılış, kör sayımla kapanış, Z raporu, geçmiş
import { h, clear, icon, get, post, can, toast, fail, modal, money, dateTime, busy, field, moneyInp, inp, inputToCents, moneyStr, empty, fill, add } from './core.js?v=1';

export function zReport(z, bizName) {
  const diff = z.counted_cash_cents !== null ? z.counted_cash_cents - z.expected_cash_cents : null;
  const row = (l, v, cls) => h('div', { class: 'z-row ' + (cls || '') }, h('span', null, l), h('b', { class: 'mono' }, v));
  return h('div', { class: 'z' },
    h('div', { class: 'z-head' }, h('b', null, bizName), h('span', null, 'Z Raporu · Vardiya #' + z.id), h('small', { class: 'muted' }, dateTime(z.opened_at) + ' → ' + (z.closed_at ? dateTime(z.closed_at) : 'açık'))),
    row('Açan', z.opened_by || '—'), z.closed_by ? row('Kapatan', z.closed_by) : null,
    h('hr'),
    row('Oyun geliri', money(z.game_cents)), row('Ürün satışı', money(z.items_cents)), row('İndirimler', '−' + money(z.discount_cents)),
    row('Toplam tahsilat', money(z.revenue_cents), 'big'),
    h('hr'),
    row('Nakit', money(z.cash_cents)), row('Kart', money(z.card_cents)), row('Diğer', money(z.other_cents)),
    z.credit_cents ? row('Veresiye satış', money(z.credit_cents)) : null,
    z.collections_cents ? row('Veresiye tahsilatı', money(z.collections_cents)) : null,
    z.expense_cents ? row('Giderler', '−' + money(z.expense_cents)) : null,
    h('hr'),
    row('Açılış kasası', money(z.opening_cash_cents)), row('Beklenen nakit', money(z.expected_cash_cents)),
    z.counted_cash_cents !== null ? row('Sayılan nakit', money(z.counted_cash_cents)) : null,
    diff !== null ? row('Fark', (diff > 0 ? '+' : '') + money(diff), diff === 0 ? 'ok' : 'bad') : null,
    h('hr'), row('Hesap sayısı', String(z.tickets)), row('İptal', String(z.voids)),
    z.note ? h('p', { class: 'muted sm' }, 'Not: ' + z.note) : null);
}
export function printNode(node) {
  const wrap = document.createElement('div'); wrap.className = 'print-only'; add(wrap, node.cloneNode(true));
  document.body.append(wrap); document.documentElement.classList.add('printing');
  const done = () => { wrap.remove(); document.documentElement.classList.remove('printing'); window.removeEventListener('afterprint', done); };
  window.addEventListener('afterprint', done); window.print(); setTimeout(done, 1500);
}

export function shiftView(ctx) {
  const { main, me } = ctx;
  const box = h('div', { class: 'stack' });
  const hist = h('section', { class: 'card' });
  add(main, box, hist);

  async function load() {
    let r;
    try { r = await get('/shift'); } catch (e) { fill(box, empty(e.message)); return; }
    ctx.updateShiftChip(r.shift ? r.shift.id : null);
    clear(box);
    if (!r.shift) {
      const cash = moneyInp('opening_cash', 0, { autofocus: true });
      const ok = h('button', { class: 'btn btn-primary btn-lg', type: 'button' }, icon('wallet', 18), 'Kasayı aç');
      add(box, h('section', { class: 'card hero-card' }, h('div', { class: 'hc-ic' }, icon('wallet', 28)), h('h2', null, 'Kasa kapalı'),
        h('p', { class: 'muted' }, 'Gün başında çekmecedeki nakit tutarını girerek kasayı açın. Hesap almak ve satış yapmak için kasa açık olmalı.'),
        h('div', { class: 'form narrow' }, field('Açılış nakdi (₺)', cash), ok)));
      ok.onclick = () => busy(ok, async () => {
        const c = inputToCents(cash.value);
        if (!Number.isFinite(c)) { toast('Tutar geçersiz.', 'bad'); return; }
        try { await post('/shift/open', { opening_cash: moneyStr(c || 0) }); toast('Kasa açıldı.', 'good'); load(); } catch (e) { fail(e); }
      });
    } else {
      const s = r.shift;
      const stats = r.can_see_totals ? h('div', { class: 'kpis' },
        k('Toplam tahsilat', money(s.revenue_cents)), k('Nakit', money(s.cash_cents)), k('Kart', money(s.card_cents)), k('Hesap', String(s.tickets)),
        k('Veresiye satış', money(s.credit_cents || 0)), k('Veresiye tahsilatı', money(s.collections_cents || 0)), k('Kasadan gider', money(s.expense_cash_cents || 0)), k('Kasada olması gereken', money(s.expected_cash_cents))) : null;
      const counted = moneyInp('counted_cash', null);
      const note = inp('note', { maxlength: 200, placeholder: 'İsteğe bağlı' });
      const ok = h('button', { class: 'btn btn-primary', type: 'button' }, 'Kasayı kapat ve Z raporu al');
      add(box, h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Açık vardiya'), h('span', { class: 'chip on' }, h('i'), 'Açık')),
        h('p', { class: 'muted' }, `${dateTime(s.opened_at)} · ${s.opened_by || ''}`), stats),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Kasayı kapat')),
        h('p', { class: 'muted' }, 'Çekmecedeki nakdi sayıp girin. Sistem beklenen tutarla karşılaştırıp farkı rapora yazar. Açık masa varken kasa kapatılamaz.'),
        h('div', { class: 'form narrow' }, field('Sayılan nakit (₺)', counted), field('Not', note), ok)));
      ok.onclick = () => busy(ok, async () => {
        const c = inputToCents(counted.value);
        if (c === null || !Number.isFinite(c)) { toast('Sayılan nakit tutarını girin.', 'bad'); return; }
        try {
          const res = await post('/shift/close', { counted_cash: moneyStr(c), note: note.value });
          showZ(res.report); load();
        } catch (e) { fail(e); }
      });
    }
    if (can('reports.view')) loadHist();
  }
  function k(l, v) { return h('div', { class: 'kpi' }, h('div', null, h('small', null, l), h('b', { class: 'mono' }, v))); }
  function showZ(z) {
    const node = zReport(z, me.business.name);
    const m = modal({ title: 'Z Raporu', body: node, actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => printNode(node) }, icon('print', 16), 'Yazdır'), h('button', { class: 'btn btn-primary', type: 'button', onclick: () => m.close() }, 'Tamam')] });
  }
  async function loadHist() {
    try {
      const r = await get('/shifts');
      fill(hist, h('div', { class: 'card-h' }, h('h2', null, 'Vardiya geçmişi')),
        r.shifts.length ? h('ul', { class: 'list' }, r.shifts.map((s) => h('li', { class: 'click', tabindex: '0', role: 'button', onclick: () => showZ(s), onkeydown: (e) => { if (e.key === 'Enter') showZ(s); } },
          h('div', null, h('b', null, '#' + s.id + ' · ' + dateTime(s.opened_at)), h('small', { class: 'muted' }, ' ' + (s.closed_at ? '→ ' + dateTime(s.closed_at) : '(açık)'))),
          h('div', { class: 'r' }, h('b', { class: 'mono' }, money(s.revenue_cents)),
            s.counted_cash_cents !== null && s.counted_cash_cents !== s.expected_cash_cents ? h('span', { class: 'tag bad' }, 'Fark ' + money(s.counted_cash_cents - s.expected_cash_cents)) : null)))) : h('p', { class: 'muted sm' }, 'Henüz vardiya yok.'));
    } catch { clear(hist); }
  }
  load();
}
