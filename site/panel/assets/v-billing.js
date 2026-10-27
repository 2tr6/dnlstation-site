// Paketim: abonelik durumu, paketler ve ödeme talimatı (yalnızca işletme sahibi)
import { h, fill, add, icon, get, toast, money, dateTime, empty } from './core.js?v=1';

const PERIOD = { aylik: 'Aylık', yillik: 'Yıllık' };
const STATE = { trial: ['Deneme sürümü', 'warn'], active: ['Aktif', 'ok'], expired: ['Süresi doldu', 'bad'], lifetime: ['Ömür boyu', 'ok'] };

export function billingView(ctx) {
  const { main } = ctx;
  (async () => {
    let b;
    try { b = await get('/billing'); } catch (e) { add(main, empty(e.message)); return; }
    const s = b.subscription;
    const [stLabel, stCls] = STATE[s.state] || [s.state, ''];
    const wa = b.whatsapp ? '90' + b.whatsapp.replace(/^0/, '') : '';
    const waText = encodeURIComponent(`Merhaba, DNL Station paketimi yenilemek istiyorum. İşletme kodum: ${b.reference}`);
    const copy = async (t, msg) => { try { await navigator.clipboard.writeText(t); toast(msg, 'good', 1500); } catch { toast('Kopyalanamadı.', 'bad'); } };
    add(main,
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Paketiniz'), h('span', { class: 'tag ' + stCls }, stLabel)),
        h('dl', { class: 'dl' },
          h('dt', null, 'Paket'), h('dd', null, `${s.plan_name} · ${PERIOD[s.period] || s.period}`),
          h('dt', null, s.state === 'trial' ? 'Deneme bitişi' : 'Bitiş'), h('dd', null, s.state === 'lifetime' ? 'Süresiz' : dateTime(s.until)),
          h('dt', null, 'Kalan'), h('dd', null, s.state === 'lifetime' ? 'Ömür boyu üyelik' : s.state === 'expired' ? 'Süresi doldu' : `${s.days_left} gün`),
          h('dt', null, 'İşletme kodu'), h('dd', { class: 'mono' }, b.reference, ' ', h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: () => copy(b.reference, 'İşletme kodu kopyalandı.') }, icon('copy', 14), 'Kopyala'))),
        s.state === 'expired' ? h('div', { class: 'banner bad' }, icon('alert', 18), h('span', null, 'Kayıtlarınız silinmedi. Ödemeniz onaylanınca paneliniz kaldığı yerden açılır.')) : null),
      h('div', { class: 'plans' }, b.plans.map((p) => h('section', { class: 'card plan' + (p.key === s.plan ? ' cur' : '') },
        h('div', { class: 'card-h' }, h('h2', null, p.name), p.key === s.plan ? h('span', { class: 'tag ok' }, 'Mevcut') : null),
        h('p', { class: 'plan-price mono' }, money(p.monthly).replace(',00', ''), h('small', null, ' /ay')),
        h('p', { class: 'muted sm' }, `Yıllık ${money(p.yearly).replace(',00', '')} (2 ay ücretsiz)`),
        h('ul', { class: 'plan-f' }, h('li', null, p.max_tables >= 1000 ? 'Sınırsız masa' : `${p.max_tables} masaya kadar`), h('li', null, p.max_staff >= 200 ? 'Sınırsız personel' : `${p.max_staff} personel`), h('li', null, 'Tüm özellikler'))))),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Nasıl ödenir?')),
        b.payment_info ? h('div', { class: 'pay-info' }, h('pre', { class: 'mono' }, b.payment_info), h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: () => copy(b.payment_info, 'Ödeme bilgileri kopyalandı.') }, icon('copy', 14), 'Kopyala')) : null,
        h('p', { class: 'muted' }, `Ödeme açıklamasına işletme kodunuzu (${b.reference}) yazın. Ödemeniz kontrol edilince paketiniz aynı gün aktif edilir.`),
        h('div', { class: 'row-wrap' },
          wa ? h('a', { class: 'btn btn-primary', href: `https://wa.me/${wa}?text=${waText}`, target: '_blank', rel: 'noopener noreferrer' }, 'WhatsApp ile yazın') : null,
          b.support_email ? h('a', { class: 'btn btn-ghost', href: `mailto:${b.support_email}?subject=${encodeURIComponent('DNL Station paket - ' + b.reference)}` }, 'E-posta gönderin') : null,
          h('a', { class: 'btn btn-ghost', href: 'https://www.instagram.com/dnlstation/', target: '_blank', rel: 'noopener noreferrer' }, 'Instagram'))));
  })();
}
