// DNL Station panel — çekirdek yardımcılar (API, DOM, biçimlendirme, modal, bildirim)
// Güvenlik: DOM her zaman h() ile kurulur; kullanıcı verisi yalnızca textContent olarak basılır (innerHTML yok).

export const state = { me: null, csrf: null };

/* ---------- DOM ---------- */
export function h(tag, attrs, ...kids) {
  const el = document.createElement(tag);
  if (attrs) {
    for (const [k, v] of Object.entries(attrs)) {
      if (v === false || v === null || v === undefined) continue;
      if (k === 'class') el.className = v;
      else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (k === 'dataset') Object.assign(el.dataset, v);
      else if (k === 'value') el.value = v;
      else if (k === 'checked') el.checked = !!v;
      else if (k === 'html') throw new Error('html özelliği kullanılamaz');
      else el.setAttribute(k, v === true ? '' : String(v));
    }
  }
  append(el, kids);
  return el;
}
function append(el, kids) {
  for (const k of kids) {
    if (k === null || k === undefined || k === false) continue;
    if (Array.isArray(k)) append(el, k);
    else el.append(k instanceof Node ? k : document.createTextNode(String(k)));
  }
}
export const $ = (s, r = document) => r.querySelector(s);
export const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
export function clear(el) { while (el.firstChild) el.firstChild.remove(); return el; }
/** İçeriği değiştirir (null/false çocuklar atlanır; yerel append "null" yazardı) */
export function fill(el, ...kids) { clear(el); append(el, kids); return el; }
export function add(el, ...kids) { append(el, kids); return el; }

// İkonlar: sabit SVG yolları (kullanıcı verisi içermez)
const ICONS = {
  grid: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
  cup: 'M17 8h1a4 4 0 0 1 0 8h-1M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4zM6 2v3M10 2v3M14 2v3',
  box: 'M21 8 12 3 3 8v8l9 5 9-5zM3 8l9 5 9-5M12 13v8',
  wallet: 'M20 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h15v13H5a2 2 0 0 1-2-2V5M16 13h.01',
  chart: 'M3 3v18h18M7 15l4-4 3 3 5-6',
  gear: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
  user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  more: 'M12 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM19 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM5 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2z',
  x: 'M18 6 6 18M6 6l12 12',
  plus: 'M12 5v14M5 12h14',
  minus: 'M5 12h14',
  clock: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
  pad: 'M6 11h4M8 9v4M15 12h.01M18 10h.01M17.3 5H6.7a4 4 0 0 0-4 3.6L2 15a3 3 0 0 0 5.2 2.1L9 15h6l1.8 2.1A3 3 0 0 0 22 15l-.7-6.4A4 4 0 0 0 17.3 5z',
  move: 'M5 9l-3 3 3 3M9 5l3-3 3 3M15 19l-3 3-3-3M19 9l3 3-3 3M2 12h20M12 2v20',
  trash: 'M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6',
  check: 'M20 6 9 17l-5-5',
  logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
  alert: 'M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  screen: 'M2 3h20v14H2zM8 21h8M12 17v4',
  download: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
  undo: 'M3 7v6h6M21 17a9 9 0 0 0-15-6.7L3 13',
  print: 'M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z',
  edit: 'M12 20h9M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4z',
  copy: 'M9 9h13v13H9zM5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
  refresh: 'M23 4v6h-6M1 20v-6h6M3.5 9a9 9 0 0 1 14.8-3.4L23 10M1 14l4.7 4.4A9 9 0 0 0 20.5 15',
  eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  ball: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
};
export function icon(name, size = 20) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('width', size); svg.setAttribute('height', size); svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '1.8');
  svg.setAttribute('stroke-linecap', 'round'); svg.setAttribute('stroke-linejoin', 'round'); svg.setAttribute('aria-hidden', 'true');
  const p = document.createElementNS(ns, 'path'); p.setAttribute('d', ICONS[name] || ICONS.grid); svg.append(p);
  return svg;
}

