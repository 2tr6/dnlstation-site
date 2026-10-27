// DNL Station — ana yönetici paneli
// Güvenlik: tüm içerik h() ile textContent olarak basılır; kimlik doğrulama sunucuda (şifre + Authenticator).
import { state, h, $, $$, clear, fill, add, icon, get, post, put, toast, fail, modal, confirmBox, money, dateTime, busy, field, inp, sel, moneyInp, toggle, showErrors, inputToCents, moneyStr, empty, seg, setAuthLost, closeAllModals, ROLE_LABEL, KIND_LABEL } from '/panel/assets/core.js?v=1';

const app = $('#app');
const PLAN = { baslangic: 'Başlangıç', pro: 'Pro', salon: 'Salon+' };
const PERIOD = { aylik: 'Aylık', yillik: 'Yıllık' };
const STATE = { trial: ['Deneme', 'warn'], active: ['Aktif', 'ok'], expired: ['Süresi doldu', 'bad'], suspended: ['Donduruldu', 'bad'], lifetime: ['Ömür boyu', 'ok'] };
let plans = [];
let current = null;

setAuthLost(() => { if (state.me) { state.me = null; loginView('Oturum süresi doldu. Lütfen tekrar giriş yapın.'); } });

async function csrf() {
  const r = await fetch('/api/auth/csrf', { credentials: 'same-origin' }).then((x) => x.json());
  if (r.ok) state.csrf = r.data.csrf;
}
const stateTag = (s) => { const [l, c] = STATE[s] || [s, '']; return h('span', { class: 'tag ' + c }, l); };
const date = (iso) => iso ? new Intl.DateTimeFormat('tr-TR', { dateStyle: 'medium', timeZone: 'Europe/Istanbul' }).format(new Date(iso)) : '—';

/* ---------- Giriş ---------- */
function loginView(msg) {
  closeAllModals();
  app.className = 'login-wrap'; app.removeAttribute('aria-busy');
  const email = inp('email', { type: 'email', autocomplete: 'username', inputmode: 'email', maxlength: 190, autofocus: true });
  const pw = inp('password', { type: 'password', autocomplete: 'current-password', maxlength: 128 });
  const f = h('form', { class: 'form', novalidate: true }, field('E-posta', email), field('Şifre', pw));
  const go = h('button', { class: 'btn btn-primary btn-lg btn-block', type: 'submit' }, 'Devam');
  f.append(go);
  const card = h('div', { class: 'login-card' }, h('div', { class: 'wm' }, 'DNL'), h('p', { class: 'eyebrow' }, 'Yönetim'), h('h1', null, 'Yönetici girişi'),
    msg ? h('div', { class: 'banner warn' }, msg) : null, f);
  fill(app, card);
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    busy(go, async () => {
      try {
        await csrf();
        const r = await post('/admin/auth/login', { email: email.value, password: pw.value });
        state.csrf = r.csrf;
        codeView(card, r);
      } catch (err) { if (!showErrors(f, err)) fail(err); }
    });
  });
}

function codeView(card, r) {
  const code = inp('code', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: 6, pattern: '[0-9]*', class: 'inp mono code-inp', placeholder: '000000', autofocus: true });
  const f = h('form', { class: 'form', novalidate: true });
  const go = h('button', { class: 'btn btn-primary btn-lg btn-block', type: 'submit' }, 'Giriş yap');
  if (r.next === 'setup') {
    const grouped = r.secret.match(/.{1,4}/g).join(' ');
    add(f,
      h('div', { class: 'banner warn' }, 'İlk giriş: hesabınızı Authenticator uygulamasına bağlayın. Bu kurulum bir kez yapılır.'),
      h('ol', { class: 'steps' },
        h('li', null, 'Telefonunuzda Google Authenticator (ya da Microsoft Authenticator) uygulamasını açın.'),
        h('li', null, 'Aşağıdaki düğmeye dokunun. Açılmazsa uygulamada “Kurulum anahtarı gir” seçip anahtarı yazın (hesap adı: DNL Station, zamana dayalı).'),
        h('li', null, 'Uygulamada görünen 6 haneli kodu aşağıya girin.')),
      h('a', { class: 'btn btn-ghost btn-block', href: r.uri }, 'Authenticator uygulamasına ekle'),
      h('div', { class: 'copy-row' }, h('code', { class: 'mono secret' }, grouped),
        h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: async () => { try { await navigator.clipboard.writeText(r.secret); toast('Anahtar kopyalandı.', 'good', 1500); } catch { toast('Kopyalanamadı; anahtarı elle yazın.', 'bad'); } } }, icon('copy', 16), 'Kopyala')),
      h('p', { class: 'hint' }, 'Bu anahtarı kimseyle paylaşmayın ve ekran görüntüsü almayın.'));
  } else {
    add(f, h('p', { class: 'muted' }, 'Authenticator uygulamasındaki 6 haneli kodu girin.'));
  }
  add(f, field('Doğrulama kodu', code), go, h('button', { class: 'btn btn-ghost btn-block', type: 'button', onclick: () => loginView() }, 'Geri'));
  fill(card, h('div', { class: 'wm' }, 'DNL'), h('p', { class: 'eyebrow' }, 'Yönetim'), h('h1', null, r.next === 'setup' ? 'İki adımlı doğrulama' : 'Doğrulama kodu'), f);
  code.addEventListener('input', () => { code.value = code.value.replace(/\D/g, '').slice(0, 6); if (code.value.length === 6) f.requestSubmit(); });
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    if (code.value.length !== 6) return;
    busy(go, async () => {
      try { const x = await post('/admin/auth/verify', { code: code.value }); state.csrf = x.csrf; await boot(); }
      catch (err) { code.value = ''; if (err.code === 'pending_expired') loginView(err.message); else fail(err); }
    });
  });
}

