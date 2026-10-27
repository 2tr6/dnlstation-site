// Ayarlar: masalar ve tarifeler, işletme, personel, müşteri ekranı, işlem kayıtları
import { catalogEditors } from './editors.js?v=1';
import { state, h, clear, icon, get, post, put, del, can, toast, fail, modal, confirmBox, money, dateTime, busy, field, inp, sel, moneyInp, toggle, showErrors, inputToCents, moneyStr, empty, KIND_LABEL, ROLE_LABEL, fill, add } from './core.js?v=1';

const TABS = [
  ['masalar', 'Masalar ve ücretler', 'settings.tables'],
  ['isletme', 'İşletme', 'settings.business'],
  ['personel', 'Personel', 'staff.manage'],
  ['ekran', 'Müşteri ekranı', 'display.manage'],
  ['kayit', 'İşlem kayıtları', 'audit.view'],
];

export function settingsView(ctx) {
  const { main } = ctx;
  const tabs = TABS.filter((t) => can(t[2]));
  let cur = (location.hash.split('/')[2]) || tabs[0][0];
  if (!tabs.some((t) => t[0] === cur)) cur = tabs[0][0];
  const nav = h('div', { class: 'subtabs', role: 'tablist' });
  const pane = h('div', { class: 'pane' });
  add(main, nav, pane);
  const go = (k) => {
    cur = k; history.replaceState(null, '', '#/ayarlar/' + k);
    fill(nav, ...tabs.map(([key, label]) => h('button', { type: 'button', role: 'tab', 'aria-selected': String(key === cur), class: key === cur ? 'on' : '', onclick: () => go(key) }, label)));
    clear(pane);
    ({ masalar: tablesTab, isletme: bizTab, personel: staffTab, ekran: displayTab, kayit: auditTab })[k](pane, ctx);
  };
  go(cur);
}

/* ---------- Masalar ve tarifeler ---------- */

function tablesTab(pane) {
  let d = null;
  const load = async () => { try { d = await get('/catalog'); render(); } catch (e) { fill(pane, empty(e.message)); } };
  const set = (r) => { d = r; render(); };
  function render() {
    const typeOpts = d.types.map((t) => [t.id, t.name]);
    fill(pane, 
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Masa türleri ve ücretler'), h('button', { class: 'btn btn-primary sm', type: 'button', onclick: () => editType(null) }, icon('plus', 16), 'Tür ekle')),
        d.types.length ? h('div', { class: 'type-list' }, d.types.map((t) => h('button', { type: 'button', class: 'type-row' + (t.is_active ? '' : ' dim'), onclick: () => editType(t) },
          h('div', null, h('b', null, t.name), h('small', { class: 'muted' }, ' · ' + (KIND_LABEL[t.kind] || ''))),
          h('div', { class: 'type-prices mono' },
            h('span', null, money(t.hour_price) + '/sa' + (t.uses_controllers && t.hour_price_4 ? ' · 4 kol ' + money(t.hour_price_4) : '')),
            t.match_price !== null ? h('span', null, 'Maç ' + money(t.match_price)) : null,
            t.night_enabled ? h('span', null, `Gece ${t.night_start}–${t.night_end} ${money(t.night_hour_price)}`) : null),
          icon('edit', 16)))) : empty('Henüz masa türü yok. Önce bir tür ve ücret tanımlayın.')),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, `Masalar (${d.tables.length}/${d.max_tables >= 1000 ? '∞' : d.max_tables})`),
        d.types.length ? h('button', { class: 'btn btn-primary sm', type: 'button', onclick: () => addTables(typeOpts) }, icon('plus', 16), 'Masa ekle') : null),
        d.tables.length ? h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl compact' },
          h('thead', null, h('tr', null, h('th', null, 'Masa'), h('th', null, 'Tür'), h('th', null, 'Durum'), h('th', { class: 'act-col' }, ''))),
          h('tbody', null, d.tables.map((t) => h('tr', { class: t.is_active ? '' : 'dim' },
            h('td', { 'data-l': 'Masa' }, h('b', null, t.name)), h('td', { 'data-l': 'Tür' }, (d.types.find((x) => x.id === t.table_type_id) || {}).name || '—'),
            h('td', { 'data-l': 'Durum' }, t.busy ? h('span', { class: 'tag warn' }, 'Dolu') : t.is_active ? h('span', { class: 'tag ok' }, 'Aktif') : h('span', { class: 'tag' }, 'Pasif')),
            h('td', { class: 'act-col' }, h('button', { class: 'icon-btn', type: 'button', 'aria-label': t.name + ' düzenle', onclick: () => editTable(t, typeOpts) }, icon('edit', 18)))))))) : h('p', { class: 'muted sm' }, 'Henüz masa yok.')));
  }
  const ed = catalogEditors({ get d() { return d; }, set });
  const editType = ed.editType, addTables = ed.addTables, editTable = ed.editTable;
  load();
}

