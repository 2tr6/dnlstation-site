// Ürünler ve stok yönetimi
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

  function edit(p) {
    const f = h('form', { class: 'form', novalidate: true },
      field('Ürün adı', inp('name', { maxlength: 60, value: p ? p.name : '', required: true, autofocus: true })),
      h('div', { class: 'grid2' },
        field('Kategori', inp('category', { maxlength: 40, value: p ? p.category : '', placeholder: 'ör. İçecek', list: 'cat-list' })),
        field('Fiyat (₺)', moneyInp('price', p ? p.price_cents : null))),
      h('datalist', { id: 'cat-list' }, Array.from(new Set(products.map((x) => x.category))).map((c) => h('option', { value: c }))),
      toggle('track_stock', 'Stok takibi yap', p ? p.track_stock : false),
      h('div', { class: 'grid2 stock-fields' },
        !p ? field('Başlangıç stoğu', inp('initial_stock', { inputmode: 'numeric', class: 'inp mono', value: '0' })) : null,
        field('Kritik stok uyarısı', inp('low_stock', { inputmode: 'numeric', class: 'inp mono', value: p ? String(p.low_stock) : '0' }), 'Bu adetin altına düşünce uyarı verilir.')),
      p ? toggle('is_active', 'Satışta', p.is_active) : null);
    const sf = f.querySelector('.stock-fields'); const ts = f.querySelector('[name=track_stock]');
    const sync = () => { sf.hidden = !ts.checked; }; ts.addEventListener('change', sync); sync();
    const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Kaydet');
    const acts = [p && p.is_active ? h('button', { class: 'btn btn-ghost danger-t', type: 'button', onclick: async () => {
      if (!await confirmBox('Satıştan kaldır', `“${p.name}” satıştan kaldırılsın mı? Geçmiş satış kayıtları korunur.`, 'Kaldır', true)) return;
      try { set(await del('/products/' + p.id)); m.close(); toast('Ürün satıştan kaldırıldı.', 'good'); } catch (e) { fail(e); }
    } }, 'Satıştan kaldır') : null, h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), save];
    const m = modal({ title: p ? 'Ürünü düzenle' : 'Yeni ürün', body: f, actions: acts });
    const submit = (ev) => {
      ev && ev.preventDefault();
      busy(save, async () => {
        const fd = new FormData(f);
        const price = inputToCents(fd.get('price'));
        const body = {
          name: fd.get('name'), category: fd.get('category'), price: Number.isFinite(price) && price !== null ? moneyStr(price) : String(fd.get('price') || ''),
          track_stock: ts.checked, low_stock: parseInt(fd.get('low_stock') || '0', 10) || 0, is_active: p ? f.querySelector('[name=is_active]').checked : true,
        };
        if (!p) body.initial_stock = parseInt(fd.get('initial_stock') || '0', 10) || 0;
        try { set(p ? await put('/products/' + p.id, body) : await post('/products', body)); m.close(); toast(p ? 'Ürün güncellendi.' : 'Ürün eklendi.', 'good'); }
        catch (e) { if (!showErrors(f, e)) fail(e); }
      });
    };
    f.addEventListener('submit', submit); save.onclick = submit;
  }

  function stock(p) {
    let mode = 'in';
    const qty = inp('qty', { inputmode: 'numeric', class: 'inp mono', placeholder: 'Adet', autofocus: true });
    const lbl = h('p', { class: 'hint' });
    const paint = () => { lbl.textContent = mode === 'in' ? `Mevcut ${p.stock_qty} adete eklenecek.` : `Sayımda bulduğunuz adedi girin (şu an sistemde ${p.stock_qty}).`; };
    const tabs = h('div', { class: 'seg' },
      h('button', { type: 'button', class: 'on', onclick: (e) => { mode = 'in'; tabs.querySelectorAll('button').forEach((b) => b.classList.remove('on')); e.currentTarget.classList.add('on'); paint(); } }, 'Stok girişi'),
      h('button', { type: 'button', onclick: (e) => { mode = 'count'; tabs.querySelectorAll('button').forEach((b) => b.classList.remove('on')); e.currentTarget.classList.add('on'); paint(); } }, 'Sayım'));
    paint();
    const ok = h('button', { class: 'btn btn-primary', type: 'button' }, 'Kaydet');
    const m = modal({ title: p.name + ' · Stok', body: h('div', { class: 'form' }, tabs, field('Adet', qty), lbl), actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), ok] });
    ok.onclick = () => busy(ok, async () => {
      const n = parseInt(qty.value, 10);
      if (!(n >= (mode === 'in' ? 1 : 0))) { toast('Geçerli bir adet girin.', 'bad'); return; }
      try { set(await post(`/products/${p.id}/${mode === 'in' ? 'restock' : 'count'}`, { qty: n })); m.close(); toast('Stok güncellendi.', 'good'); } catch (e) { fail(e); }
    });
  }
  load();
}
