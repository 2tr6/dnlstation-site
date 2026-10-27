// Kafeterya: masasız hızlı satış (POS) ve ortak ürün seçici
import { h, $, clear, icon, get, post, can, toast, fail, modal, confirmBox, money, timeOnly, busy, empty, fill, add } from './core.js?v=1';
import { checkoutModal } from './v-tables.js?v=1';
import { printReceipt } from './receipt.js?v=1';

let menuCache = null, menuAt = 0;
async function menu(force) {
  if (!force && menuCache && Date.now() - menuAt < 30000) return menuCache;
  menuCache = (await get('/menu')).products; menuAt = Date.now();
  return menuCache;
}
export function invalidateMenu() { menuCache = null; }

/** Kategori + arama ile ürün ızgarası */
function productGrid(products, onPick) {
  const cats = ['Tümü', ...Array.from(new Set(products.map((p) => p.category)))];
  let cat = 'Tümü', q = '';
  const search = h('input', { class: 'inp', type: 'search', placeholder: 'Ürün ara', 'aria-label': 'Ürün ara', autocomplete: 'off' });
  const chips = h('div', { class: 'chips' });
  const grid = h('div', { class: 'pgrid' });
  const norm = (s) => s.toLocaleLowerCase('tr');
  const render = () => {
    fill(chips, ...cats.map((c) => h('button', { type: 'button', class: 'chip-btn' + (c === cat ? ' on' : ''), onclick: () => { cat = c; render(); } }, c)));
    const list = products.filter((p) => (cat === 'Tümü' || p.category === cat) && (!q || norm(p.name).includes(norm(q))));
    fill(grid, ...(list.length ? list.map((p) => {
      const out = p.track_stock && p.stock_qty <= 0;
      return h('button', { type: 'button', class: 'pcard' + (out ? ' out' : ''), disabled: out, onclick: (ev) => onPick(p, ev.currentTarget) },
        h('b', null, p.name), h('span', { class: 'mono' }, money(p.price_cents)),
        p.track_stock ? h('small', { class: p.stock_qty <= p.low_stock ? 'low' : 'muted' }, out ? 'Stokta yok' : p.stock_qty + ' adet') : null);
    }) : [empty(products.length ? 'Eşleşen ürün yok.' : 'Henüz ürün eklenmemiş.', products.length || !can('products.manage') ? null : h('a', { class: 'btn btn-primary sm', href: '#/urunler' }, 'Ürün ekleyin'))]));
  };
  search.addEventListener('input', () => { q = search.value.trim(); render(); });
  render();
  return h('div', { class: 'pg' }, h('div', { class: 'pg-top' }, search, chips), grid);
}