/* ---------- İşletme ---------- */
function bizTab(pane, ctx) {
  (async () => {
    let s;
    try { s = await get('/settings'); } catch (e) { add(pane, empty(e.message)); return; }
    const f = h('form', { class: 'form narrow', novalidate: true },
      field('İşletme adı', inp('name', { maxlength: 80, value: s.name })),
      field('Telefon', inp('phone', { inputmode: 'tel', value: s.phone, placeholder: '05XX XXX XX XX' })),
      field('Süre uyarısı (dakika)', inp('warn_minutes', { inputmode: 'numeric', class: 'inp mono', value: String(s.warn_minutes) }), 'Sabit süreli masalarda bitişe bu kadar kala sesli uyarı verilir. 0 = kapalı.'),
      toggle('staff_can_discount', 'Personel indirim yapabilsin', s.staff_can_discount),
      toggle('show_prices_on_display', 'Müşteri ekranında tutarları göster', s.show_prices_on_display),
      toggle('show_free_on_display', 'Müşteri ekranında boş masaları göster', s.show_free_on_display),
      toggle('email_z_report', 'Kasa kapanınca Z raporunu e-postayla gönder', s.email_z_report));
    const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Kaydet');
    add(f, save);
    add(pane, h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'İşletme bilgileri')), f));
    f.addEventListener('submit', (ev) => { ev.preventDefault(); busy(save, async () => {
      const q = (n) => f.querySelector(`[name="${n}"]`);
      try {
        await put('/settings', { name: q('name').value, phone: q('phone').value, warn_minutes: parseInt(q('warn_minutes').value, 10) || 0, staff_can_discount: q('staff_can_discount').checked, show_prices_on_display: q('show_prices_on_display').checked, show_free_on_display: q('show_free_on_display').checked, email_z_report: q('email_z_report').checked });
        await ctx.refreshMe(); toast('Ayarlar kaydedildi.', 'good');
      } catch (e) { if (!showErrors(f, e)) fail(e); }
    }); });
  })();
}

