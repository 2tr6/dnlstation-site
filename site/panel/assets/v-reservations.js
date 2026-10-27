// Rezervasyonlar: gün görünümü, hızlı ekleme, durum (geldi / gelmedi / iptal)
import { h, fill, add, icon, get, post, put, toast, fail, modal, confirmBox, busy, field, inp, sel, showErrors, empty, localDate, timeOnly } from './core.js?v=1';

const ST = { booked: ['Bekleniyor', 'warn'], arrived: ['Geldi', 'ok'], cancelled: ['İptal', ''], noshow: ['Gelmedi', 'bad'] };
const addDays = (ymd, n) => { const [y, m, d] = ymd.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10); };
const dayLabel = (ymd) => new Intl.DateTimeFormat('tr-TR', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(ymd + 'T12:00:00Z'));
const localTime = (iso) => new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Istanbul' }).format(new Date(iso));

export async function reservationForm(r, tables, onSaved, presetTable) {
  const tOpts = [['', 'Masa seçilmedi'], ...tables.map((t) => [t.id, t.name])];
  const date = inp('date', { type: 'date', class: 'inp mono', value: r ? new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Istanbul' }).format(new Date(r.starts_at)) : localDate() });
  const time = inp('time', { type: 'time', class: 'inp mono', value: r ? localTime(r.starts_at) : '20:00' });
  const f = h('form', { class: 'form', novalidate: true },
    h('div', { class: 'grid2' }, field('Ad soyad', inp('customer_name', { maxlength: 80, value: r ? r.customer_name : '', autofocus: true })), field('Telefon', inp('phone', { inputmode: 'tel', value: r ? r.phone : '' }))),
    h('div', { class: 'grid2' }, field('Tarih', date), field('Saat', time)),
    h('div', { class: 'grid2' }, field('Masa', sel('table_id', tOpts, r ? (r.table_id ?? '') : (presetTable ?? ''))), field('Süre', sel('duration_minutes', [[30, '30 dk'], [60, '1 saat'], [90, '1,5 saat'], [120, '2 saat'], [180, '3 saat'], [240, '4 saat']], r ? r.duration_minutes : 60))),
    h('div', { class: 'grid2' }, field('Kişi', inp('people', { inputmode: 'numeric', class: 'inp mono', value: r && r.people ? String(r.people) : '' })), field('Not', inp('note', { maxlength: 200, value: r ? r.note : '', placeholder: 'ör. doğum günü' }))));
  const ok = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Kaydet');
  const m = modal({ title: r ? 'Rezervasyonu düzenle' : 'Yeni rezervasyon', body: f, actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), ok] });
  const run = (e) => { e && e.preventDefault(); busy(ok, async () => {
    const q = (n) => f.querySelector(`[name=${n}]`).value;
    const body = { customer_name: q('customer_name'), phone: q('phone'), date: q('date'), time: q('time'), table_id: q('table_id') ? parseInt(q('table_id'), 10) : null, duration_minutes: parseInt(q('duration_minutes'), 10), people: q('people') ? parseInt(q('people'), 10) : null, note: q('note') };
    try { r ? await put('/reservations/' + r.id, body) : await post('/reservations', body); m.close(); toast('Rezervasyon kaydedildi.', 'good'); onSaved && onSaved(body.date); } catch (err) { if (!showErrors(f, err)) fail(err); }
  }); };
  f.addEventListener('submit', run); ok.onclick = run;
}

export function reservationsView(ctx) {
  const { main } = ctx;
  let day = localDate(), tables = [];
  const title = h('h2', { class: 'day-title' });
  const list = h('div', { class: 'res-list' });
  add(main, h('div', { class: 'toolbar' }, h('div', { class: 'tb-l day-nav' },
    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Önceki gün', onclick: () => { day = addDays(day, -1); load(); } }, '‹'),
    h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: () => { day = localDate(); load(); } }, 'Bugün'),
    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Sonraki gün', onclick: () => { day = addDays(day, 1); load(); } }, '›'), title),
    h('div', { class: 'tb-r' }, h('button', { class: 'btn btn-primary', type: 'button', onclick: () => reservationForm(null, tables, (d) => { day = d; load(); }) }, icon('plus', 18), 'Rezervasyon ekle'))), list);
  async function load() {
    title.textContent = dayLabel(day) + (day === localDate() ? ' · Bugün' : '');
    try {
      const [r, dsh] = await Promise.all([get('/reservations?date=' + day), tables.length ? null : get('/dashboard')]);
      if (dsh) tables = dsh.tables;
      fill(list, r.items.length ? r.items.map((x) => {
        const [lbl, cls] = ST[x.status] || [x.status, ''];
        const setSt = (s) => async (ev) => busy(ev.currentTarget, async () => { try { await post(`/reservations/${x.id}/status`, { status: s }); load(); } catch (e) { fail(e); } });
        return h('article', { class: 'res card ' + x.status },
          h('div', { class: 'res-time mono' }, localTime(x.starts_at), h('small', null, x.duration_minutes + ' dk')),
          h('div', { class: 'res-main' }, h('b', null, x.customer_name), ' ', h('span', { class: 'tag ' + cls }, lbl),
            h('div', { class: 'muted sm' }, [x.table_name || 'Masa seçilmedi', x.people ? x.people + ' kişi' : null, x.phone || null, x.note || null].filter(Boolean).join(' · '))),
          h('div', { class: 'res-acts' },
            x.status === 'booked' ? [h('button', { class: 'btn btn-primary sm', type: 'button', onclick: setSt('arrived') }, 'Geldi'), h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: setSt('noshow') }, 'Gelmedi')] : null,
            x.phone ? h('a', { class: 'btn btn-ghost sm', href: 'tel:' + x.phone }, 'Ara') : null,
            h('button', { class: 'icon-btn sm', type: 'button', 'aria-label': 'Düzenle', onclick: () => reservationForm(x, tables, (d) => { day = d; load(); }) }, icon('edit', 16)),
            x.status === 'booked' ? h('button', { class: 'icon-btn sm', type: 'button', 'aria-label': 'İptal', onclick: async (ev) => { if (await confirmBox('Rezervasyonu iptal et', `${x.customer_name} · ${localTime(x.starts_at)} iptal edilsin mi?`, 'İptal et', true)) setSt('cancelled')(ev); } }, icon('x', 16)) : null));
      }) : [empty('Bu gün için rezervasyon yok.', h('button', { class: 'btn btn-primary', type: 'button', onclick: () => reservationForm(null, tables, (d) => { day = d; load(); }) }, 'Rezervasyon ekle'))]);
    } catch (e) { fail(e); }
  }
  load();
  void timeOnly;
}