/* ---------- Kabuk ---------- */
const ROUTES = {
  genel: ['Genel bakış', 'grid', overviewView],
  isletmeler: ['İşletmeler', 'box', businessesView],
  odemeler: ['Ödemeler', 'wallet', paymentsView],
  akis: ['İşletme akışı', 'activity', activityView],
  eposta: ['E-posta kayıtları', 'mail', emailsView],
  guvenlik: ['Güvenlik', 'shield', securityView],
  sistem: ['Sistem', 'server', systemView],
  duyuru: ['Duyuru', 'alert', announceView],
  ayarlar: ['Ayarlar', 'gear', settingsView],
  kayitlar: ['İşlem kaydı', 'clock', auditView],
  hesap: ['Hesabım', 'user', accountView],
};
function shell() {
  const nav = (tab) => Object.entries(ROUTES).filter(([k]) => !tab || ['genel', 'isletmeler', 'odemeler', 'duyuru'].includes(k)).map(([k, [t, ic]]) => h('a', { href: '#/' + k, class: 'nav-item', 'data-route': k }, icon(ic, 20), h('span', null, t)));
  app.className = 'shell'; app.removeAttribute('aria-busy');
  fill(app,
    h('aside', { class: 'side' }, h('a', { class: 'side-brand', href: '#/genel' }, h('span', { class: 'wm' }, 'DNL'), h('span', { class: 'side-tag' }, 'Yönetim')),
      h('nav', { class: 'side-nav' }, nav(false)),
      h('div', { class: 'side-foot' }, h('div', { class: 'who' }, h('span', { class: 'avatar adm-av' }, (state.me.name || 'Y').charAt(0)), h('div', null, h('b', null, state.me.name), h('small', null, 'Ana yönetici'))),
        h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Çıkış yap', onclick: logout }, icon('logout', 18)))),
    h('div', { class: 'col' }, h('header', { class: 'top' }, h('div', { class: 'top-l' }, h('span', { class: 'wm sm only-m' }, 'DNL'), h('h1', { class: 'top-title', id: 'top-title' })),
      h('div', { class: 'top-r' }, h('span', { class: 'chip on' }, h('i'), '2FA'), h('button', { class: 'icon-btn only-m', type: 'button', 'aria-label': 'Çıkış', onclick: logout }, icon('logout', 18)))),
    h('main', { class: 'main', id: 'main' })),
    h('nav', { class: 'tabs' }, nav(true), h('button', { type: 'button', class: 'nav-item', onclick: moreSheet }, icon('more', 20), h('span', null, 'Diğer'))));
}
function moreSheet() {
  const close = () => { wrap.classList.add('out'); setTimeout(() => wrap.remove(), 200); };
  const wrap = h('div', { class: 'sheet-wrap' }, h('div', { class: 'sheet-back', onclick: close }),
    h('div', { class: 'sheet', role: 'dialog', 'aria-label': 'Diğer' }, h('div', { class: 'sheet-grip' }),
      h('a', { href: '#/akis', class: 'sheet-item', onclick: close }, icon('activity', 22), 'İşletme akışı'),
      h('a', { href: '#/eposta', class: 'sheet-item', onclick: close }, icon('mail', 22), 'E-posta kayıtları'),
      h('a', { href: '#/guvenlik', class: 'sheet-item', onclick: close }, icon('shield', 22), 'Güvenlik'),
      h('a', { href: '#/sistem', class: 'sheet-item', onclick: close }, icon('server', 22), 'Sistem'),
      h('a', { href: '#/ayarlar', class: 'sheet-item', onclick: close }, icon('gear', 22), 'Ayarlar'),
      h('a', { href: '#/kayitlar', class: 'sheet-item', onclick: close }, icon('clock', 22), 'İşlem kaydı'),
      h('a', { href: '#/hesap', class: 'sheet-item', onclick: close }, icon('user', 22), 'Hesabım'),
      h('button', { type: 'button', class: 'sheet-item danger', onclick: () => { close(); logout(); } }, icon('logout', 22), 'Çıkış yap')));
  document.body.append(wrap);
}
async function logout() {
  try { await post('/admin/auth/logout', {}); } catch { /* yine de */ }
  state.me = null; loginView('Çıkış yapıldı.');
}
function route() {
  if (!state.me) return;
  const parts = location.hash.replace(/^#\/?/, '').split('/');
  let name = parts[0] || 'genel';
  if (name === 'isletme' && /^\d+$/.test(parts[1] || '')) { render('İşletme', 'isletmeler', (m) => detailView(m, parseInt(parts[1], 10))); return; }
  if (!ROUTES[name]) name = 'genel';
  const [title, , view] = ROUTES[name];
  render(title, name, view);
}
function render(title, active, view) {
  closeAllModals();
  document.title = title + ' | DNL Yönetim';
  $('#top-title').textContent = title;
  $$('.nav-item').forEach((a) => a.classList.toggle('active', a.dataset.route === active));
  const main = clear($('#main'));
  window.scrollTo(0, 0);
  current = view(main);
}

async function boot() {
  try {
    const r = await get('/admin/me');
    state.me = r.admin; state.csrf = r.csrf;
  } catch (e) {
    if (e.status === 401) { loginView(); return; }
    fill(app, h('div', { class: 'boot-err' }, h('p', null, e.message), h('button', { class: 'btn btn-primary', onclick: () => location.reload() }, 'Tekrar dene')));
    return;
  }
  shell();
  if (!location.hash) location.replace('#/genel');
  route();
}
window.addEventListener('hashchange', route);
// Hareketsizlikte sunucu oturumu 30 dk'da kapanır; ekranda da kilitle
let idle; const resetIdle = () => { clearTimeout(idle); idle = setTimeout(() => { if (state.me) { state.me = null; loginView('Güvenlik için 30 dakika hareketsizlik sonrası çıkış yapıldı.'); } }, 30 * 60 * 1000); };
['pointerdown', 'keydown'].forEach((ev) => document.addEventListener(ev, resetIdle, { passive: true }));
resetIdle();
boot();

/* ---------- Ortak ---------- */
const kpi = (label, value, href, cls) => h(href ? 'a' : 'div', { class: 'kpi' + (cls ? ' ' + cls : ''), href: href || null }, h('div', null, h('small', null, label), h('b', { class: 'mono' }, value)));
function bizRow(b) {
  return h('a', { class: 'biz-row', href: '#/isletme/' + b.id },
    h('div', { class: 'br-main' }, h('b', null, b.name), h('small', { class: 'muted' }, [b.owner_name, b.owner_email].filter(Boolean).join(' · '))),
    h('div', { class: 'br-meta' }, stateTag(b.state), h('span', { class: 'tag' }, b.plan_name + ' · ' + (PERIOD[b.period] || '')),
      h('small', { class: 'muted' }, b.state === 'suspended' ? 'Donduruldu' : b.state === 'lifetime' ? 'Süresiz' : b.state === 'expired' ? 'Bitti: ' + date(b.until) : `${b.days_left} gün kaldı`)));
}

/* ---------- Genel bakış ---------- */
function overviewView(main) {
  (async () => {
    let o;
    try { o = await get('/admin/overview'); plans = o.plans; } catch (e) { add(main, empty(e.message)); return; }
    const c = o.counts;
    const maxS = Math.max(1, ...o.signups.map((x) => x.count));
    add(main,
      h('section', { class: 'card alerts-card' }, h('div', { class: 'card-h' }, h('h2', null, 'Sistem kontrolü'), h('a', { class: 'btn btn-ghost sm', href: '#/sistem' }, icon('server', 16), 'Ayrıntı')),
        o.alerts.length ? h('ul', { class: 'alerts' }, o.alerts.map((x) => h('li', { class: 'al ' + x.level }, h('i'), h('div', null, h('b', null, x.title), h('small', null, x.text)), x.link ? h('a', { class: 'btn btn-ghost sm', href: x.link }, 'Git') : null)))
          : h('div', { class: 'all-good' }, icon('check', 20), h('span', null, 'Her şey yolunda. Hata, başarısız e-posta veya şüpheli giriş yok.'))),
      h('div', { class: 'kpis' },
        kpi('Toplam işletme', String(c.all), '#/isletmeler'), kpi('Denemede', String(c.trial), '#/isletmeler?d=trial'),
        kpi('Ücretli aktif', String(c.active), '#/isletmeler?d=active'), kpi('Süresi dolan', String(c.expired), '#/isletmeler?d=expired', c.expired ? 'warn' : ''),
        kpi('7 gün içinde bitecek', String(c.expiring), '#/isletmeler?d=expiring', c.expiring ? 'warn' : ''), kpi('Dondurulan', String(c.suspended), '#/isletmeler?d=suspended'), kpi('Ömür boyu', String(c.lifetime), '#/isletmeler?d=lifetime')),
      h('div', { class: 'kpis' }, kpi('Son 30 gün tahsilat', money(o.revenue_30d_cents), '#/odemeler'), kpi('Toplam tahsilat', money(o.revenue_all_cents), '#/odemeler'),
        kpi('Yeni kayıt (7 / 30 gün)', `${o.new_7d} / ${o.new_30d}`), kpi('Son 24 saatte aktif', String(o.active_24h))),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Yeni kayıtlar (30 gün)')),
        h('div', { class: 'spark', role: 'img', 'aria-label': 'Son 30 günde günlük yeni kayıt' }, o.signups.map((x) => h('i', { title: `${x.date}: ${x.count}`, style: { height: Math.max(3, Math.round(x.count / maxS * 100)) + '%' } })))),
      h('div', { class: 'grid2' },
        h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Süresi yaklaşanlar'), h('a', { class: 'btn btn-ghost sm', href: '#/isletmeler?d=expiring' }, 'Tümü')),
          o.expiring.length ? h('div', { class: 'biz-list' }, o.expiring.map(bizRow)) : h('p', { class: 'muted sm' }, '7 gün içinde süresi dolacak işletme yok.')),
        h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Son kayıtlar'), h('a', { class: 'btn btn-ghost sm', href: '#/isletmeler' }, 'Tümü')),
          o.recent.length ? h('div', { class: 'biz-list' }, o.recent.map(bizRow)) : h('p', { class: 'muted sm' }, 'Henüz kayıt yok.'))));
  })();
}

