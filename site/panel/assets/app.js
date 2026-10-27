// DNL Station panel — kabuk, yönlendirme ve oturum yönetimi
import { state, h, $, clear, icon, get, post, can, toast, fail, setAuthLost, closeAllModals, unlockAudio, fill, add } from './core.js?v=1';
import { tablesView } from './v-tables.js?v=1';
import { cafeView } from './v-cafe.js?v=1';
import { productsView } from './v-products.js?v=1';
import { shiftView } from './v-shift.js?v=1';
import { reportsView } from './v-reports.js?v=1';
import { settingsView } from './v-settings.js?v=1';
import { accountView } from './v-account.js?v=1';
import { setupView } from './v-setup.js?v=1';
import { billingView } from './v-billing.js?v=1';
import { customersView } from './v-customers.js?v=1';
import { expensesView } from './v-expenses.js?v=1';
import { reservationsView } from './v-reservations.js?v=1';

const ROUTES = {
  masalar: { title: 'Masalar', icon: 'grid', view: tablesView, perm: 'tables.operate', tab: true },
  kafe: { title: 'Kafeterya', icon: 'cup', view: cafeView, perm: 'sales.quick', tab: true },
  vardiya: { title: 'Kasa', icon: 'wallet', view: shiftView, perm: 'shift.operate', tab: true },
  raporlar: { title: 'Raporlar', icon: 'chart', view: reportsView, perm: 'reports.view', tab: true },
  rezervasyon: { title: 'Rezervasyon', icon: 'calendar', view: reservationsView, perm: 'reservations.use' },
  musteriler: { title: 'Müşteriler', icon: 'users', view: customersView, perm: 'customers.use' },
  giderler: { title: 'Giderler', icon: 'receipt', view: expensesView, perm: 'expenses.manage' },
  urunler: { title: 'Ürünler', icon: 'box', view: productsView, perm: 'products.manage' },
  ayarlar: { title: 'Ayarlar', icon: 'gear', view: settingsView, perm: null, anyPerm: ['settings.tables', 'settings.business', 'staff.manage', 'display.manage', 'audit.view'] },
  paket: { title: 'Paketim', icon: 'wallet', view: billingView, perm: 'settings.business' },
  hesap: { title: 'Hesabım', icon: 'user', view: accountView, perm: null },
  kurulum: { title: 'Kurulum', icon: 'gear', view: setupView, perm: null, hidden: true },
};
const ORDER = ['masalar', 'kafe', 'rezervasyon', 'vardiya', 'musteriler', 'raporlar', 'giderler', 'urunler', 'ayarlar', 'paket', 'hesap'];

const app = $('#app');
let current = null; // { name, cleanup }
let authLostShown = false;

function allowed(name) {
  const r = ROUTES[name];
  if (!r) return false;
  if (r.perm && !can(r.perm)) return false;
  if (r.anyPerm && !r.anyPerm.some(can)) return false;
  return true;
}

setAuthLost((why) => {
  if (why === 'must_change') {
    if (state.me) state.me.user.must_change_password = true;
    if (location.hash !== '#/hesap') location.hash = '#/hesap';
    return;
  }
  if (why === 'suspended') { suspendedScreen(); return; }
  if (authLostShown) return;
  authLostShown = true;
  location.replace('/giris/?oturum=bitti');
});

async function loadMe() {
  const me = await get('/me');
  state.me = me;
  state.csrf = me.csrf;
  return me;
}

function navItems(isTab) {
  return ORDER.filter((n) => allowed(n) && !ROUTES[n].hidden && (!isTab || ROUTES[n].tab)).map((n) =>
    h('a', { href: '#/' + n, class: 'nav-item', 'data-route': n }, icon(ROUTES[n].icon, 20), h('span', null, ROUTES[n].title)));
}