/* ---------- Personel ---------- */
function tempPassModal(name, pass) {
  const code = h('code', { class: 'temp-pass mono' }, pass);
  const m = modal({ title: 'Geçici şifre', body: h('div', { class: 'form' },
    h('p', null, h('b', null, name), ' için geçici şifre oluşturuldu. Bu şifre yalnızca şimdi gösterilir; personele güvenli bir şekilde iletin. İlk girişte kendi şifresini belirlemesi istenecek.'),
    h('div', { class: 'copy-row' }, code, h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: async () => { try { await navigator.clipboard.writeText(pass); toast('Kopyalandı.', 'good', 1500); } catch { toast('Kopyalanamadı; şifreyi elle not alın.', 'bad'); } } }, icon('copy', 16), 'Kopyala'))),
    actions: [h('button', { class: 'btn btn-primary', type: 'button', onclick: () => m.close() }, 'Not aldım')] });
}
function staffTab(pane) {
  let d;
  const load = async () => { try { d = await get('/staff'); render(); } catch (e) { fill(pane, empty(e.message)); } };
  function render() {
    const active = d.users.filter((u) => u.role !== 'owner' && u.is_active).length;
    fill(pane, h('section', { class: 'card' },
      h('div', { class: 'card-h' }, h('h2', null, `Personel (${active}/${d.max_staff})`), h('button', { class: 'btn btn-primary sm', type: 'button', onclick: add }, icon('plus', 16), 'Personel ekle')),
      h('p', { class: 'muted sm' }, 'Müdür: ürün, stok, masa ayarları, raporlar, iptal ve indirim. Personel: masa açma/kapama, ürün ekleme, hızlı satış ve kasa.'),
      h('ul', { class: 'list' }, d.users.map((u) => h('li', { class: u.is_active ? '' : 'dim' },
        h('div', null, h('b', null, u.name), ' ', h('span', { class: 'tag' + (u.role === 'owner' ? ' ok' : '') }, ROLE_LABEL[u.role]), u.is_active ? null : h('span', { class: 'tag' }, 'Pasif'),
          h('div', { class: 'muted sm' }, u.email + (u.last_login_at ? ' · son giriş ' + dateTime(u.last_login_at) : ' · henüz giriş yapmadı'))),
        u.role !== 'owner' ? h('div', { class: 'r' }, h('button', { class: 'icon-btn', type: 'button', 'aria-label': u.name + ' düzenle', onclick: () => edit(u) }, icon('edit', 18))) : null)))));
  }
  function add() {
    const f = h('form', { class: 'form', novalidate: true },
      field('Ad soyad', inp('name', { maxlength: 80 })), field('E-posta (giriş için)', inp('email', { type: 'email', inputmode: 'email', maxlength: 190 })),
      field('Telefon', inp('phone', { inputmode: 'tel', placeholder: 'İsteğe bağlı' })), field('Rol', sel('role', [['staff', 'Personel'], ['manager', 'Müdür']], 'staff')));
    const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Ekle');
    const m = modal({ title: 'Personel ekle', body: f, actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), save] });
    const submit = (ev) => { ev && ev.preventDefault(); busy(save, async () => {
      const q = (n) => f.querySelector(`[name="${n}"]`);
      try { const r = await post('/staff', { name: q('name').value, email: q('email').value, phone: q('phone').value, role: q('role').value }); d = r; render(); m.close(); tempPassModal(q('name').value, r.temp_password); }
      catch (e) { if (!showErrors(f, e)) fail(e); }
    }); };
    f.addEventListener('submit', submit); save.onclick = submit;
  }
  function edit(u) {
    const f = h('form', { class: 'form', novalidate: true },
      field('Ad soyad', inp('name', { maxlength: 80, value: u.name })), field('Telefon', inp('phone', { inputmode: 'tel', value: u.phone })),
      field('Rol', sel('role', [['staff', 'Personel'], ['manager', 'Müdür']], u.role)), toggle('is_active', 'Aktif (giriş yapabilir)', u.is_active),
      h('p', { class: 'hint' }, 'Rol değişikliği ya da pasife alma, kişinin açık oturumlarını hemen kapatır.'));
    const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Kaydet');
    const m = modal({ title: u.name, body: f, actions: [
      h('button', { class: 'btn btn-ghost', type: 'button', onclick: async (ev) => {
        if (!await confirmBox('Şifre sıfırla', `${u.name} için yeni geçici şifre oluşturulsun mu? Mevcut oturumları kapatılır.`, 'Sıfırla')) return;
        busy(ev.currentTarget, async () => { try { const r = await post(`/staff/${u.id}/reset-password`, {}); d = r; render(); m.close(); tempPassModal(u.name, r.temp_password); } catch (e) { fail(e); } });
      } }, 'Şifre sıfırla'),
      h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), save] });
    const submit = (ev) => { ev && ev.preventDefault(); busy(save, async () => {
      const q = (n) => f.querySelector(`[name="${n}"]`);
      try { d = await put('/staff/' + u.id, { name: q('name').value, phone: q('phone').value, role: q('role').value, is_active: q('is_active').checked }); render(); m.close(); toast('Kaydedildi.', 'good'); }
      catch (e) { if (!showErrors(f, e)) fail(e); }
    }); };
    f.addEventListener('submit', submit); save.onclick = submit;
  }
  load();
}

/* ---------- Müşteri ekranı ---------- */
function displayTab(pane) {
  (async () => {
    let enabled = false;
    try { enabled = can('settings.business') ? (await get('/settings')).display_enabled : false; } catch { /* yok */ }
    const out = h('div', { class: 'form' });
    const card = h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Müşteri ekranı')),
      h('p', { class: 'muted' }, 'Salondaki TV ya da tablette masaların doluluk durumunu ve kalan süreleri gösterin. Ekran bağlantısı gizli bir anahtar içerir; yalnızca salondaki cihazda açın. Anahtar güvenlik için saklanmaz, yalnızca oluşturulduğunda bir kez gösterilir.'),
      enabled ? h('p', { class: 'tag ok' }, 'Ekran bağlantısı etkin') : null, out,
      h('div', { class: 'row-wrap' },
        h('button', { class: 'btn btn-primary', type: 'button', onclick: (ev) => busy(ev.currentTarget, async () => {
          if (enabled && !await confirmBox('Bağlantıyı yenile', 'Yeni bağlantı oluşturulunca eski bağlantı çalışmaz. Ekrandaki cihazda yeni bağlantıyı açmanız gerekir.', 'Yenile')) return;
          try { const r = await post('/settings/display/rotate', {}); enabled = true; showLink(r.key); } catch (e) { fail(e); }
        }) }, icon('screen', 18), enabled ? 'Yeni bağlantı oluştur' : 'Ekran bağlantısı oluştur'),
        h('button', { class: 'btn btn-ghost danger-t', type: 'button', onclick: (ev) => busy(ev.currentTarget, async () => {
          if (!await confirmBox('Ekranı kapat', 'Müşteri ekranı bağlantısı devre dışı bırakılsın mı?', 'Kapat', true)) return;
          try { await post('/settings/display/disable', {}); enabled = false; clear(out); toast('Müşteri ekranı kapatıldı.', 'good'); } catch (e) { fail(e); }
        }) }, 'Devre dışı bırak')));
    function showLink(key) {
      const url = location.origin + '/ekran/#k=' + key;
      fill(out, h('div', { class: 'copy-row' }, h('code', { class: 'mono url' }, url),
        h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: async () => { try { await navigator.clipboard.writeText(url); toast('Bağlantı kopyalandı.', 'good', 1500); } catch { toast('Kopyalanamadı.', 'bad'); } } }, icon('copy', 16), 'Kopyala'),
        h('a', { class: 'btn btn-ghost sm', href: url, target: '_blank', rel: 'noopener noreferrer' }, 'Aç')),
      h('p', { class: 'hint' }, 'Bu bağlantıyı şimdi ekran cihazında açın ya da kaydedin; tekrar gösterilmeyecek.'));
    }
    add(pane, card);
  })();
}