/* ---------- İşletmeler ---------- */
function businessesView(main) {
  const qs = new URLSearchParams(location.hash.split('?')[1] || '');
  let st = qs.get('d') || 'all', q = '', page = 1, t;
  const search = h('input', { class: 'inp', type: 'search', placeholder: 'İşletme, sahip, e-posta ya da telefon ara', 'aria-label': 'Ara' });
  const chips = h('div', { class: 'chips' });
  const list = h('div', { class: 'card' });
  add(main, h('div', { class: 'toolbar' }, h('div', { class: 'tb-l' }, search), chips), list);
  const drawChips = () => fill(chips, ...[['all', 'Tümü'], ['trial', 'Deneme'], ['active', 'Aktif'], ['expiring', 'Bitecek'], ['expired', 'Süresi dolan'], ['lifetime', 'Ömür boyu'], ['suspended', 'Dondurulan']].map(([k, l]) =>
    h('button', { type: 'button', class: 'chip-btn' + (st === k ? ' on' : ''), onclick: () => { st = k; page = 1; drawChips(); load(); } }, l)));
  search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { q = search.value.trim(); page = 1; load(); }, 300); });
  async function load() {
    let r;
    try { r = await get(`/admin/businesses?state=${st}&page=${page}&q=${encodeURIComponent(q)}`); } catch (e) { fail(e); return; }
    fill(list, h('div', { class: 'card-h' }, h('h2', null, `${r.total} işletme`)),
      r.items.length ? h('div', { class: 'biz-list' }, r.items.map((b) => {
        const row = bizRow(b);
        row.querySelector('.br-meta').append(h('small', { class: 'muted' }, `${b.tables ?? 0} masa · son giriş ${b.last_login_at ? dateTime(b.last_login_at) : '—'}`));
        return row;
      })) : empty('Sonuç yok.'),
      r.pages > 1 ? h('div', { class: 'pager' },
        h('button', { class: 'btn btn-ghost sm', disabled: page <= 1, onclick: () => { page--; load(); } }, '‹ Önceki'), h('span', { class: 'muted sm' }, `${r.page} / ${r.pages}`),
        h('button', { class: 'btn btn-ghost sm', disabled: page >= r.pages, onclick: () => { page++; load(); } }, 'Sonraki ›')) : null);
  }
  drawChips(); load();
}

