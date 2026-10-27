// Müşteriler ve veresiye (cari hesap)
import { h, clear, fill, add, icon, get, post, put, can, toast, fail, modal, money, dateTime, busy, field, inp, moneyInp, toggle, showErrors, inputToCents, moneyStr, empty, seg } from './core.js?v=1';

export const balanceTag = (c) => c.balance_cents > 0 ? h('span', { class: 'tag bad mono' }, 'Borç ' + money(c.balance_cents)) : c.balance_cents < 0 ? h('span', { class: 'tag ok mono' }, 'Alacak ' + money(-c.balance_cents)) : h('span', { class: 'tag' }, 'Borç yok');

/** Hızlı müşteri ekleme (veresiye seçerken de kullanılır) */
export function customerForm(c, onSaved) {
  const manage = can('customers.manage');
  const f = h('form', { class: 'form', novalidate: true },
    field('Ad soyad', inp('name', { maxlength: 80, value: c ? c.name : '', autofocus: true })),
    field('Telefon', inp('phone', { inputmode: 'tel', value: c ? c.phone : '', placeholder: 'İsteğe bağlı' })),
    field('Not', inp('note', { maxlength: 200, value: c ? c.note : '', placeholder: 'ör. Her cuma gelir' })),
    manage ? field('Veresiye limiti (₺)', moneyInp('credit_limit', c ? c.credit_limit_cents : null, { placeholder: 'Sınırsız' }), 'Bu tutarın üstünde veresiye yazılamaz.') : null,
    manage && c ? toggle('is_active', 'Aktif (veresiye yazılabilir)', c.is_active) : null);
  const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Kaydet');
  const m = modal({ title: c ? 'Müşteriyi düzenle' : 'Yeni müşteri', body: f, actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), save] });
  const run = (e) => {
    e && e.preventDefault();
    busy(save, async () => {
      const q = (n) => f.querySelector(`[name=${n}]`);
      const body = { name: q('name').value, phone: q('phone').value, note: q('note').value };
      if (manage) { const lc = inputToCents(q('credit_limit').value); body.credit_limit = lc === null ? null : (Number.isFinite(lc) ? moneyStr(lc) : q('credit_limit').value); body.is_active = c ? q('is_active').checked : true; }
      try { const r = c ? await put('/customers/' + c.id, body) : await post('/customers', body); m.close(); toast('Kaydedildi.', 'good'); onSaved && onSaved(r); } catch (err) { if (!showErrors(f, err)) fail(err); }
    });
  };
  f.addEventListener('submit', run); save.onclick = run;
}

/** Veresiye için müşteri seçici */
export async function pickCustomer(onPick) {
  let list;
  try { list = (await get('/customers')).customers.filter((c) => c.is_active); } catch (e) { fail(e); return; }
  const search = h('input', { class: 'inp', type: 'search', placeholder: 'Müşteri ara', autofocus: true });
  const box = h('div', { class: 'cust-pick' });
  const draw = () => {
    const q = search.value.trim().toLocaleLowerCase('tr');
    const rows = list.filter((c) => !q || c.name.toLocaleLowerCase('tr').includes(q) || c.phone.includes(q));
    fill(box, ...(rows.length ? rows.map((c) => h('button', { type: 'button', class: 'pick-row', onclick: () => { m.close(); onPick(c); } }, h('b', null, c.name), balanceTag(c))) : [h('p', { class: 'muted sm' }, 'Müşteri bulunamadı.')]));
  };
  search.addEventListener('input', draw);
  const m = modal({ title: 'Veresiye: müşteri seçin', body: h('div', { class: 'form' }, search, box),
    actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => { m.close(); customerForm(null, (r) => { const c = r.customers.find((x) => x.id === r.id); if (c) onPick(c); }); } }, icon('plus', 16), 'Yeni müşteri')] });
  draw();
}