function moreSheet() {
  const extra = ORDER.filter((n) => allowed(n) && !ROUTES[n].tab);
  const back = h('div', { class: 'sheet-back', onclick: () => close() });
  const sheet = h('div', { class: 'sheet', role: 'dialog', 'aria-label': 'Diğer' },
    h('div', { class: 'sheet-grip' }),
    extra.map((n) => h('a', { href: '#/' + n, class: 'sheet-item', onclick: () => close() }, icon(ROUTES[n].icon, 22), ROUTES[n].title)),
    h('button', { class: 'sheet-item danger', type: 'button', onclick: logout }, icon('logout', 22), 'Çıkış yap'));
  const wrap = h('div', { class: 'sheet-wrap' }, back, sheet);
  function close() { wrap.classList.add('out'); setTimeout(() => wrap.remove(), 200); }
  document.body.append(wrap);
}

async function logout() {
  try { await post('/auth/logout', {}); } catch { /* yine de çık */ }
  location.replace('/giris/');
}

function suspendedScreen() {
  app.className = 'boot-err'; app.removeAttribute('aria-busy');
  fill(app, h('div', { class: 'wm' }, 'DNL'), h('h1', null, 'İşletme hesabınız askıya alındı'),
    h('p', { class: 'muted' }, 'Verileriniz güvende. Hesabınızı yeniden açmak için bizimle iletişime geçin.'),
    h('a', { class: 'btn btn-ghost', href: 'https://wa.me/905377620708', target: '_blank', rel: 'noopener noreferrer' }, 'WhatsApp destek'),
    h('a', { class: 'btn btn-ghost', href: 'mailto:support@dnlstation.com.tr' }, 'support@dnlstation.com.tr'),
    h('button', { class: 'btn btn-primary', type: 'button', onclick: logout }, 'Çıkış yap'));
}

function announceBanner() {
  const a = state.me.announcement;
  if (!a || !a.text) return null;
  const key = 'dnl_ann_' + a.id;
  try { if (localStorage.getItem(key)) return null; } catch { /* depolama yok */ }
  const el = h('div', { class: 'banner ' + (a.level === 'warn' ? 'warn' : 'info') }, icon(a.level === 'warn' ? 'alert' : 'clock', 18), h('span', { class: 'grow' }, a.text),
    h('button', { class: 'icon-btn sm', type: 'button', 'aria-label': 'Duyuruyu kapat', onclick: () => { try { localStorage.setItem(key, '1'); } catch { /* yok */ } el.remove(); } }, icon('x', 16)));
  return el;
}

function subBanner() {
  const s = state.me.subscription;
  if (s.state === 'expired') {
    return h('div', { class: 'banner bad' }, icon('alert', 18), h('span', null, 'Deneme süreniz sona erdi. Kayıtlarınız güvende; yeni işlem yapmak için paketinizi yenileyin. '), can('settings.business') ? h('a', { href: '#/paket' }, 'Paketi yenile') : h('span', null, 'İşletme sahibinize bilgi verin.'));
  }
  if (s.state === 'trial' && s.days_left <= 3) {
    return h('div', { class: 'banner warn' }, icon('clock', 18), h('span', null, `Deneme sürenizin bitmesine ${s.days_left} gün kaldı.`), can('settings.business') ? h('a', { href: '#/paket' }, 'Paketi seç') : null);
  }
  return null;
}