/* ---------- İşletme detayı ---------- */
function detailView(main, id) {
  let d;
  const load = async () => { try { d = await get('/admin/businesses/' + id); if (!plans.length) plans = (await get('/admin/overview')).plans; render(); } catch (e) { fill(main, empty(e.message)); } };
  const set = (x) => { d = x; render(); };
  const action = (btn, path, body, msg) => busy(btn, async () => { try { set(await post(path, body)); if (msg) toast(msg, 'good'); } catch (e) { fail(e); } });
  function render() {
    const b = d.business, u = d.usage;
    document.title = b.name + ' | DNL Yönetim'; $('#top-title').textContent = b.name;
    const owner = d.users.find((x) => x.role === 'owner') || {};
    const phone = (b.owner_phone || b.phone || '').replace(/\D/g, '');
    const note = h('textarea', { class: 'inp', rows: '3', maxlength: '2000', placeholder: 'Sadece sizin göreceğiniz not (görüşmeler, anlaşmalar...)' });
    note.value = b.admin_note || '';
    fill(main,
      h('a', { class: 'back-link', href: '#/isletmeler' }, '‹ İşletmeler'),
      b.state === 'suspended' ? h('div', { class: 'banner bad' }, icon('alert', 18), h('span', null, `Donduruldu (${dateTime(b.suspended_at)}): ${b.suspend_reason}`)) : null,
      h('div', { class: 'grid2' },
        h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Abonelik'), stateTag(b.state)),
          h('dl', { class: 'dl' },
            h('dt', null, 'Paket'), h('dd', null, `${b.plan_name} · ${PERIOD[b.period] || b.period}`),
            h('dt', null, 'Deneme bitişi'), h('dd', null, dateTime(b.trial_ends_at)),
            h('dt', null, 'Ücretli dönem'), h('dd', null, b.paid_until ? dateTime(b.paid_until) : 'Yok'),
            h('dt', null, 'Kalan'), h('dd', null, b.lifetime ? 'Ömür boyu (süresiz)' : b.state === 'expired' ? 'Süresi doldu' : `${b.days_left} gün`),
            h('dt', null, 'Sınırlar'), h('dd', null, `${b.max_tables >= 1000 ? 'Sınırsız' : b.max_tables} masa · ${b.max_staff >= 200 ? 'sınırsız' : b.max_staff} personel`, b.max_tables_override !== null || b.max_staff_override !== null ? h('span', { class: 'tag warn' }, 'Özel') : null),
            h('dt', null, 'Kayıt'), h('dd', null, dateTime(b.created_at))),
          h('div', { class: 'row-wrap' },
            h('button', { class: 'btn btn-primary sm', type: 'button', onclick: paymentModal }, icon('wallet', 16), 'Ödeme al'),
            h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: trialModal }, 'Deneme uzat'),
            h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: planModal }, 'Paket değiştir'),
            h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: dateModal }, 'Bitişi düzelt'),
            h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: lifetimeModal }, b.lifetime ? 'Ömür boyu üyeliği kaldır' : 'Ömür boyu üyelik ver'),
            h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: limitsModal }, 'Sınırlar'),
            b.state === 'suspended'
              ? h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: async (ev) => { if (await confirmBox('Dondurmayı kaldır', `${b.name} yeniden kullanıma açılsın mı?`, 'Aç')) action(ev.currentTarget, `/admin/businesses/${id}/unsuspend`, {}, 'İşletme açıldı.'); } }, 'Dondurmayı kaldır')
              : h('button', { class: 'btn btn-ghost sm danger-t', type: 'button', onclick: suspendModal }, 'Dondur'))),
        h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'İletişim ve kullanım')),
          h('dl', { class: 'dl' },
            h('dt', null, 'Sahip'), h('dd', null, owner.name || '—'),
            h('dt', null, 'E-posta'), h('dd', null, owner.email ? h('a', { href: 'mailto:' + owner.email }, owner.email) : '—', ' ', owner.verified ? h('span', { class: 'tag ok' }, 'Doğrulandı') : h('span', { class: 'tag warn' }, 'Doğrulanmadı')),
            h('dt', null, 'Telefon'), h('dd', null, phone ? [h('a', { href: 'tel:+9' + phone }, b.owner_phone || b.phone), ' · ', h('a', { href: 'https://wa.me/9' + phone, target: '_blank', rel: 'noopener noreferrer' }, 'WhatsApp')] : '—'),
            h('dt', null, 'Oyun türleri'), h('dd', null, (b.types || []).map((k) => KIND_LABEL[k] || k).join(', ') || '—'),
            h('dt', null, 'Kullanım'), h('dd', null, `${u.tables} masa · ${u.products} ürün · son 30 gün ${u.sessions_30d} oturum, ${u.tickets_30d} hesap`),
            h('dt', null, 'Ciro (30 gün)'), h('dd', { class: 'mono' }, money(u.revenue_30d_cents)),
            h('dt', null, 'Son işlem'), h('dd', null, u.last_activity ? dateTime(u.last_activity) : '—'),
            h('dt', null, 'Kurulum'), h('dd', null, b.setup_done ? 'Tamamlandı' : 'Yapılmadı', b.display_enabled ? ' · müşteri ekranı açık' : ''),
            h('dt', null, 'Toplam'), h('dd', null, `${u.staff} personel · ${u.customers} müşteri · şu an ${u.open_sessions} açık masa · toplam tahsilat ${money(u.revenue_all_cents)}`)),
          h('div', { class: 'row-wrap' },
            h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: infoModal }, icon('edit', 16), 'Bilgileri düzenle'),
            h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: mailModal }, icon('mail', 16), 'E-posta gönder'),
            h('a', { class: 'btn btn-ghost sm', href: `/api/admin/businesses/${id}/export`, download: '' }, icon('download', 16), 'Verileri indir (JSON)')))),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, `Kullanıcılar (${d.users.length})`)),
        h('ul', { class: 'list' }, d.users.map((x) => h('li', { class: x.is_active ? '' : 'dim' },
          h('div', null, h('b', null, x.name), ' ', h('span', { class: 'tag' }, ROLE_LABEL[x.role]), x.locked ? h('span', { class: 'tag bad' }, 'Kilitli') : null, x.is_active ? null : h('span', { class: 'tag' }, 'Pasif'),
            h('div', { class: 'muted sm' }, x.email + ' · son giriş ' + (x.last_login_at ? dateTime(x.last_login_at) : '—'))),
          h('div', { class: 'r' }, h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: () => userMenu(x) }, 'İşlemler')))))),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Yönetici notu'), h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: (ev) => busy(ev.currentTarget, async () => { try { await post(`/admin/businesses/${id}/note`, { note: note.value }); toast('Not kaydedildi.', 'good'); } catch (e) { fail(e); } }) }, 'Kaydet')), note),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Ödeme geçmişi')),
        d.payments.length ? h('ul', { class: 'list' }, d.payments.map((p, i) => h('li', { class: p.voided ? 'void' : '' },
          h('div', null, h('b', { class: 'mono' }, money(p.amount_cents)), ' ', h('span', { class: 'tag' }, `${PLAN[p.plan] || p.plan} · ${p.months} ay`), p.voided ? h('span', { class: 'tag bad' }, 'İptal') : null,
            h('div', { class: 'muted sm' }, `${dateTime(p.created_at)} · ${p.method} · bitiş ${date(p.paid_until_after)}${p.note ? ' · ' + p.note : ''}`)),
          i === 0 && !p.voided ? h('div', { class: 'r' }, h('button', { class: 'btn btn-ghost sm danger-t', type: 'button', onclick: async (ev) => {
            if (await confirmBox('Ödeme kaydını iptal et', `${money(p.amount_cents)} tutarındaki kayıt iptal edilsin ve bitiş tarihi ${p.paid_until_before ? date(p.paid_until_before) : 'önceki haline'} geri alınsın mı?`, 'İptal et', true)) action(ev.currentTarget, `/admin/payments/${p.id}/void`, {}, 'Ödeme kaydı iptal edildi.');
          } }, 'İptal') ) : null))) : h('p', { class: 'muted sm' }, 'Ödeme kaydı yok.')),
      actLog,
      h('div', { class: 'grid2' },
        h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'E-postalar')),
          d.emails.length ? h('ul', { class: 'list' }, d.emails.map((m) => h('li', null, h('div', null, h('b', null, m.subject), h('div', { class: 'muted sm' }, `${m.to} · ${dateTime(m.at)}`), m.error ? h('div', { class: 'sm danger-t' }, m.error) : null), h('span', { class: 'tag ' + (m.status === 'sent' ? 'ok' : 'bad') }, m.status === 'sent' ? 'Gönderildi' : 'Hata')))) : h('p', { class: 'muted sm' }, 'Kayıt yok.')),
        h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Güvenlik olayları')),
          d.security.length ? h('ul', { class: 'list' }, d.security.map((x) => h('li', null, h('div', null, h('b', null, SEC[x.type] || x.type), h('div', { class: 'muted sm' }, [x.actor, x.meta].filter(Boolean).join(' · '))), h('small', { class: 'muted' }, dateTime(x.at))))) : h('p', { class: 'muted sm' }, 'Kayıt yok.'))),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Yönetici işlemleri')),
        d.log.length ? h('ul', { class: 'list' }, d.log.map((l) => h('li', null, h('div', null, h('b', null, ACTION[l.action] || l.action), ' ', h('span', { class: 'muted sm' }, metaText(l))), h('div', { class: 'r muted sm' }, (l.admin || 'Sunucu') + ' · ' + dateTime(l.at))))) : h('p', { class: 'muted sm' }, 'Kayıt yok.')),
      h('section', { class: 'card danger-zone' }, h('div', { class: 'card-h' }, h('h2', null, 'Tehlikeli bölge')),
        h('p', { class: 'muted sm' }, 'Kalıcı silme işletmenin tüm masalarını, adisyonlarını, müşterilerini ve kullanıcılarını geri dönüşsüz siler. Önce “Verileri indir” ile yedek alın. Güvenlik için işletme önce dondurulmalıdır.'),
        h('button', { class: 'btn btn-danger sm', type: 'button', disabled: b.state !== 'suspended', onclick: deleteModal }, icon('trash', 16), 'İşletmeyi kalıcı sil')));
    loadActivity(true);
  }
  function infoModal() {
    const f = h('form', { class: 'form', novalidate: true }, field('İşletme adı', inp('name', { maxlength: 80, value: d.business.name })), field('Telefon', inp('phone', { inputmode: 'tel', value: d.business.phone || '' })));
    formModal('İşletme bilgileri', f, 'Kaydet', () => put(`/admin/businesses/${id}`, { name: f.querySelector('[name=name]').value, phone: f.querySelector('[name=phone]').value }));
  }
  function lifetimeModal() {
    const on = !d.business.lifetime;
    const f = h('form', { class: 'form', novalidate: true },
      h('p', { class: 'muted' }, on ? 'İşletmenin süresi hiç dolmaz; deneme ve ücretli dönem tarihleri dikkate alınmaz. Paket sınırları geçerliliğini korur.' : 'Ömür boyu üyelik kaldırılır; işletme mevcut deneme/ücretli dönem tarihlerine göre çalışır.'),
      field('Neden', inp('reason', { maxlength: 200, placeholder: on ? 'ör. Kurucu müşteri, iş ortağı' : 'ör. Anlaşma sona erdi' })));
    formModal(on ? 'Ömür boyu üyelik ver' : 'Ömür boyu üyeliği kaldır', f, on ? 'Ömür boyu yap' : 'Kaldır', () => post(`/admin/businesses/${id}/lifetime`, { on, reason: f.querySelector('[name=reason]').value }), !on);
  }
  function limitsModal() {
    const b = d.business;
    const f = h('form', { class: 'form', novalidate: true },
      h('p', { class: 'muted sm' }, 'Boş bırakırsanız paketin sınırı geçerli olur. Özel anlaşmalar için kullanın.'),
      h('div', { class: 'grid2' }, field('Masa sınırı', inp('max_tables', { inputmode: 'numeric', class: 'inp mono', value: b.max_tables_override ?? '', placeholder: String(b.max_tables) })),
        field('Personel sınırı', inp('max_staff', { inputmode: 'numeric', class: 'inp mono', value: b.max_staff_override ?? '', placeholder: String(b.max_staff) }))));
    const num = (n) => { const v = f.querySelector(`[name=${n}]`).value.trim(); return v === '' ? null : parseInt(v, 10); };
    formModal('Özel sınırlar', f, 'Kaydet', () => post(`/admin/businesses/${id}/limits`, { max_tables: num('max_tables'), max_staff: num('max_staff') }));
  }
  function mailModal() {
    const msg = h('textarea', { class: 'inp', name: 'message', rows: '7', maxlength: '4000', placeholder: 'Mesajınız... (paragrafları boş satırla ayırın)' });
    const f = h('form', { class: 'form', novalidate: true }, field('Konu', inp('subject', { maxlength: 120 })), field('Mesaj', msg), h('p', { class: 'hint' }, 'İşletme sahibine DNL Station tasarımıyla gönderilir ve kayda geçer.'));
    const ok = h('button', { class: 'btn btn-primary', type: 'submit' }, icon('mail', 16), 'Gönder');
    const m = modal({ title: 'İşletmeye e-posta', body: f, actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), ok] });
    const run = (e) => { e && e.preventDefault(); busy(ok, async () => { try { await post(`/admin/businesses/${id}/email`, { subject: f.querySelector('[name=subject]').value, message: msg.value }); m.close(); toast('E-posta gönderildi.', 'good'); load(); } catch (err) { if (!showErrors(f, err)) fail(err); } }); };
    f.addEventListener('submit', run); ok.onclick = run;
  }
  function deleteModal() {
    const f = h('form', { class: 'form', novalidate: true },
      h('div', { class: 'banner bad' }, icon('alert', 18), h('span', null, 'Bu işlem geri alınamaz.')),
      h('p', null, 'Onaylamak için işletme adını birebir yazın: ', h('b', null, d.business.name)),
      field('İşletme adı', inp('confirm_name', { maxlength: 80, autocomplete: 'off' })));
    const ok = h('button', { class: 'btn btn-danger', type: 'submit' }, 'Kalıcı olarak sil');
    const m = modal({ title: 'İşletmeyi sil', body: f, actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), ok] });
    const run = (e) => { e && e.preventDefault(); busy(ok, async () => { try { await post(`/admin/businesses/${id}/delete`, { confirm_name: f.querySelector('[name=confirm_name]').value }); m.close(); toast('İşletme silindi.', 'good'); location.hash = '#/isletmeler'; } catch (err) { if (!showErrors(f, err)) fail(err); } }); };
    f.addEventListener('submit', run); ok.onclick = run;
  }
  let actLast = 0;
  const actList = h('ul', { class: 'list' });
  const actMore = h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: () => loadActivity(false) }, 'Daha eski');
  const actLog = h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'İşletme işlem kaydı'), h('small', { class: 'muted' }, 'Panelde kim ne yaptı')), actList, actMore);
  async function loadActivity(reset) {
    if (reset) { actLast = 0; clear(actList); }
    try {
      const r = await get(`/admin/businesses/${id}/activity` + (actLast ? '?before=' + actLast : ''));
      r.items.forEach((x) => { actList.append(h('li', null, h('div', null, h('b', null, BIZ[x.action] || x.action), ' ', h('span', { class: 'muted sm' }, bizMeta(x))), h('small', { class: 'muted r' }, (x.user || '—') + ' · ' + dateTime(x.at)))); actLast = x.id; });
      actMore.hidden = r.items.length < 100;
      if (!actList.childElementCount) actList.append(h('li', { class: 'muted' }, 'Henüz işlem yok.'));
    } catch (e) { fail(e); }
  }
  function formModal(title, f, okText, submit, danger) {
    const ok = h('button', { class: 'btn ' + (danger ? 'btn-danger' : 'btn-primary'), type: 'submit' }, okText);
    const m = modal({ title, body: f, actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), ok] });
    const run = (e) => { e && e.preventDefault(); busy(ok, async () => { try { set(await submit()); m.close(); toast('Kaydedildi.', 'good'); } catch (err) { if (!showErrors(f, err)) fail(err); } }); };
    f.addEventListener('submit', run); ok.onclick = run;
  }
  function paymentModal() {
    const b = d.business;
    let plan = b.plan, period = b.period;
    const amount = moneyInp('amount', null);
    const months = inp('months', { inputmode: 'numeric', class: 'inp mono' });
    const sync = () => { const p = plans.find((x) => x.key === plan); if (p) amount.value = String(period === 'yillik' ? p.yearly / 100 : p.monthly / 100).replace('.', ','); months.value = period === 'yillik' ? '12' : '1'; };
    const f = h('form', { class: 'form', novalidate: true },
      h('div', { class: 'field' }, h('span', { class: 'lbl' }, 'Paket'), seg(plans.map((p) => [p.key, p.name]), plan, (v) => { plan = v; sync(); })),
      h('div', { class: 'field' }, h('span', { class: 'lbl' }, 'Dönem'), seg([['aylik', 'Aylık'], ['yillik', 'Yıllık']], period, (v) => { period = v; sync(); })),
      h('div', { class: 'grid2' }, field('Alınan tutar (₺)', amount), field('Süre (ay)', months)),
      field('Ödeme yöntemi', sel('method', [['havale', 'Havale / EFT'], ['nakit', 'Nakit'], ['kart', 'Kart'], ['diger', 'Diğer']], 'havale')),
      field('Not', inp('note', { maxlength: 200, placeholder: 'ör. dekont no, gönderen adı' })),
      h('p', { class: 'hint' }, 'Süre, mevcut ücretli dönem bitmediyse kaldığı yerden; bittiyse bugünden itibaren eklenir.'));
    sync();
    formModal(b.name + ' · Ödeme al', f, 'Ödemeyi kaydet', () => {
      const c = inputToCents(amount.value);
      return post(`/admin/businesses/${id}/payments`, { plan, period, months: parseInt(months.value, 10) || 0, amount: Number.isFinite(c) && c !== null ? moneyStr(c) : amount.value, method: f.querySelector('[name=method]').value, note: f.querySelector('[name=note]').value });
    });
  }
  function trialModal() {
    const f = h('form', { class: 'form', novalidate: true }, field('Kaç gün eklensin?', inp('days', { inputmode: 'numeric', class: 'inp mono', value: '7' }), 'Deneme bitmediyse bitiş tarihine, bittiyse bugüne eklenir (en fazla 90).'));
    formModal('Deneme süresini uzat', f, 'Uzat', () => post(`/admin/businesses/${id}/trial`, { days: parseInt(f.querySelector('[name=days]').value, 10) || 0 }));
  }
  function planModal() {
    const f = h('form', { class: 'form', novalidate: true },
      field('Paket', sel('plan', plans.map((p) => [p.key, p.name]), d.business.plan)), field('Dönem', sel('period', [['aylik', 'Aylık'], ['yillik', 'Yıllık']], d.business.period)),
      h('p', { class: 'hint' }, 'Bitiş tarihine dokunmaz. Paket sınırları (masa, personel) hemen değişir.'));
    formModal('Paket değiştir', f, 'Kaydet', () => post(`/admin/businesses/${id}/plan`, { plan: f.querySelector('[name=plan]').value, period: f.querySelector('[name=period]').value }));
  }
  function dateModal() {
    const cur = d.business.paid_until ? new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Istanbul' }).format(new Date(d.business.paid_until)) : '';
    const f = h('form', { class: 'form', novalidate: true },
      field('Ücretli dönem bitişi', inp('date', { type: 'date', class: 'inp mono', value: cur }), 'Boş bırakırsanız ücretli dönem kaldırılır.'),
      field('Neden', inp('reason', { maxlength: 200, placeholder: 'ör. hatalı kayıt düzeltmesi' })));
    formModal('Bitiş tarihini düzelt', f, 'Kaydet', () => post(`/admin/businesses/${id}/paid-until`, { date: f.querySelector('[name=date]').value || null, reason: f.querySelector('[name=reason]').value }));
  }
  function suspendModal() {
    const f = h('form', { class: 'form', novalidate: true },
      h('p', { class: 'muted' }, 'İşletmenin tüm kullanıcılarının oturumu hemen kapanır, giriş yapamaz ve müşteri ekranı durur. Veriler silinmez; istediğiniz an geri açabilirsiniz.'),
      field('Neden', inp('reason', { maxlength: 200, placeholder: 'ör. ödeme yapılmadı' })));
    formModal('İşletmeyi dondur', f, 'Dondur', () => post(`/admin/businesses/${id}/suspend`, { reason: f.querySelector('[name=reason]').value }), true);
  }
  function emailChange(x) {
    const f = h('form', { class: 'form', novalidate: true }, field('Yeni e-posta', inp('email', { type: 'email', value: x.email })), h('p', { class: 'hint' }, 'Kullanıcının oturumları kapanır; yeni adrese doğrulama e-postası gider.'));
    formModal(x.name + ' · e-posta', f, 'Değiştir', () => post(`/admin/users/${x.id}/email`, { email: f.querySelector('[name=email]').value }));
  }
  function userMenu(x) {
    const act = (a, label, danger) => h('button', { class: 'sheet-item' + (danger ? ' danger' : ''), type: 'button', onclick: async (ev) => {
      if (!await confirmBox(label, `${x.name} (${x.email}) için “${label}” yapılsın mı?`, 'Onayla', danger)) return;
      m.close(); action(ev.currentTarget, a === 'make_owner' ? `/admin/users/${x.id}/make-owner` : `/admin/users/${x.id}/action`, a === 'make_owner' ? {} : { action: a }, 'İşlem tamamlandı.');
    } }, label);
    const m = modal({ title: x.name, body: h('div', { class: 'user-acts' },
      x.locked ? act('unlock', 'Kilidi aç') : null,
      act('send_reset', 'Şifre sıfırlama bağlantısı gönder'),
      x.verified ? null : act('resend_verify', 'Doğrulama e-postasını tekrar gönder'),
      act('logout_all', 'Tüm cihazlarda oturumu kapat'),
      h('button', { class: 'sheet-item', type: 'button', onclick: () => { m.close(); emailChange(x); } }, 'Giriş e-postasını değiştir'),
      x.role !== 'owner' && x.is_active ? act('make_owner', 'İşletme sahibi yap') : null,
      x.role !== 'owner' ? (x.is_active ? act('deactivate', 'Pasife al', true) : act('activate', 'Aktif et')) : null,
      h('p', { class: 'hint' }, 'Güvenlik gereği kullanıcı şifreleri görüntülenemez ve yönetici tarafından belirlenemez; kullanıcı sıfırlama bağlantısıyla kendi şifresini seçer.')) });
  }
  load();
}