/* ---------- İşlem kayıtları ---------- */
const ACTION_LABEL = {
  'session.open': 'Masa açıldı', 'session.close': 'Hesap kapatıldı', 'session.cancel': 'Oturum iptal', 'session.reopen': 'Hesap geri alındı', 'session.extend': 'Süre eklendi',
  'session.controllers': 'Kol sayısı değişti', 'session.match': 'Maç sayısı', 'session.move': 'Masa taşındı', 'item.remove': 'Ürün çıkarıldı',
  'quick.sale': 'Hızlı satış', 'quick.void': 'Hızlı satış iptal', 'shift.open': 'Kasa açıldı', 'shift.close': 'Kasa kapatıldı',
  'product.create': 'Ürün eklendi', 'product.update': 'Ürün güncellendi', 'product.archive': 'Ürün kaldırıldı', 'stock.restock': 'Stok girişi', 'stock.count': 'Stok sayımı',
  'type.create': 'Masa türü eklendi', 'type.update': 'Ücret güncellendi', 'type.delete': 'Masa türü silindi', 'table.create': 'Masa eklendi', 'table.update': 'Masa güncellendi', 'table.delete': 'Masa silindi',
  'staff.create': 'Personel eklendi', 'staff.update': 'Personel güncellendi', 'staff.reset_password': 'Personel şifresi sıfırlandı', 'business.update': 'İşletme ayarları',
  'display.rotate': 'Ekran bağlantısı yenilendi', 'display.disable': 'Ekran kapatıldı', 'setup.quick': 'İlk kurulum',
  'auth.login': 'Giriş', 'auth.logout': 'Çıkış', 'auth.locked': 'Hesap kilitlendi', 'auth.password_changed': 'Şifre değişti', 'auth.password_reset': 'Şifre sıfırlandı', 'auth.register': 'Kayıt', 'auth.verified': 'E-posta doğrulandı',
};
function metaText(a) {
  const m = a.meta || {};
  if (a.action === 'session.close') return money(m.total || 0) + (m.discount ? ' · indirim ' + money(m.discount) : '');
  if (a.action === 'quick.sale') return money(m.total || 0);
  if (a.action === 'session.cancel') return m.reason || '';
  if (a.action === 'shift.close') return `beklenen ${money(m.expected || 0)} · sayılan ${money(m.counted || 0)}`;
  if (a.action === 'session.extend') return '+' + m.minutes + ' dk';
  if (a.action === 'stock.restock') return '+' + m.qty;
  if (a.action === 'item.remove') return `${m.qty}× ${m.name}`;
  return '';
}
function auditTab(pane) {
  const list = h('ul', { class: 'list audit' });
  const more = h('button', { class: 'btn btn-ghost', type: 'button' }, 'Daha eski kayıtlar');
  add(pane, h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'İşlem kayıtları')), h('p', { class: 'muted sm' }, 'Paneldeki önemli işlemler kim tarafından ne zaman yapıldığıyla kaydedilir; kayıtlar değiştirilemez.'), list, more));
  let last = 0;
  const load = () => busy(more, async () => {
    try {
      const r = await get('/audit' + (last ? '?before=' + last : ''));
      r.items.forEach((a) => { list.append(h('li', null, h('div', null, h('b', null, ACTION_LABEL[a.action] || a.action), ' ', h('span', { class: 'muted sm' }, metaText(a))), h('div', { class: 'r muted sm' }, (a.user || 'Sistem') + ' · ' + dateTime(a.at)))); last = a.id; });
      more.hidden = r.items.length < 100;
      if (!list.childElementCount) list.append(h('li', { class: 'muted' }, 'Kayıt yok.'));
    } catch (e) { fail(e); }
  });
  more.onclick = load; load();
  void state;
}