function shell() {
  const me = state.me;
  const side = h('aside', { class: 'side' },
    h('a', { class: 'side-brand', href: '#/masalar', 'aria-label': 'DNL Station panel' }, h('span', { class: 'wm' }, 'DNL'), h('span', { class: 'side-tag' }, 'Station')),
    h('nav', { class: 'side-nav', 'aria-label': 'Panel menüsü' }, navItems(false)),
    h('div', { class: 'side-foot' },
      h('div', { class: 'who' }, h('span', { class: 'avatar' }, (me.user.name || '?').trim().charAt(0).toLocaleUpperCase('tr')),
        h('div', null, h('b', null, me.user.name), h('small', null, me.business.name))),
      h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Çıkış yap', title: 'Çıkış yap', onclick: logout }, icon('logout', 18))));
  const top = h('header', { class: 'top' },
    h('div', { class: 'top-l' }, h('span', { class: 'wm sm only-m' }, 'DNL'), h('h1', { class: 'top-title', id: 'top-title' }, '')),
    h('div', { class: 'top-r', id: 'top-r' },
      h('span', { class: 'chip shift-chip', id: 'shift-chip' }),
      h('span', { class: 'chip plan-chip only-d' }, me.subscription.state === 'trial' ? `Deneme · ${me.subscription.days_left} gün` : me.subscription.state === 'lifetime' ? 'Ömür boyu' : me.subscription.plan_name)));
  const tabs = h('nav', { class: 'tabs', 'aria-label': 'Alt menü' }, navItems(true),
    h('button', { class: 'nav-item', type: 'button', onclick: moreSheet }, icon('more', 20), h('span', null, 'Diğer')));
  const main = h('main', { class: 'main', id: 'main', tabindex: '-1' });
  clear(app);
  app.className = 'shell';
  app.removeAttribute('aria-busy');
  add(app, side, h('div', { class: 'col' }, subBanner(), announceBanner(), top, main), tabs);
  updateShiftChip();
}

export function updateShiftChip(openShiftId) {
  const chip = $('#shift-chip');
  if (!chip) return;
  if (openShiftId !== undefined) state.me.business.open_shift_id = openShiftId;
  const open = state.me.business.open_shift_id !== null;
  chip.className = 'chip shift-chip ' + (open ? 'on' : 'off');
  fill(chip, h('i'), open ? 'Kasa açık' : 'Kasa kapalı');
  chip.onclick = () => { location.hash = '#/vardiya'; };
}

function route() {
  const name = (location.hash.replace(/^#\/?/, '').split('/')[0]) || 'masalar';
  const me = state.me;
  // Zorunlu şifre değişikliği
  if (me.user.must_change_password && name !== 'hesap') { location.replace('#/hesap'); return; }
  // İlk kurulum
  if (!me.business.setup_done && can('settings.tables') && name === 'masalar') { location.replace('#/kurulum'); return; }
  let target = name;
  if (!allowed(target)) target = allowed('masalar') ? 'masalar' : 'hesap';
  if (target !== name) { location.replace('#/' + target); return; }
  if (current && current.cleanup) { try { current.cleanup(); } catch { /* yoksay */ } }
  closeAllModals();
  const r = ROUTES[target];
  document.title = r.title + ' | DNL Station';
  $('#top-title').textContent = r.title;
  document.querySelectorAll('.nav-item').forEach((a) => a.classList.toggle('active', a.dataset.route === target));
  const main = clear($('#main'));
  main.scrollTop = 0; window.scrollTo(0, 0);
  const ctx = { main, me, refreshMe: async () => { await loadMe(); updateShiftChip(); }, updateShiftChip };
  let cleanup = null;
  try { cleanup = r.view(ctx) || null; } catch (e) { console.error(e); fail(e); }
  current = { name: target, cleanup };
}

async function boot() {
  try {
    await loadMe();
  } catch (e) {
    if (e.status === 401) return; // setAuthLost yönlendirir
    fill(app, h('div', { class: 'boot-err' }, h('p', null, e.message || 'Panel yüklenemedi.'), h('button', { class: 'btn btn-primary', onclick: () => location.reload() }, 'Tekrar dene')));
    return;
  }
  if (state.me.suspended) { suspendedScreen(); return; }
  shell();
  window.addEventListener('hashchange', route);
  document.addEventListener('pointerdown', unlockAudio, { once: true });
  route();
  // Oturum ve izinleri arada bir tazele (rol değişikliği, paket durumu)
  setInterval(() => { if (!document.hidden) loadMe().then(updateShiftChip).catch(() => {}); }, 120000);
  if (new URLSearchParams(location.search).get('hos') === '1') toast('Hoş geldiniz! Panelinize giriş yaptınız.', 'good');
}
boot();