/* ---------- Etiketler ---------- */
const SEC = {
  login_failed: 'Hatalı giriş', login_ok: 'Başarılı giriş', account_locked: 'Hesap kilitlendi', login_suspended: 'Dondurulan hesaptan giriş denemesi',
  reset_requested: 'Şifre sıfırlama istendi', bot_trap: 'Bot engellendi', rate_limited: 'Hız sınırı aşıldı', csrf_failed: 'Geçersiz form anahtarı',
  bad_origin: 'Yabancı kaynaktan istek', display_bad_key: 'Geçersiz ekran anahtarı', migrate_denied: 'Yetkisiz güncelleme denemesi',
  admin_login_failed: 'Yönetici: hatalı giriş', admin_login_ok: 'Yönetici: giriş', admin_locked: 'Yönetici hesabı kilitlendi',
};
const BIZ = {
  'auth.login': 'Giriş', 'auth.logout': 'Çıkış', 'auth.register': 'Kayıt', 'auth.locked': 'Hesap kilitlendi', 'auth.password_changed': 'Şifre değişti', 'auth.reset_requested': 'Şifre sıfırlama istendi', 'auth.verified': 'E-posta doğrulandı',
  'session.open': 'Masa açıldı', 'session.close': 'Hesap kapatıldı', 'session.cancel': 'Oturum iptal', 'session.reopen': 'Hesap geri alındı', 'session.extend': 'Süre eklendi', 'session.controllers': 'Kol değişti', 'session.match': 'Maç', 'session.move': 'Masa taşındı',
  'item.remove': 'Ürün çıkarıldı', 'quick.sale': 'Hızlı satış', 'quick.void': 'Satış iptal', 'shift.open': 'Kasa açıldı', 'shift.close': 'Kasa kapatıldı',
  'product.create': 'Ürün eklendi', 'product.update': 'Ürün güncellendi', 'product.archive': 'Ürün kaldırıldı', 'stock.restock': 'Stok girişi', 'stock.count': 'Stok sayımı',
  'type.create': 'Masa türü eklendi', 'type.update': 'Ücret güncellendi', 'type.delete': 'Tür silindi', 'table.create': 'Masa eklendi', 'table.update': 'Masa güncellendi', 'table.delete': 'Masa silindi',
  'staff.create': 'Personel eklendi', 'staff.update': 'Personel güncellendi', 'staff.reset_password': 'Personel şifresi sıfırlandı', 'business.update': 'İşletme ayarları', 'setup.quick': 'İlk kurulum',
  'display.rotate': 'Ekran bağlantısı yenilendi', 'display.disable': 'Ekran kapatıldı', 'customer.create': 'Müşteri eklendi', 'customer.update': 'Müşteri güncellendi', 'customer.collect': 'Veresiye tahsilatı',
  'expense.add': 'Gider eklendi', 'expense.void': 'Gider iptal', 'reservation.create': 'Rezervasyon', 'reservation.update': 'Rezervasyon güncellendi', 'reservation.cancelled': 'Rezervasyon iptal', 'reservation.arrived': 'Rezervasyon geldi', 'reservation.noshow': 'Rezervasyon gelmedi', 'reservation.booked': 'Rezervasyon aktif',
};
function bizMeta(x) {
  const m = x.meta || {};
  if (m.total !== undefined) return money(m.total);
  if (m.amount !== undefined) return money(m.amount);
  if (m.reason) return m.reason;
  if (m.name) return m.name;
  return '';
}
const KIND = { welcome: 'Hoş geldin', verify: 'Doğrulama', reset: 'Şifre sıfırlama', z_report: 'Z raporu', admin_message: 'Yönetici mesajı', test: 'Test' };
function moreBtn(load) { const b = h('button', { class: 'btn btn-ghost', type: 'button' }, 'Daha eski'); b.onclick = () => busy(b, load); return b; }