/** Masaya ürün ekleme penceresi */
export async function productPicker({ title, onPick }) {
  let products;
  try { products = await menu(true); } catch (e) { fail(e); return; }
  let qty = 1;
  const qtyEl = h('b', { class: 'mono' }, '1');
  const setQty = (v) => { qty = Math.max(1, Math.min(20, v)); qtyEl.textContent = String(qty); };
  const stepper = h('div', { class: 'stepper' },
    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Adet azalt', onclick: () => setQty(qty - 1) }, icon('minus')), qtyEl,
    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Adet artır', onclick: () => setQty(qty + 1) }, icon('plus')));
  const body = h('div', null, h('div', { class: 'pick-qty' }, h('span', { class: 'lbl' }, 'Adet'), stepper),
    productGrid(products, async (p, btn) => {
      await busy(btn, async () => { const ok = await onPick(p, qty); if (ok) { setQty(1); if (p.track_stock) p.stock_qty -= qty; } });
    }));
  const newBtn = can('products.manage') ? h('button', { class: 'btn btn-ghost', type: 'button', onclick: async () => {
    const { productEditors } = await import('./editors.js?v=1');
    productEditors({ products, set: () => { invalidateMenu(); m.close(); productPicker({ title, onPick }); } }).edit(null);
  } }, icon('plus', 16), 'Yeni ürün') : null;
  const m = modal({ title, body, wide: true, actions: [newBtn, h('button', { class: 'btn btn-primary', type: 'button', onclick: () => m.close() }, 'Tamam')] });
}

export function cafeView(ctx) {
  const { main } = ctx;
  const cart = new Map(); // id -> {p, qty}
  let products = [];
  const left = h('section', { class: 'pos-l' });
  const cartList = h('div', { class: 'cart-list' });
  const totalEl = h('b', { class: 'mono' }, money(0));
  const payBtn = h('button', { class: 'btn btn-primary btn-lg btn-block', type: 'button', disabled: true, onclick: checkout }, icon('wallet', 18), 'Ödeme al');
  const right = h('aside', { class: 'pos-r card' }, h('div', { class: 'card-h' }, h('h2', null, 'Sepet'), h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: () => { cart.clear(); renderCart(); } }, 'Temizle')),
    cartList, h('div', { class: 'cart-total' }, h('span', null, 'Toplam'), totalEl), payBtn);
  const recentBox = h('section', { class: 'card' });
  add(main, h('div', { class: 'pos' }, left, right), recentBox);

  function renderCart() {
    let total = 0;
    const rows = Array.from(cart.values()).map(({ p, qty }) => {
      total += p.price_cents * qty;
      return h('div', { class: 'cart-row' }, h('div', null, h('b', null, p.name), h('small', { class: 'muted mono' }, money(p.price_cents))),
        h('div', { class: 'stepper sm' },
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Azalt', onclick: () => { const c = cart.get(p.id); c.qty--; if (c.qty <= 0) cart.delete(p.id); renderCart(); } }, icon('minus', 16)),
          h('b', { class: 'mono' }, String(qty)),
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Artır', onclick: () => { const c = cart.get(p.id); if (c.qty < 99) c.qty++; renderCart(); } }, icon('plus', 16))));
    });
    fill(cartList, ...(rows.length ? rows : [h('p', { class: 'muted sm' }, 'Ürünlere dokunarak sepete ekleyin.')]));
    totalEl.textContent = money(total);
    payBtn.disabled = !rows.length;
    return total;
  }
  function checkout() {
    const lines = () => Array.from(cart.values()).map(({ p, qty }) => [`${qty}× ${p.name}`, p.price_cents * qty]);
    const sub = () => Array.from(cart.values()).reduce((a, { p, qty }) => a + p.price_cents * qty, 0);
    checkoutModal({
      title: 'Hızlı satış', lines, subtotal: sub, live: false,
      submit: (payload) => post('/quick-sales', Object.assign(payload, { lines: Array.from(cart.values()).map(({ p, qty }) => ({ product_id: p.id, qty })) })),
      done: (r) => { toast('Satış tamamlandı · ' + money(r.total_cents), 'good'); cart.clear(); renderCart(); load(); },
    });
  }
  async function loadRecent() {
    try {
      const r = await get('/quick-sales');
      fill(recentBox, h('div', { class: 'card-h' }, h('h2', null, 'Son satışlar')),
        r.items.length ? h('ul', { class: 'list' }, r.items.map((it) => h('li', { class: it.status === 'void' ? 'void' : '' },
          h('div', null, h('b', { class: 'mono' }, money(it.total_cents)), h('small', { class: 'muted' }, ' · ' + timeOnly(it.closed_at) + (it.by ? ' · ' + it.by : '')), h('div', { class: 'muted sm' }, it.summary)),
          h('div', { class: 'r' }, it.status === 'void' ? h('span', { class: 'tag bad' }, 'İptal') : h('button', { class: 'icon-btn sm', type: 'button', 'aria-label': 'Fiş yazdır', onclick: async () => { try { printReceipt(await get('/tickets/' + it.id), ctx.me.business.name); } catch (e) { fail(e); } } }, icon('print', 16)),
            it.can_void && can('session.cancel') ? h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: async (ev) => {
              if (!await confirmBox('Satışı iptal et', 'Bu satış iptal edilsin mi? Ödeme kasadan düşülür, ürünler stoğa geri eklenir.', 'İptal et', true)) return;
              busy(ev.currentTarget, async () => { try { await post(`/quick-sales/${it.id}/void`, {}); toast('Satış iptal edildi.', 'good'); load(); } catch (e) { fail(e); } });
            } }, 'İptal et') : null)))) : h('p', { class: 'muted sm' }, 'Henüz hızlı satış yok.'));
    } catch (e) { clear(recentBox); }
  }
  async function load() {
    try {
      products = await menu(true);
      fill(left, productGrid(products, (p) => {
        const c = cart.get(p.id) || { p, qty: 0 };
        if (c.qty < 99) c.qty++;
        cart.set(p.id, c); renderCart();
      }));
    } catch (e) { fill(left, empty(e.message)); }
    loadRecent();
  }
  if (!ctx.me.business.open_shift_id) main.prepend(h('div', { class: 'banner warn' }, icon('wallet', 18), h('span', null, 'Kasa kapalı. Satış yapmak için önce kasayı açın.'), h('a', { href: '#/vardiya' }, 'Kasayı aç')));
  renderCart(); load();
  void $;
}