export function customersView(ctx) {
  const { main } = ctx;
  let list = [], q = '', filter = 'all';
  const kpis = h('div', { class: 'kpis' });
  const search = h('input', { class: 'inp', type: 'search', placeholder: 'Ad ya da telefon ara', 'aria-label': 'Ara' });
  const chips = h('div', { class: 'chips' });
  const box = h('div', { class: 'card table-card' });
  add(main, kpis, h('div', { class: 'toolbar' }, h('div', { class: 'tb-l' }, search, chips), h('div', { class: 'tb-r' }, h('button', { class: 'btn btn-primary', type: 'button', onclick: () => customerForm(null, load) }, icon('plus', 18), 'Müşteri ekle'))), box);
  search.addEventListener('input', () => { q = search.value.trim().toLocaleLowerCase('tr'); render(); });
  const drawChips = () => fill(chips, ...[['all', 'Tümü'], ['debt', 'Borçlu'], ['passive', 'Pasif']].map(([k, l]) => h('button', { type: 'button', class: 'chip-btn' + (filter === k ? ' on' : ''), onclick: () => { filter = k; drawChips(); render(); } }, l)));
  async function load() {
    try {
      const r = await get('/customers'); list = r.customers;
      fill(kpis, h('div', { class: 'kpi' }, h('div', null, h('small', null, 'Toplam veresiye alacağı'), h('b', { class: 'mono' }, money(r.total_debt_cents)))),
        h('div', { class: 'kpi' }, h('div', null, h('small', null, 'Borçlu müşteri'), h('b', { class: 'mono' }, String(list.filter((c) => c.balance_cents > 0).length)))),
        h('div', { class: 'kpi' }, h('div', null, h('small', null, 'Toplam müşteri'), h('b', { class: 'mono' }, String(list.length)))));
      render();
    } catch (e) { fill(box, empty(e.message)); }
  }
  function render() {
    const rows = list.filter((c) => (!q || c.name.toLocaleLowerCase('tr').includes(q) || c.phone.includes(q)) && (filter === 'all' ? c.is_active : filter === 'debt' ? c.balance_cents > 0 : !c.is_active));
    if (!list.length) { fill(box, empty('Henüz müşteri yok. Düzenli müşterilerinizi ekleyerek veresiye takibi yapabilirsiniz.', h('button', { class: 'btn btn-primary', type: 'button', onclick: () => customerForm(null, load) }, 'İlk müşteriyi ekleyin'))); return; }
    fill(box, rows.length ? h('ul', { class: 'list cust-list' }, rows.map((c) => h('li', { class: 'click' + (c.is_active ? '' : ' dim'), tabindex: '0', role: 'button', onclick: () => detail(c.id), onkeydown: (e) => { if (e.key === 'Enter') detail(c.id); } },
      h('div', null, h('b', null, c.name), h('div', { class: 'muted sm' }, c.phone || '—', c.note ? ' · ' + c.note : '')), h('div', { class: 'r' }, balanceTag(c))))) : h('p', { class: 'muted sm', style: { padding: '12px' } }, 'Eşleşen müşteri yok.'));
  }
  async function detail(id) {
    let r;
    try { r = await get('/customers/' + id); } catch (e) { fail(e); return; }
    const c = r.customer;
    const KIND = { sale: 'Veresiye satış', collection: 'Tahsilat', reversal: 'İptal / geri alma' };
    const m = modal({ title: c.name, wide: true, body: h('div', { class: 'form' },
      h('div', { class: 'cust-head' }, balanceTag(c), c.credit_limit_cents !== null ? h('span', { class: 'tag' }, 'Limit ' + money(c.credit_limit_cents)) : null, c.phone ? h('a', { class: 'btn btn-ghost sm', href: 'tel:' + c.phone }, 'Ara') : null,
        c.phone && c.balance_cents > 0 ? h('a', { class: 'btn btn-ghost sm', href: `https://wa.me/90${c.phone.replace(/^0/, '')}?text=${encodeURIComponent(`Merhaba ${c.name}, ${ctx.me.business.name} hesabınızda ${money(c.balance_cents)} borç bulunmaktadır.`)}`, target: '_blank', rel: 'noopener noreferrer' }, 'WhatsApp hatırlat') : null),
      r.moves.length ? h('ul', { class: 'list' }, r.moves.map((x) => h('li', null, h('div', null, h('b', null, KIND[x.kind] || x.kind), h('div', { class: 'muted sm' }, dateTime(x.at), x.by ? ' · ' + x.by : '', x.note ? ' · ' + x.note : '')),
        h('b', { class: 'mono ' + (x.amount_cents > 0 ? 'danger-t' : 'ok-t') }, (x.amount_cents > 0 ? '+' : '−') + money(Math.abs(x.amount_cents)))))) : h('p', { class: 'muted sm' }, 'Hareket yok.')),
    actions: [
      can('customers.manage') ? h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => { m.close(); customerForm(c, () => { load(); }); } }, icon('edit', 16), 'Düzenle') : null,
      c.balance_cents > 0 ? h('button', { class: 'btn btn-primary', type: 'button', onclick: () => { m.close(); collect(c); } }, icon('wallet', 16), 'Tahsilat al') : h('button', { class: 'btn btn-primary', type: 'button', onclick: () => m.close() }, 'Kapat')] });
  }
  function collect(c) {
    let method = 'cash';
    const amt = moneyInp('amount', c.balance_cents, { autofocus: true });
    const f = h('form', { class: 'form', novalidate: true }, h('p', { class: 'muted' }, 'Borç: ', h('b', { class: 'mono' }, money(c.balance_cents))), field('Tahsil edilen (₺)', amt),
      h('div', { class: 'field' }, h('span', { class: 'lbl' }, 'Ödeme'), seg([['cash', 'Nakit'], ['card', 'Kart'], ['other', 'Diğer']], method, (v) => { method = v; })), field('Not', inp('note', { maxlength: 200 })));
    const ok = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Tahsil et');
    const m = modal({ title: c.name + ' · Tahsilat', body: f, actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), ok] });
    const run = (e) => { e && e.preventDefault(); busy(ok, async () => {
      const v = inputToCents(amt.value);
      try { await post(`/customers/${c.id}/collect`, { amount: Number.isFinite(v) && v !== null ? moneyStr(v) : amt.value, method, note: f.querySelector('[name=note]').value }); m.close(); toast('Tahsilat alındı.', 'good'); load(); } catch (err) { if (!showErrors(f, err)) fail(err); }
    }); };
    f.addEventListener('submit', run); ok.onclick = run;
  }
  drawChips(); load();
  void clear;
}