/* ---------- İşletme akışı (tüm işletmeler) ---------- */
function activityView(main) {
  const list = h('ul', { class: 'list' });
  let last = 0;
  const more = moreBtn(load);
  add(main, h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Tüm işletmelerde son işlemler')), list, more));
  async function load() {
    try {
      const r = await get('/admin/activity' + (last ? '?before=' + last : ''));
      r.items.forEach((x) => { list.append(h('li', null, h('div', null, h('b', null, BIZ[x.action] || x.action), ' ', h('span', { class: 'muted sm' }, bizMeta(x)),
        h('div', { class: 'muted sm' }, x.business_id ? h('a', { href: '#/isletme/' + x.business_id }, x.business || '#' + x.business_id) : '—', ' · ', x.user || '—')), h('small', { class: 'muted r' }, dateTime(x.at)))); last = x.id; });
      more.hidden = r.items.length < 100;
      if (!list.childElementCount) list.append(h('li', { class: 'muted' }, 'Henüz işlem yok.'));
    } catch (e) { fail(e); }
  }
  load();
}

/* ---------- E-posta kayıtları ---------- */
function emailsView(main) {
  let st = '', q = '', last = 0, t;
  const statsBox = h('div', { class: 'kpis' });
  const chips = h('div', { class: 'chips' });
  const search = h('input', { class: 'inp', type: 'search', placeholder: 'Alıcı e-posta ara', 'aria-label': 'Ara' });
  const list = h('ul', { class: 'list' });
  const more = moreBtn(() => load(false));
  const testTo = inp('to', { type: 'email', placeholder: 'test@ornek.com', value: state.me.email });
  const testBtn = h('button', { class: 'btn btn-primary sm', type: 'button' }, icon('mail', 16), 'Test gönder');
  testBtn.onclick = () => busy(testBtn, async () => { try { const r = await post('/admin/system/test-email', { to: testTo.value }); toast(r.message, 'good'); load(true); } catch (e) { fail(e); } });
  add(main, statsBox,
    h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Test e-postası')), h('div', { class: 'row-wrap' }, testTo, testBtn)),
    h('div', { class: 'toolbar' }, h('div', { class: 'tb-l' }, search), chips),
    h('section', { class: 'card' }, list, more));
  const drawChips = () => fill(chips, ...[['', 'Tümü'], ['sent', 'Gönderilen'], ['failed', 'Hatalı']].map(([k, l]) => h('button', { type: 'button', class: 'chip-btn' + (st === k ? ' on' : ''), onclick: () => { st = k; drawChips(); load(true); } }, l)));
  search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { q = search.value.trim(); load(true); }, 300); });
  async function load(reset) {
    if (reset) { last = 0; clear(list); }
    try {
      const r = await get(`/admin/emails?status=${st}&q=${encodeURIComponent(q)}` + (last ? '&before=' + last : ''));
      fill(statsBox, kpi('Gönderilen (24 saat)', String(r.stats.sent_24h)), kpi('Hatalı (24 saat)', String(r.stats.failed_24h), null, r.stats.failed_24h ? 'warn' : ''), kpi('Toplam kayıt', String(r.stats.total)));
      r.items.forEach((m) => { list.append(h('li', null, h('div', null, h('b', null, m.subject), ' ', h('span', { class: 'tag' }, KIND[m.kind] || m.kind || 'Diğer'),
        h('div', { class: 'muted sm' }, m.to, m.business_id ? [' · ', h('a', { href: '#/isletme/' + m.business_id }, m.business || '#' + m.business_id)] : null, ' · ', dateTime(m.at)),
        m.error ? h('div', { class: 'sm danger-t' }, m.error) : null), h('span', { class: 'tag ' + (m.status === 'sent' ? 'ok' : 'bad') }, m.status === 'sent' ? 'Gönderildi' : 'Hata'))); last = m.id; });
      more.hidden = r.items.length < 100;
      if (!list.childElementCount) list.append(h('li', { class: 'muted' }, 'Kayıt yok.'));
    } catch (e) { fail(e); }
  }
  drawChips(); load(true);
}

