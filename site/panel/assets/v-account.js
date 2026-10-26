// Hesabım: profil, şifre değiştirme (zorunlu değişim dahil), e-posta doğrulama, paket
import { state, h, icon, post, toast, fail, busy, field, showErrors, ROLE_LABEL, dateTime, add } from './core.js?v=1';

function pw(name, label, ac) {
  const i = h('input', { class: 'inp', type: 'password', name, autocomplete: ac, maxlength: 128 });
  const b = h('button', { type: 'button', class: 'pw-eye', 'aria-label': 'Şifreyi göster', onclick: () => { i.type = i.type === 'password' ? 'text' : 'password'; } }, icon('eye', 18));
  const f = field(label, i);
  const slot = h('div', { class: 'pw' });
  f.insertBefore(slot, i);
  slot.append(i, b);
  return f;
}

export function accountView(ctx) {
  const { main } = ctx;
  const me = state.me;
  const forced = me.user.must_change_password;
  const f = h('form', { class: 'form narrow', novalidate: true },
    pw('current_password', forced ? 'Geçici şifre' : 'Mevcut şifre', 'current-password'),
    pw('password', 'Yeni şifre', 'new-password'),
    pw('password_confirm', 'Yeni şifre tekrar', 'new-password'),
    h('p', { class: 'hint' }, 'En az 8 karakter; harf, rakam ve sembol karıştırın.'));
  const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Şifremi güncelle');
  add(f, save);
  f.addEventListener('submit', (ev) => {
    ev.preventDefault();
    busy(save, async () => {
      const fd = new FormData(f);
      try {
        const r = await post('/account/password', { current_password: fd.get('current_password'), password: fd.get('password'), password_confirm: fd.get('password_confirm') });
        state.csrf = r.csrf; f.reset(); showErrors(f, null);
        toast(r.message, 'good', 6000);
        if (forced) { me.user.must_change_password = false; location.hash = '#/masalar'; location.reload(); }
      } catch (e) { if (!showErrors(f, e)) fail(e); }
    });
  });
  const sub = me.subscription;
  add(main, 
    forced ? h('div', { class: 'banner warn' }, icon('alert', 18), h('span', null, 'Güvenliğiniz için size verilen geçici şifreyi değiştirmeniz gerekiyor. Ardından panele erişebilirsiniz.')) : null,
    h('div', { class: 'grid2' },
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Profil')),
        h('dl', { class: 'dl' },
          h('dt', null, 'Ad soyad'), h('dd', null, me.user.name),
          h('dt', null, 'E-posta'), h('dd', null, me.user.email, ' ', me.user.verified ? h('span', { class: 'tag ok' }, 'Doğrulandı') : h('span', { class: 'tag warn' }, 'Doğrulanmadı')),
          h('dt', null, 'Rol'), h('dd', null, ROLE_LABEL[me.user.role] || me.user.role),
          h('dt', null, 'İşletme'), h('dd', null, me.business.name),
          me.user.role === 'owner' ? [h('dt', null, 'Paket'), h('dd', null, sub.plan_name + ' · ' + (sub.state === 'trial' ? `Deneme (${sub.days_left} gün kaldı)` : sub.state === 'active' ? 'Aktif · ' + dateTime(sub.until) + ' tarihine kadar' : 'Süresi doldu'))] : null),
        !me.user.verified ? h('button', { class: 'btn btn-ghost sm', type: 'button', onclick: (ev) => busy(ev.currentTarget, async () => {
          try { const r = await post('/auth/resend-verification', {}); toast(r.message || 'Doğrulama bağlantısı gönderildi.', 'good'); } catch (e) { fail(e); }
        }) }, 'Doğrulama e-postasını tekrar gönder') : null),
      h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Şifre değiştir')), f)));
}
