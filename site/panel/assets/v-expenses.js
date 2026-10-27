// Giderler: kira, fatura, ürün alımı... Kasadan nakit gider açık vardiyadan düşer.
import { h, fill, add, icon, get, post, toast, fail, modal, confirmBox, money, dateTime, busy, field, inp, sel, moneyInp, showErrors, inputToCents, moneyStr, empty, seg, localDate, METHOD_LABEL } from './core.js?v=1';

export function expensesView(ctx) {
  const { main } = ctx;
  const today = localDate();
  let month = today.slice(0, 7), data = null;
  const mInp = h('input', { class: 'inp mono', type: 'month', value: month, 'aria-label': 'Ay' });
  const sum = h('div', { class: 'kpis' });
  const cats = h('section', { class: 'card' });
  const list = h('section', { class: 'card' });
  add(main, h('div', { class: 'toolbar' }, h('div', { class: 'tb-l' }, mInp), h('div', { class: 'tb-r' }, h('button', { class: 'btn btn-primary', type: 'button', onclick: addModal }, icon('plus', 18), 'Gider ekle'))), sum, h('div', { class: 'grid2' }, cats, list));
  mInp.addEventListener('change', () => { if (mInp.value) { month = mInp.value; load(); } });
  const lastDay = (ym) => { const [y, m] = ym.split('-').map(Number); return `${ym}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}`; };
  async function load() {
    const to = lastDay(month) > today ? today : lastDay(month);
    try { data = await get(`/expenses?from=${month}-01&to=${to}`); render(); } catch (e) { fail(e); }
  }
  function render() {
    const d = data;
    fill(sum, h('div', { class: 'kpi' }, h('div', null, h('small', null, 'Bu ayki gider'), h('b', { class: 'mono' }, money(d.total_cents)))),
      h('div', { class: 'kpi' }, h('div', null, h('small', null, 'Kayıt'), h('b', { class: 'mono' }, String(d.items.filter((x) => !x.voided).length)))));
    fill(cats, h('div', { class: 'card-h' }, h('h2', null, 'Kategoriye göre')),
      d.by_category.length ? h('ul', { class: 'list' }, d.by_category.map((c) => h('li', null, h('span', null, c.category), h('b', { class: 'mono' }, money(c.total_cents))))) : h('p', { class: 'muted sm' }, 'Bu ay gider yok.'));
    fill(list, h('div', { class: 'card-h' }, h('h2', null, 'Giderler')),
      d.items.length ? h('ul', { class: 'list' }, d.items.map((x) => h('li', { class: x.voided ? 'void' : '' },
        h('div', null, h('b', null, x.category), ' ', h('span', { class: 'tag' }, METHOD_LABEL[x.method] || x.method), x.shift_id ? h('span', { class: 'tag' }, 'Kasadan') : null, x.voided ? h('span', { class: 'tag bad' }, 'İptal') : null,
          h('div', { class: 'muted sm' }, x.spent_on.split('-').reverse().join('.'), x.by ? ' · ' + x.by : '', x.note ? ' · ' + x.note : '')),
        h('div', { class: 'r' }, h('b', { class: 'mono' }, money(x.amount_cents)), x.voided ? null : h('button', { class: 'icon-btn sm', type: 'button', 'aria-label': 'Gideri iptal et', onclick: async (ev) => {
          if (!await confirmBox('Gideri iptal et', `${x.category} · ${money(x.amount_cents)} iptal edilsin mi?`, 'İptal et', true)) return;
          busy(ev.currentTarget, async () => { try { data = await post(`/expenses/${x.id}/void`, {}); render(); load(); toast('Gider iptal edildi.', 'good'); } catch (e) { fail(e); } });
        } }, icon('trash', 16)))))) : empty('Bu ay kayıtlı gider yok.'));
  }
  function addModal() {
    let method = 'cash';
    const catSel = sel('category', (data ? data.categories : ['Diğer']).map((c) => [c, c]), 'Ürün alımı');
    const amt = moneyInp('amount', null, { autofocus: true });
    const date = inp('spent_on', { type: 'date', class: 'inp mono', value: today, max: today });
    const hint = h('p', { class: 'hint' });
    const paint = () => { hint.textContent = method === 'cash' ? 'Kasadan nakit çıkışı: açık vardiyanın beklenen nakdinden düşülür (kasa açık olmalı).' : 'Kasa dışı ödeme (banka, kart): kasayı etkilemez, raporlarda gider olarak görünür.'; };
    const f = h('form', { class: 'form', novalidate: true }, h('div', { class: 'grid2' }, field('Kategori', catSel), field('Tutar (₺)', amt)),
      h('div', { class: 'field' }, h('span', { class: 'lbl' }, 'Ödeme şekli'), seg([['cash', 'Kasadan nakit'], ['card', 'Kart'], ['other', 'Banka / diğer']], method, (v) => { method = v; paint(); })), hint,
      h('div', { class: 'grid2' }, field('Tarih', date), field('Not', inp('note', { maxlength: 200, placeholder: 'ör. 2 koli kola' }))));
    paint();
    const ok = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Kaydet');
    const m = modal({ title: 'Gider ekle', body: f, actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), ok] });
    const run = (e) => { e && e.preventDefault(); busy(ok, async () => {
      const v = inputToCents(amt.value);
      try { await post('/expenses', { category: catSel.value, amount: Number.isFinite(v) && v !== null ? moneyStr(v) : amt.value, method, note: f.querySelector('[name=note]').value, spent_on: date.value }); m.close(); toast('Gider kaydedildi.', 'good'); load(); }
      catch (err) { if (!showErrors(f, err)) fail(err); }
    }); };
    f.addEventListener('submit', run); ok.onclick = run;
  }
  load();
  void dateTime;
}
