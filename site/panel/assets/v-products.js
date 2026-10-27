// Ürünler ve stok yönetimi
import { productEditors } from './editors.js?v=1';
import { h, clear, icon, get, post, put, del, can, toast, fail, modal, confirmBox, money, busy, field, inp, moneyInp, toggle, showErrors, inputToCents, moneyStr, empty, fill, add } from './core.js?v=1';
import { invalidateMenu } from './v-cafe.js?v=1';

export function productsView(ctx) {
  const { main } = ctx;
  let products = [];
  let q = '', showArchived = false;
  const search = h('input', { class: 'inp', type: 'search', placeholder: 'Ürün ara', 'aria-label': 'Ürün ara' });
  const archT = toggle('arch', 'Satıştan kaldırılanları göster', false);
  const list = h('div', { class: 'card table-card' });
  add(main, h('div', { class: 'toolbar' }, h('div', { class: 'tb-l' }, search, archT),
    h('div', { class: 'tb-r' }, h('button', { class: 'btn btn-primary', type: 'button', onclick: () => edit(null) }, icon('plus', 18), 'Ürün ekle'))), list);
  search.addEventListener('input', () => { q = search.value.trim().toLocaleLowerCase('tr'); render(); });
  archT.querySelector('input').addEventListener('change', (e) => { showArchived = e.target.checked; render(); });

  async function load() {
    try { products = (await get('/products')).products; render(); } catch (e) { fill(list, empty(e.message)); }
  }
  function set(r) { products = r.products; invalidateMenu(); render(); }
  function render() {
    const rows = products.filter((p) => (showArchived || p.is_active) && (!q || p.name.toLocaleLowerCase('tr').includes(q) || p.category.toLocaleLowerCase('tr').includes(q)));
    if (!products.length) { fill(list, empty('Henüz ürün yok. Kafeterya ürünlerinizi ekleyerek masalara ve hızlı satışa ekleyebilirsiniz.', h('button', { class: 'btn btn-primary', type: 'button', onclick: () => edit(null) }, 'İlk ürünü ekleyin'))); return; }
    fill(list, h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' },
      h('thead', null, h('tr', null, h('th', null, 'Ürün'), h('th', null, 'Kategori'), h('th', { class: 'num' }, 'Fiyat'), h('th', { class: 'num' }, 'Stok'), h('th', { class: 'act-col' }, ''))),
      h('tbody', null, rows.map((p) => h('tr', { class: p.is_active ? '' : 'dim' },
        h('td', { 'data-l': 'Ürün' }, h('b', null, p.name), p.is_active ? null : h('span', { class: 'tag' }, 'Satışta değil')),
        h('td', { 'data-l': 'Kategori' }, p.category),
        h('td', { class: 'num mono', 'data-l': 'Fiyat' }, money(p.price_cents)),
        h('td', { class: 'num mono', 'data-l': 'Stok' }, p.track_stock ? h('span', { class: p.stock_qty <= p.low_stock ? 'low' : '' }, String(p.stock_qty)) : h('span', { class: 'muted' }, '—')),
        h('td', { class: 'act-col' }, h('div', { class: 'row-acts' },
          p.track_stock && can('stock.manage') ? h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: () => stock(p) }, 'Stok') : null,
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': p.name + ' düzenle', onclick: () => edit(p) }, icon('edit', 18))))))))));
  }

  const ed = productEditors({ get products() { return products; }, set });
  const edit = ed.edit, stock = ed.stock;
  load();
}
