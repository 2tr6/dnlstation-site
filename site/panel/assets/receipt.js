// Adisyon fişi (80 mm termal yazıcıya uygun)
import { h, money, dateTime, METHOD_LABEL } from './core.js?v=1';
import { printNode } from './v-shift.js?v=1';

export function receiptNode(t, business, pre = false) {
  const row = (l, v, cls) => h('div', { class: 'z-row ' + (cls || '') }, h('span', null, l), h('b', { class: 'mono' }, v));
  return h('div', { class: 'z receipt' },
    h('div', { class: 'z-head' }, h('b', null, business), h('span', null, pre ? 'Ara hesap' : 'Adisyon #' + t.id), h('small', null, t.table_name ? `${t.table_name} · ${dateTime(t.started_at)}${t.ended_at ? ' → ' + dateTime(t.ended_at) : ''}` : dateTime(t.closed_at))),
    h('hr'),
    t.game_cents ? row('Oyun ücreti', money(t.game_cents)) : null,
    ...(t.items || []).map((i) => row(`${i.qty} × ${i.name}`, money(i.total_cents))),
    h('hr'),
    t.discount_cents ? row('İndirim', '−' + money(t.discount_cents)) : null,
    row(pre ? 'Şu anki tutar' : 'Toplam', money(pre ? t.subtotal_cents : t.total_cents), 'big'),
    ...(t.payments || []).map((p) => row((METHOD_LABEL[p.method] || p.method) + (p.customer ? ' · ' + p.customer : ''), money(p.amount_cents))),
    h('hr'),
    h('p', { class: 'muted sm', style: { textAlign: 'center' } }, pre ? 'Bu belge ara hesap bilgisidir.' : 'Teşekkür ederiz, yine bekleriz!'),
    h('p', { class: 'muted sm', style: { textAlign: 'center' } }, 'DNL Station'));
}
export function printReceipt(t, business, pre) { printNode(receiptNode(t, business, pre)); }