/* ---------- Biçimlendirme ---------- */
const tl = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export const money = (c) => '₺' + tl.format((c || 0) / 100);
export const money0 = (c) => '₺' + new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 }).format(Math.round((c || 0) / 100));
/** Kuruş -> giriş alanı metni ("12,50") */
export const centsToInput = (c) => (c === null || c === undefined) ? '' : tl.format(c / 100).replace(/\./g, '');
/** Giriş metni -> kuruş (geçersizse null) */
export function inputToCents(v) {
  const s = String(v ?? '').trim().replace(/\s/g, '').replace(/\.(?=\d{3}(\D|$))/g, '');
  if (s === '') return null;
  const m = s.match(/^(\d{1,7})(?:[.,](\d{1,2}))?$/);
  if (!m) return NaN;
  return parseInt(m[1], 10) * 100 + parseInt((m[2] || '0').padEnd(2, '0'), 10);
}
/** Sunucuya gönderilecek tutar metni ("12.50") */
export const moneyStr = (c) => (c / 100).toFixed(2);
export function dur(sec) {
  sec = Math.max(0, Math.floor(sec));
  const hh = Math.floor(sec / 3600), mm = Math.floor((sec % 3600) / 60), ss = sec % 60;
  return (hh ? hh + ':' + String(mm).padStart(2, '0') : String(mm)) + ':' + String(ss).padStart(2, '0');
}
export function durShort(sec) {
  sec = Math.max(0, Math.round(sec / 60));
  const hh = Math.floor(sec / 60), mm = sec % 60;
  return hh ? `${hh} sa ${mm} dk` : `${mm} dk`;
}
const dtf = new Intl.DateTimeFormat('tr-TR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Istanbul' });
const tf = new Intl.DateTimeFormat('tr-TR', { timeStyle: 'short', timeZone: 'Europe/Istanbul' });
export const dateTime = (iso) => iso ? dtf.format(new Date(iso)) : '—';
export const timeOnly = (iso) => iso ? tf.format(new Date(iso)) : '—';
export function localDate(d = new Date()) {
  const p = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Istanbul', year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
  return p; // YYYY-MM-DD
}
export const KIND_LABEL = { playstation: 'PlayStation', bilardo: 'Bilardo', okey: 'Okey', langirt: 'Langırt', 'masa-tenisi': 'Masa Tenisi', diger: 'Diğer' };
export const METHOD_LABEL = { cash: 'Nakit', card: 'Kart', other: 'Diğer' };
export const ROLE_LABEL = { owner: 'İşletme sahibi', manager: 'Müdür', staff: 'Personel' };

/* ---------- API ---------- */
export class ApiError extends Error {
  constructor(status, code, message, fields) { super(message); this.status = status; this.code = code; this.fields = fields || {}; }
}
let onAuthLost = () => {};
export function setAuthLost(fn) { onAuthLost = fn; }

export async function api(method, path, body) {
  const opts = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (method !== 'GET') {
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF-Token'] = state.csrf || '';
    opts.body = JSON.stringify(body || {});
  }
  let res;
  try {
    res = await fetch('/api' + path, opts);
  } catch {
    throw new ApiError(0, 'network', 'Bağlantı kurulamadı. İnternet bağlantınızı kontrol edin.');
  }
  let json = null;
  try { json = await res.json(); } catch { /* boş */ }
  if (res.ok && json && json.ok) return json.data;
  const e = json && json.error ? json.error : { code: 'server_error', message: 'Beklenmeyen bir hata oluştu.' };
  if (res.status === 401) onAuthLost('expired');
  if (res.status === 403 && e.code === 'must_change_password') onAuthLost('must_change');
  if (res.status === 403 && e.code === 'suspended') onAuthLost('suspended');
  if (res.status === 419) {
    // CSRF anahtarı yenilendi: bir kez yeniden dene
    if (!api._retry) {
      try {
        api._retry = true;
        const me = await fetch('/api/me', { credentials: 'same-origin' }).then((r) => r.json());
        if (me.ok) { state.csrf = me.data.csrf; return await api(method, path, body); }
      } finally { api._retry = false; }
    }
  }
  throw new ApiError(res.status, e.code, e.message, e.fields);
}
export const get = (p) => api('GET', p);
export const post = (p, b) => api('POST', p, b);
export const put = (p, b) => api('PUT', p, b);
export const del = (p) => api('DELETE', p);

export function can(perm) { return !!state.me && state.me.permissions.includes(perm); }

/* ---------- Bildirim ---------- */
let toastBox;
export function toast(msg, type = 'info', ms = 3800) {
  if (!toastBox) { toastBox = h('div', { class: 'toasts', role: 'status', 'aria-live': 'polite' }); document.body.append(toastBox); }
  const t = h('div', { class: 'toast ' + type }, icon(type === 'bad' ? 'alert' : type === 'good' ? 'check' : 'clock', 18), h('span', null, msg));
  while (toastBox.childElementCount >= 3) toastBox.firstChild.remove(); // ekranı kaplamasın
  toastBox.append(t);
  setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 300); }, ms);
}
export function fail(e) {
  if (e instanceof ApiError && (e.status === 401 || e.code === 'must_change_password')) return;
  toast(e && e.message ? e.message : 'İşlem tamamlanamadı.', 'bad', 5200);
}