/* ---------- Güvenlik ---------- */
function securityView(main) {
  let type = '', last = 0;
  const top = h('div', { class: 'grid2' });
  const chips = h('div', { class: 'chips' });
  const list = h('ul', { class: 'list' });
  const more = moreBtn(() => load(false));
  add(main, top, h('div', { class: 'toolbar' }, chips), h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Güvenlik olayları'), h('small', { class: 'muted' }, 'IP adresleri gizlilik için özet (parmak izi) olarak tutulur')), list, more));
  const drawChips = () => fill(chips, ...[['', 'Tümü'], ['login_failed', 'Hatalı giriş'], ['account_locked', 'Kilit'], ['rate_limited', 'Hız sınırı'], ['csrf_failed', 'Form anahtarı'], ['bot_trap', 'Bot'], ['admin_login_failed', 'Yönetici']].map(([k, l]) =>
    h('button', { type: 'button', class: 'chip-btn' + (type === k ? ' on' : ''), onclick: () => { type = k; drawChips(); load(true); } }, l)));
  async function load(reset) {
    if (reset) { last = 0; clear(list); }
    try {
      const r = await get(`/admin/security?type=${type}` + (last ? '&before=' + last : ''));
      if (reset) {
        fill(top,
          h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Son 24 saat')),
            r.by_type_24h.length ? h('ul', { class: 'list' }, r.by_type_24h.map((x) => h('li', null, h('span', null, SEC[x.type] || x.type), h('b', { class: 'mono' }, String(x.count))))) : h('p', { class: 'muted sm' }, 'Olay yok.'),
            r.top_sources_24h.length ? [h('h3', { style: { marginTop: '14px' } }, 'En çok olay üreten kaynaklar'), h('ul', { class: 'list' }, r.top_sources_24h.map((x) => h('li', null, h('code', { class: 'mono' }, x.ip_hash), h('b', { class: 'mono' }, String(x.count)))))] : null),
          h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Kilitli hesaplar')),
            r.locked_users.length ? h('ul', { class: 'list' }, r.locked_users.map((u) => h('li', null, h('div', null, h('b', null, u.name), h('div', { class: 'muted sm' }, u.email, ' · ', h('a', { href: '#/isletme/' + u.business_id }, u.business))),
              h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: (ev) => busy(ev.currentTarget, async () => { try { await post(`/admin/users/${u.id}/action`, { action: 'unlock' }); toast('Kilit açıldı.', 'good'); load(true); } catch (e) { fail(e); } }) }, 'Kilidi aç')))) : h('p', { class: 'muted sm' }, 'Kilitli hesap yok.')));
      }
      r.items.forEach((x) => { list.append(h('li', null, h('div', null, h('b', null, SEC[x.type] || x.type), ' ', x.meta ? h('span', { class: 'muted sm' }, x.meta) : null,
        h('div', { class: 'muted sm' }, [x.actor || '—', x.business ? null : null].filter(Boolean).join(''), x.business_id ? [' · ', h('a', { href: '#/isletme/' + x.business_id }, x.business || '#' + x.business_id)] : null, ' · ', h('code', null, x.ip_hash)),
        x.ua ? h('div', { class: 'muted sm ua' }, x.ua) : null), h('small', { class: 'muted r' }, dateTime(x.at)))); last = x.id; });
      more.hidden = r.items.length < 100;
      if (!list.childElementCount) list.append(h('li', { class: 'muted' }, 'Kayıt yok.'));
    } catch (e) { fail(e); }
  }
  drawChips(); load(true);
}

/* ---------- Sistem ---------- */
function systemView(main) {
  (async () => {
    let s;
    try { s = await get('/admin/system/status'); } catch (e) { add(main, empty(e.message)); return; }
    const mMsg = inp('mmsg', { maxlength: 200, value: s.maintenance.message, placeholder: 'ör. 10 dakikalık bakım yapıyoruz' });
    const mBtn = h('button', { class: 'btn ' + (s.maintenance.on ? 'btn-primary' : 'btn-danger') + ' sm', type: 'button' }, s.maintenance.on ? 'Bakım modunu kapat' : 'Bakım modunu aç');
    mBtn.onclick = async () => {
      const on = !s.maintenance.on;
      if (on && !await confirmBox('Bakım modu', 'Tüm işletmeler panele erişemez (yönetim paneli çalışmaya devam eder). Açılsın mı?', 'Aç', true)) return;
      busy(mBtn, async () => { try { await post('/admin/system/maintenance', { on, message: mMsg.value }); toast(on ? 'Bakım modu açıldı.' : 'Bakım modu kapandı.', 'good'); render('Sistem', 'sistem', systemView); } catch (e) { fail(e); } });
    };
    const gcBtn = h('button', { class: 'btn btn-ghost sm', type: 'button' }, icon('refresh', 16), 'Eski kayıtları temizle');
    gcBtn.onclick = () => busy(gcBtn, async () => { try { toast((await post('/admin/system/gc', {})).message, 'good', 6000); } catch (e) { fail(e); } });
    const clrBtn = h('button', { class: 'btn btn-ghost sm', type: 'button' }, icon('trash', 16), 'Hata günlüğünü temizle');
    clrBtn.onclick = async () => { if (!await confirmBox('Hata günlüğü', 'Tüm hata kayıtları silinsin mi?', 'Temizle', true)) return; busy(clrBtn, async () => { try { toast((await post('/admin/system/clear-errors', {})).message, 'good'); render('Sistem', 'sistem', systemView); } catch (e) { fail(e); } }); };
    const okCount = s.checks.filter((c) => c.level === 'ok').length;
    add(main,
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Sistem testleri'), h('span', { class: 'tag ' + (okCount === s.checks.length ? 'ok' : 'warn') }, `${okCount}/${s.checks.length} başarılı`),
        h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: () => render('Sistem', 'sistem', systemView) }, icon('refresh', 16), 'Yeniden çalıştır')),
        h('ul', { class: 'checks' }, s.checks.map((c) => h('li', { class: c.level }, h('i'), h('b', null, c.name), h('span', { class: 'muted' }, c.detail))))),
      h('div', { class: 'grid2' },
        h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Bakım modu'), s.maintenance.on ? h('span', { class: 'tag bad' }, 'AÇIK') : h('span', { class: 'tag ok' }, 'Kapalı')),
          h('p', { class: 'muted sm' }, 'Güncelleme sırasında işletmelerin paneli kısa süre kapatmak için. İşletmeler mesajınızı görür.'), h('div', { class: 'form' }, field('Mesaj', mMsg), mBtn)),
        h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Bakım işlemleri')),
          h('div', { class: 'row-wrap' }, gcBtn, clrBtn, h('a', { class: 'btn btn-ghost sm', href: '#/eposta' }, icon('mail', 16), 'E-posta testi')),
          h('dl', { class: 'dl', style: { marginTop: '14px' } }, ...Object.entries({ 'PHP': s.server.php, 'Çalışma': s.server.sapi, 'Bellek sınırı': s.server.memory_limit, 'Zaman aşımı': s.server.max_execution_time + ' sn', 'Sunucu saati (UTC)': s.server.time_utc }).flatMap(([k, v]) => [h('dt', null, k), h('dd', { class: 'mono' }, String(v))])))),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Veritabanı tabloları')),
        h('div', { class: 'tbl-chips' }, s.tables.map((t) => h('span', { class: 'tag' }, `${t.table}: ${t.rows.toLocaleString('tr-TR')}`)))),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, `Sistem hata kaydı (${s.errors.length})`)),
        s.errors.length ? h('ul', { class: 'list errs' }, s.errors.map((e) => h('li', null, h('div', null, h('b', { class: 'mono' }, e.type || 'Kayıt'), ' ', h('span', { class: 'muted sm' }, e.context), h('div', { class: 'mono sm err-msg' }, e.message)), h('small', { class: 'muted r' }, e.at ? dateTime(e.at) : '')))) : h('div', { class: 'all-good' }, icon('check', 20), h('span', null, 'Kayıtlı sistem hatası yok.'))));
  })();
}

/* ---------- Ödemeler ---------- */
function paymentsView(main) {
  (async () => {
    let r;
    try { r = await get('/admin/payments'); } catch (e) { add(main, empty(e.message)); return; }
    add(main,
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Aylık tahsilat')),
        r.by_month.length ? h('ul', { class: 'list' }, r.by_month.map((m) => h('li', null, h('b', null, new Intl.DateTimeFormat('tr-TR', { month: 'long', year: 'numeric' }).format(new Date(m.month + '-15'))), h('b', { class: 'mono' }, money(m.total_cents))))) : h('p', { class: 'muted sm' }, 'Henüz ödeme yok.')),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Tüm ödeme kayıtları')),
        r.items.length ? h('ul', { class: 'list' }, r.items.map((p) => h('li', { class: p.voided ? 'void' : '' },
          h('div', null, h('a', { href: '#/isletme/' + p.business_id }, h('b', null, p.business_name || '#' + p.business_id)), ' ', h('span', { class: 'tag' }, `${PLAN[p.plan] || p.plan} · ${p.months} ay`), p.voided ? h('span', { class: 'tag bad' }, 'İptal') : null,
            h('div', { class: 'muted sm' }, `${dateTime(p.created_at)} · ${p.method}${p.note ? ' · ' + p.note : ''}`)),
          h('b', { class: 'mono r' }, money(p.amount_cents))))) : h('p', { class: 'muted sm' }, 'Henüz ödeme yok.')));
  })();
}

