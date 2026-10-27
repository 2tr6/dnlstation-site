// Paketim: abonelik durumu, paketler (aylık / yıllık / ömür boyu) ve ödeme talimatı (yalnızca işletme sahibi)
import { h, fill, add, icon, get, toast, money, dateTime, empty, seg } from './core.js?v=1';

const PERIOD = { aylik: 'Aylık', yillik: 'Yıllık' };
const STATE = { trial: ['Deneme sürümü', 'warn'], active: ['Aktif', 'ok'], expired: ['Süresi doldu', 'bad'], lifetime: ['Ömür boyu', 'ok'] };
const fmt = (c) => money(c).replace(',00', '');

export function billingView(ctx) {
  const { main } = ctx;
  (async () => {
    let b;
    try { b = await get('/billing'); } catch (e) { add(main, empty(e.message)); return; }
    const s = b.subscription;
    const owned = s.state === 'lifetime';
    const [stLabel, stCls] = STATE[s.state] || [s.state, ''];
    const wa = b.whatsapp ? '90' + b.whatsapp.replace(/^0/, '') : '';
    const waText = encodeURIComponent(`Merhaba, DNL Station paketimi yenilemek istiyorum. İşletme kodum: ${b.reference}`);
    const copy = async (t, msg) => { try { await navigator.clipboard.writeText(t); toast(msg, 'good', 1500); } catch { toast('Kopyalanamadı.', 'bad'); } };

    // Dönem seçimi: Aylık / Yıllık / Ömür boyu
    let view = owned ? 'omur' : (s.period === 'yillik' ? 'yillik' : 'aylik');
    const plansBox = h('div', { class: 'plans' });
    const draw = () => {
      const cards = view === 'omur'
        ? [lifetimeCard()]
        : b.plans.map((p) => planCard(p));
      fill(plansBox, ...cards);
    };
    const planCard = (p) => {
      const current = !owned && p.key === s.plan;
      const yearly = view === 'yillik';
      return h('section', { class: 'card plan' + (current ? ' cur' : '') },
        h('div', { class: 'card-h' }, h('h2', null, p.name), current ? h('span', { class: 'tag ok' }, 'Mevcut') : null),
        h('p', { class: 'plan-price mono' }, fmt(yearly ? p.yearly : p.monthly), h('small', null, yearly ? ' /yıl' : ' /ay')),
        h('p', { class: 'muted sm' }, yearly ? `Aylık ${fmt(p.monthly)} yerine (2 ay ücretsiz)` : `Yıllık ${fmt(p.yearly)} (2 ay ücretsiz)`),
        h('ul', { class: 'plan-f' }, h('li', null, p.max_tables >= 1000 ? 'Sınırsız masa' : `${p.max_tables} masaya kadar`), h('li', null, p.max_staff >= 200 ? 'Sınırsız personel' : `${p.max_staff} personel`), h('li', null, 'Tüm özellikler')));
    };
    const lifetimeCard = () => h('section', { class: 'card plan' + (owned ? ' cur' : '') },
      h('div', { class: 'card-h' }, h('h2', null, b.lifetime.name + ' · Ömür boyu'), owned ? h('span', { class: 'tag ok' }, 'Mevcut') : null),
      h('p', { class: 'plan-price mono' }, fmt(b.lifetime.price), h('small', null, ' tek seferlik')),
      h('p', { class: 'muted sm' }, 'Aylık ya da yıllık ücret yoktur; bir kez ödenir.'),
      h('ul', { class: 'plan-f' }, h('li', null, 'Sınırsız masa'), h('li', null, 'Sınırsız personel'), h('li', null, 'Tüm özellikler ve gelecekteki güncellemeler')),
      owned ? null : h('p', { class: 'muted sm' }, 'Ödeme onaylanınca hesabınız ömür boyu olarak açılır.'));

    add(main,
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Paketiniz'), h('span', { class: 'tag ' + stCls }, stLabel)),
        h('dl', { class: 'dl' },
          h('dt', null, 'Paket'), h('dd', null, owned ? `${b.lifetime.name} · Ömür boyu` : `${s.plan_name} · ${PERIOD[s.period] || s.period}`),
          h('dt', null, s.state === 'trial' ? 'Deneme bitişi' : 'Bitiş'), h('dd', null, owned ? 'Süresiz' : dateTime(s.until)),
          h('dt', null, 'Kalan'), h('dd', null, owned ? 'Ömür boyu üyelik' : s.state === 'expired' ? 'Süresi doldu' : `${s.days_left} gün`),
          h('dt', null, 'İşletme kodu'), h('dd', { class: 'mono' }, b.reference, ' ', h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: () => copy(b.reference, 'İşletme kodu kopyalandı.') }, icon('copy', 14), 'Kopyala'))),
        s.state === 'expired' ? h('div', { class: 'banner bad' }, icon('alert', 18), h('span', null, 'Kayıtlarınız silinmedi. Ödemeniz onaylanınca paneliniz kaldığı yerden açılır.')) : null),
      owned ? null : h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Paket seçin')),
        seg([['aylik', 'Aylık'], ['yillik', 'Yıllık'], ['omur', 'Ömür boyu']], view, (v) => { view = v; draw(); }, 'billing-view')),
      plansBox,
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Nasıl ödenir?')),
        b.payment_info ? h('div', { class: 'pay-info' }, h('pre', { class: 'mono' }, b.payment_info), h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: () => copy(b.payment_info, 'Ödeme bilgileri kopyalandı.') }, icon('copy', 14), 'Kopyala')) : null,
        h('p', { class: 'muted' }, `Ödeme açıklamasına işletme adınızı ve işletme kodunuzu (${b.reference}) yazın. Ödemeniz kontrol edilince paketiniz aynı gün aktif edilir.`),
        h('div', { class: 'row-wrap' },
          wa ? h('a', { class: 'btn btn-primary', href: `https://wa.me/${wa}?text=${waText}`, target: '_blank', rel: 'noopener noreferrer' }, 'WhatsApp ile yazın') : null,
          b.support_email ? h('a', { class: 'btn btn-ghost', href: `mailto:${b.support_email}?subject=${encodeURIComponent('DNL Station paket - ' + b.reference)}` }, 'E-posta gönderin') : null,
          h('a', { class: 'btn btn-ghost', href: 'https://www.instagram.com/dnlstation/', target: '_blank', rel: 'noopener noreferrer' }, 'Instagram'))));
    draw();
  })();
}