/* ---------- Modal / sayfa altı panel ---------- */
let openModals = [];
export function modal({ title, body, actions, wide = false, onClose }) {
  const prev = document.activeElement;
  const close = () => {
    root.classList.add('out');
    document.removeEventListener('keydown', onKey);
    openModals = openModals.filter((m) => m !== root);
    if (!openModals.length) document.documentElement.classList.remove('modal-open');
    setTimeout(() => root.remove(), 180);
    if (prev && prev.focus) prev.focus();
    onClose && onClose();
  };
  const onKey = (ev) => { if (ev.key === 'Escape' && openModals[openModals.length - 1] === root) close(); };
  const head = h('div', { class: 'm-head' }, h('h2', { id: 'm-title-' + openModals.length }, title),
    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Kapat', onclick: close }, icon('x')));
  const content = h('div', { class: 'm-body' }, body);
  const foot = actions ? h('div', { class: 'm-foot' }, actions) : null;
  const box = h('div', { class: 'm-box' + (wide ? ' wide' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'm-title-' + openModals.length }, head, content, foot);
  const root = h('div', { class: 'modal' }, h('div', { class: 'm-back', onclick: close }), box);
  document.body.append(root);
  document.documentElement.classList.add('modal-open');
  openModals.push(root);
  document.addEventListener('keydown', onKey);
  requestAnimationFrame(() => {
    const f = box.querySelector('[autofocus]') || box.querySelector('input,select,textarea,button:not(.icon-btn)');
    if (f && window.matchMedia('(pointer:fine)').matches) f.focus();
  });
  return { root, box, body: content, close, setBody(n) { fill(content, n); }, setActions(a) { clear(foot || box.appendChild(h('div', { class: 'm-foot' }))).append(...[].concat(a)); } };
}
export function closeAllModals() { openModals.slice().forEach((m) => m.querySelector('.m-head .icon-btn').click()); }

export function confirmBox(title, text, okText = 'Onayla', danger = false) {
  return new Promise((resolve) => {
    let done = false;
    const m = modal({
      title, body: h('p', { class: 'muted' }, text),
      actions: [
        h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => { done = true; m.close(); resolve(false); } }, 'Vazgeç'),
        h('button', { class: 'btn ' + (danger ? 'btn-danger' : 'btn-primary'), type: 'button', onclick: () => { done = true; m.close(); resolve(true); } }, okText),
      ],
      onClose: () => { if (!done) resolve(false); },
    });
  });
}

/* ---------- Form yardımcıları ---------- */
export function field(label, input, hint) {
  const id = input.id || ('f' + Math.random().toString(36).slice(2, 8));
  input.id = id;
  return h('div', { class: 'field' }, h('label', { class: 'lbl', for: id }, label), input, h('p', { class: 'f-err', 'data-err': input.name || '' }), hint ? h('p', { class: 'hint' }, hint) : null);
}
export function inp(name, attrs = {}) { return h('input', Object.assign({ class: 'inp', name, autocomplete: 'off' }, attrs)); }
export function moneyInp(name, cents, attrs = {}) {
  return h('input', Object.assign({ class: 'inp mono', name, inputmode: 'decimal', autocomplete: 'off', placeholder: '0,00', value: centsToInput(cents) }, attrs));
}
export function sel(name, options, value) {
  return h('select', { class: 'inp', name }, options.map(([v, l]) => h('option', { value: v, selected: String(v) === String(value) }, l)));
}
export function toggle(name, label, checked) {
  return h('label', { class: 'switch' }, h('input', { type: 'checkbox', name, checked }), h('span', { class: 'sw' }), h('span', null, label));
}
export function showErrors(form, e) {
  $$('.f-err', form).forEach((p) => { p.textContent = ''; });
  $$('.inp.bad', form).forEach((i) => i.classList.remove('bad'));
  if (!(e instanceof ApiError) || !e.fields) return false;
  let any = false;
  for (const [k, msg] of Object.entries(e.fields)) {
    const p = form.querySelector(`.f-err[data-err="${CSS.escape(k)}"]`);
    const i = form.querySelector(`[name="${CSS.escape(k)}"]`);
    if (p) { p.textContent = msg; any = true; }
    if (i) i.classList.add('bad');
  }
  return any;
}
/** Bir düğmeyi işlem süresince kilitler (çift tıklama koruması) */
export async function busy(btn, fn) {
  if (btn.disabled) return;
  btn.disabled = true; btn.classList.add('loading');
  try { return await fn(); } finally { btn.disabled = false; btn.classList.remove('loading'); }
}
export function seg(options, value, onChange, name = '') {
  const box = h('div', { class: 'seg', role: 'radiogroup' });
  const render = (v) => {
    fill(box, ...options.map(([val, label]) => h('button', {
      type: 'button', role: 'radio', 'aria-checked': String(val === v), class: val === v ? 'on' : '', 'data-name': name,
      onclick: () => { render(val); onChange(val); },
    }, label)));
  };
  render(value);
  return box;
}
export function empty(text, action) {
  return h('div', { class: 'empty' }, h('p', null, text), action || null);
}

/* ---------- Ses uyarısı ---------- */
let actx;
export function beep(times = 3) {
  try {
    actx = actx || new (window.AudioContext || window.webkitAudioContext)();
    let t = actx.currentTime;
    for (let i = 0; i < times; i++) {
      const o = actx.createOscillator(); const g = actx.createGain();
      o.type = 'sine'; o.frequency.value = 880;
      g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.25, t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.25);
      o.connect(g).connect(actx.destination); o.start(t); o.stop(t + 0.3); t += 0.4;
    }
  } catch { /* ses desteklenmiyor */ }
}
export function unlockAudio() {
  try { actx = actx || new (window.AudioContext || window.webkitAudioContext)(); if (actx.state === 'suspended') actx.resume(); } catch { /* yok */ }
}