/* ---------- Duyuru ---------- */
function announceView(main) {
  (async () => {
    let a;
    try { a = (await get('/admin/announcement')).announcement || { text: '', level: 'info', active: false }; } catch (e) { add(main, empty(e.message)); return; }
    const text = h('textarea', { class: 'inp', name: 'text', rows: '3', maxlength: '300', placeholder: 'ör. Bu gece 03:00-03:30 arası kısa bir bakım yapılacaktır.' });
    text.value = a.text || '';
    const f = h('form', { class: 'form narrow', novalidate: true },
      field('Duyuru metni', text), field('Tür', sel('level', [['info', 'Bilgi'], ['warn', 'Uyarı']], a.level)),
      toggle('active', 'Tüm işletme panellerinde göster', a.active));
    const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Kaydet');
    f.append(save);
    add(main, h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Panel duyurusu')), h('p', { class: 'muted sm' }, 'Açık olduğunda tüm işletmelerin panelinin üstünde görünür. Kullanıcı kapatabilir; metni değiştirince yeniden gösterilir.'), f));
    f.addEventListener('submit', (e) => { e.preventDefault(); busy(save, async () => {
      try { await put('/admin/announcement', { text: text.value, level: f.querySelector('[name=level]').value, active: f.querySelector('[name=active]').checked }); toast('Duyuru kaydedildi.', 'good'); } catch (err) { if (!showErrors(f, err)) fail(err); }
    }); });
  })();
}

/* ---------- Genel ayarlar ---------- */
function settingsView(main) {
  (async () => {
    let st;
    try { st = (await get('/admin/settings')).settings; } catch (e) { add(main, empty(e.message)); return; }
    const pay = h('textarea', { class: 'inp mono', name: 'payment_info', rows: '5', maxlength: '600', placeholder: 'Alıcı: Ad Soyad\nBanka: ...\nIBAN: TR00 0000 ...' });
    pay.value = st.payment_info || '';
    const f = h('form', { class: 'form narrow', novalidate: true },
      field('Yeni kayıtlarda deneme süresi (gün)', inp('trial_days', { inputmode: 'numeric', class: 'inp mono', value: String(st.trial_days) }), 'Mevcut işletmelerin süresi değişmez; tek tek uzatmak için işletme detayını kullanın.'),
      field('Ödeme bilgileri (işletmelerin “Paketim” sayfasında görünür)', pay),
      field('WhatsApp numarası', inp('whatsapp', { inputmode: 'tel', value: st.whatsapp || '', placeholder: '05XX XXX XX XX' })),
      field('Destek e-postası', inp('support_email', { type: 'email', value: st.support_email || '', placeholder: 'support@dnlstation.com.tr' })));
    const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Kaydet');
    f.append(save);
    add(main, h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Genel ayarlar')), f));
    f.addEventListener('submit', (e) => { e.preventDefault(); busy(save, async () => {
      const v = (n) => f.querySelector(`[name=${n}]`).value;
      try { await put('/admin/settings', { trial_days: parseInt(v('trial_days'), 10) || 0, payment_info: v('payment_info'), whatsapp: v('whatsapp'), support_email: v('support_email') }); toast('Ayarlar kaydedildi.', 'good'); }
      catch (err) { if (!showErrors(f, err)) fail(err); }
    }); });
  })();
}

/* ---------- İşlem kaydı ---------- */
const ACTION = {
  'admin.login': 'Yönetici girişi', 'admin.logout': 'Yönetici çıkışı', 'admin.login_failed': 'Hatalı giriş denemesi', 'admin.locked': 'Yönetici hesabı kilitlendi', 'admin.2fa_enabled': '2FA kuruldu', 'admin.password_changed': 'Yönetici şifresi değişti',
  'cli.admin_created': 'Yönetici oluşturuldu (sunucu)', 'cli.password_reset': 'Yönetici şifresi sıfırlandı (sunucu)', 'cli.2fa_reset': '2FA sıfırlandı (sunucu)',
  'business.plan': 'Paket değişti', 'business.trial': 'Deneme uzatıldı', 'business.payment': 'Ödeme kaydedildi', 'business.payment_void': 'Ödeme kaydı iptal', 'business.paid_until': 'Bitiş düzeltildi',
  'business.suspend': 'İşletme donduruldu', 'business.unsuspend': 'Dondurma kaldırıldı', 'business.note': 'Not güncellendi',
  'user.unlock': 'Kullanıcı kilidi açıldı', 'user.logout_all': 'Kullanıcı oturumları kapatıldı', 'user.deactivate': 'Kullanıcı pasife alındı', 'user.activate': 'Kullanıcı aktif edildi', 'user.send_reset': 'Sıfırlama bağlantısı gönderildi', 'user.resend_verify': 'Doğrulama e-postası gönderildi',
  'system.announcement': 'Duyuru güncellendi', 'system.settings': 'Genel ayarlar güncellendi',
  'business.lifetime_on': 'Ömür boyu üyelik verildi', 'business.lifetime_off': 'Ömür boyu üyelik kaldırıldı', 'business.limits': 'Özel sınır', 'business.info': 'İşletme bilgisi düzenlendi',
  'business.email': 'İşletmeye e-posta', 'business.export': 'Veriler indirildi', 'business.deleted': 'İşletme silindi', 'business.owner_transfer': 'Sahiplik devredildi', 'user.email_changed': 'Kullanıcı e-postası değişti',
  'system.test_email': 'Test e-postası', 'system.gc': 'Temizlik', 'system.maintenance_on': 'Bakım modu açıldı', 'system.maintenance_off': 'Bakım modu kapandı', 'system.logs_cleared': 'Hata günlüğü temizlendi',
};
function metaText(l) {
  const m = l.meta || {};
  if (l.action === 'business.payment') return money(m.amount || 0) + ' · ' + m.months + ' ay';
  if (l.action === 'business.trial') return '+' + m.days + ' gün';
  if (l.action === 'business.plan') return `${m.from} → ${m.to}`;
  if (l.action === 'business.suspend' || l.action === 'business.paid_until') return m.reason || '';
  if (l.action === 'system.announcement') return m.active ? m.text : 'kapatıldı';
  return '';
}
function auditView(main) {
  const list = h('ul', { class: 'list' });
  const more = h('button', { class: 'btn btn-ghost', type: 'button' }, 'Daha eski');
  add(main, h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Yönetici işlem kaydı')), list, more));
  let last = 0;
  const load = () => busy(more, async () => {
    try {
      const r = await get('/admin/audit' + (last ? '?before=' + last : ''));
      r.items.forEach((l) => { list.append(h('li', null, h('div', null, h('b', null, ACTION[l.action] || l.action), ' ',
        l.entity === 'business' && l.entity_id ? h('a', { href: '#/isletme/' + l.entity_id }, '#' + l.entity_id) : null, ' ', h('span', { class: 'muted sm' }, metaText(l))),
        h('div', { class: 'r muted sm' }, (l.admin || 'Sunucu') + ' · ' + dateTime(l.at)))); last = l.id; });
      more.hidden = r.items.length < 100;
    } catch (e) { fail(e); }
  });
  more.onclick = load; load();
}

/* ---------- Hesabım ---------- */
function accountView(main) {
  const pw = (n, l, ac) => field(l, inp(n, { type: 'password', autocomplete: ac, maxlength: 128 }));
  const f = h('form', { class: 'form narrow', novalidate: true },
    pw('current_password', 'Mevcut şifre', 'current-password'), pw('password', 'Yeni şifre (en az 12 karakter)', 'new-password'), pw('password_confirm', 'Yeni şifre tekrar', 'new-password'),
    field('Authenticator kodu', inp('code', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: 6, class: 'inp mono' })));
  const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Şifreyi değiştir');
  f.append(save);
  add(main,
    h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Hesap')),
      h('dl', { class: 'dl' }, h('dt', null, 'Ad'), h('dd', null, state.me.name), h('dt', null, 'E-posta'), h('dd', null, state.me.email), h('dt', null, 'Güvenlik'), h('dd', null, 'Şifre + Authenticator (2 adımlı)'))),
    h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Şifre değiştir')), f,
      h('p', { class: 'hint' }, 'Telefonunuzu kaybederseniz: sunucuda Terminal’de “bash public_html/_app/yonetici.sh” → 3 ile Authenticator’ı sıfırlayabilirsiniz.')));
  f.addEventListener('submit', (e) => { e.preventDefault(); busy(save, async () => {
    const v = (n) => f.querySelector(`[name=${n}]`).value;
    try { const r = await post('/admin/account/password', { current_password: v('current_password'), password: v('password'), password_confirm: v('password_confirm'), code: v('code') }); state.csrf = r.csrf; f.reset(); toast('Şifre değişti; diğer oturumlar kapatıldı.', 'good'); }
    catch (err) { if (!showErrors(f, err)) fail(err); }
  }); });
}
void current;
