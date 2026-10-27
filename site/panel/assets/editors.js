// Ortak düzenleme pencereleri: masa türü/ücret, masa, ürün, stok.
// Ayarlar, Ürünler ve Masalar ekranları aynı pencereleri kullanır (tek kaynak).
import { h, clear, fill, add, icon, get, post, put, del, can, toast, fail, modal, confirmBox, money, busy, field, inp, sel, moneyInp, toggle, showErrors, inputToCents, moneyStr, KIND_LABEL } from './core.js?v=1';
import { invalidateMenu } from './v-cafe.js?v=1';

function money2(v) { const c = inputToCents(v); return c === null ? null : (Number.isFinite(c) ? moneyStr(c) : String(v)); }

/** S: { d: /catalog yanıtı, set(yeniYanıt) } */
export function catalogEditors(S) {
  function editType(t) {
    const d = S.d, set = S.set;
    const f = h('form', { class: 'form', novalidate: true },
      h('div', { class: 'grid2' }, field('Tür adı', inp('name', { maxlength: 40, value: t ? t.name : '', placeholder: 'ör. PS5 VIP' })),
        field('Kategori', sel('kind', Object.entries(KIND_LABEL), t ? t.kind : 'playstation'))),
      toggle('uses_controllers', 'Kol sayısına göre ücret (2 / 4 kol)', t ? t.uses_controllers : true),
      h('div', { class: 'grid2' }, field('Saatlik ücret ₺', moneyInp('hour_price', t ? t.hour_price : null)), h('div', { class: 'c4' }, field('Saatlik ücret (4 kol) ₺', moneyInp('hour_price_4', t ? t.hour_price_4 : null), 'Boş bırakılırsa 2 kol fiyatı uygulanır.'))),
      h('div', { class: 'grid2' }, field('Maç başı ücret ₺', moneyInp('match_price', t ? t.match_price : null, { placeholder: 'Yok' })), h('div', { class: 'c4' }, field('Maç başı (4 kol) ₺', moneyInp('match_price_4', t ? t.match_price_4 : null, { placeholder: 'Yok' })))),
      field('Minimum ücretlendirme (dk)', inp('min_minutes', { inputmode: 'numeric', class: 'inp mono', value: t ? String(t.min_minutes) : '0' }), 'Açık süreli oturumlarda en az bu kadar dakika ücretlendirilir. 0 = yok.'),
      toggle('night_enabled', 'Gece tarifesi', t ? t.night_enabled : false),
      h('div', { class: 'night' },
        h('div', { class: 'grid2' }, field('Başlangıç', inp('night_start', { type: 'time', class: 'inp mono', value: t ? t.night_start : '00:00' })), field('Bitiş', inp('night_end', { type: 'time', class: 'inp mono', value: t ? t.night_end : '06:00' }))),
        h('div', { class: 'grid2' }, field('Gece saatlik ₺', moneyInp('night_hour_price', t ? t.night_hour_price : null)), h('div', { class: 'c4' }, field('Gece saatlik (4 kol) ₺', moneyInp('night_hour_price_4', t ? t.night_hour_price_4 : null))))),
      t ? toggle('is_active', 'Aktif', t.is_active) : null,
      h('p', { class: 'hint' }, 'Ücret değişiklikleri yeni açılan masalara uygulanır; açık masalar açıldıkları ücretle devam eder.'));
    const q = (n) => f.querySelector(`[name="${n}"]`);
    const sync = () => {
      f.querySelectorAll('.c4').forEach((x) => { x.hidden = !q('uses_controllers').checked; });
      f.querySelector('.night').hidden = !q('night_enabled').checked;
    };
    q('uses_controllers').addEventListener('change', sync); q('night_enabled').addEventListener('change', sync); sync();
    const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Kaydet');
    const acts = [t ? h('button', { class: 'btn btn-ghost danger-t', type: 'button', onclick: async () => {
      if (!await confirmBox('Türü sil', `“${t.name}” silinsin mi? Bu türe bağlı masa varsa silinemez.`, 'Sil', true)) return;
      try { set(await del('/catalog/types/' + t.id)); m.close(); toast('Tür silindi.', 'good'); } catch (e) { fail(e); }
    } }, 'Sil') : null, h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), save];
    const m = modal({ title: t ? 'Masa türünü düzenle' : 'Yeni masa türü', body: f, actions: acts, wide: true });
    const submit = (ev) => {
      ev && ev.preventDefault();
      busy(save, async () => {
        const v = (n) => q(n) ? q(n).value : '';
        const body = {
          name: v('name'), kind: v('kind'), uses_controllers: q('uses_controllers').checked,
          hour_price: money2(v('hour_price')) ?? '', hour_price_4: money2(v('hour_price_4')), match_price: money2(v('match_price')), match_price_4: money2(v('match_price_4')),
          min_minutes: parseInt(v('min_minutes') || '0', 10) || 0, night_enabled: q('night_enabled').checked, night_start: v('night_start') || '00:00', night_end: v('night_end') || '06:00',
          night_hour_price: money2(v('night_hour_price')), night_hour_price_4: money2(v('night_hour_price_4')), is_active: t ? q('is_active').checked : true, sort: t ? t.sort : 0,
        };
        try { set(t ? await put('/catalog/types/' + t.id, body) : await post('/catalog/types', body)); m.close(); toast('Kaydedildi.', 'good'); }
        catch (e) { if (!showErrors(f, e)) fail(e); }
      });
    };
    f.addEventListener('submit', submit); save.onclick = submit;
  }
  function addTables(typeOpts) {
    const d = S.d, set = S.set;
    const f = h('form', { class: 'form', novalidate: true },
      field('Masa türü', sel('table_type_id', typeOpts, typeOpts[0][0])),
      h('div', { class: 'grid2' }, field('Ad öneki', inp('prefix', { maxlength: 30, value: 'Masa' }), 'ör. “PS” → PS 5, PS 6'), field('Adet', inp('count', { inputmode: 'numeric', class: 'inp mono', value: '1' }))));
    const tsel = f.querySelector('[name=table_type_id]');
    const pre = f.querySelector('[name=prefix]');
    const syncPre = () => { const t = d.types.find((x) => String(x.id) === tsel.value); if (t) pre.value = t.kind === 'playstation' ? 'PS' : t.name; };
    tsel.addEventListener('change', syncPre); syncPre();
    const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Ekle');
    const m = modal({ title: 'Masa ekle', body: f, actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), save] });
    const submit = (ev) => { ev && ev.preventDefault(); busy(save, async () => {
      try { set(await post('/catalog/tables', { table_type_id: parseInt(tsel.value, 10), prefix: pre.value, count: parseInt(f.querySelector('[name=count]').value, 10) || 0 })); m.close(); toast('Masalar eklendi.', 'good'); }
      catch (e) { if (!showErrors(f, e)) fail(e); }
    }); };
    f.addEventListener('submit', submit); save.onclick = submit;
  }
  function editTable(t, typeOpts) {
    const d = S.d, set = S.set;
    const f = h('form', { class: 'form', novalidate: true },
      field('Masa adı', inp('name', { maxlength: 40, value: t.name })),
      h('div', { class: 'grid2' }, field('Tür', sel('table_type_id', typeOpts, t.table_type_id)), field('Sıra', inp('sort', { inputmode: 'numeric', class: 'inp mono', value: String(t.sort) }))),
      toggle('is_active', 'Aktif (masalar ekranında görünür)', t.is_active));
    const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Kaydet');
    const m = modal({ title: 'Masayı düzenle', body: f, actions: [
      h('button', { class: 'btn btn-ghost danger-t', type: 'button', onclick: async () => {
        if (!await confirmBox('Masayı sil', `“${t.name}” silinsin mi? Geçmiş kaydı olan masalar raporlar korunsun diye pasife alınır.`, 'Sil', true)) return;
        try { set(await del('/catalog/tables/' + t.id)); m.close(); toast('Masa kaldırıldı.', 'good'); } catch (e) { fail(e); }
      } }, 'Sil'),
      h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), save] });
    const submit = (ev) => { ev && ev.preventDefault(); busy(save, async () => {
      const q = (n) => f.querySelector(`[name="${n}"]`);
      try { set(await put('/catalog/tables/' + t.id, { name: q('name').value, table_type_id: parseInt(q('table_type_id').value, 10), sort: parseInt(q('sort').value, 10) || 0, is_active: q('is_active').checked })); m.close(); toast('Kaydedildi.', 'good'); }
      catch (e) { if (!showErrors(f, e)) fail(e); }
    }); };
    f.addEventListener('submit', submit); save.onclick = submit;
  }
  return { editType, addTables, editTable };
}

/** S: { products: ürün listesi, set(yeniYanıt) } */
export function productEditors(S) {
  function edit(p) {
    const products = S.products, set = S.set;
    const f = h('form', { class: 'form', novalidate: true },
      field('Ürün adı', inp('name', { maxlength: 60, value: p ? p.name : '', required: true, autofocus: true })),
      h('div', { class: 'grid2' },
        field('Kategori', inp('category', { maxlength: 40, value: p ? p.category : '', placeholder: 'ör. İçecek', list: 'cat-list' })),
        field('Fiyat (₺)', moneyInp('price', p ? p.price_cents : null))),
      h('datalist', { id: 'cat-list' }, Array.from(new Set(products.map((x) => x.category))).map((c) => h('option', { value: c }))),
      toggle('track_stock', 'Stok takibi yap', p ? p.track_stock : false),
      h('div', { class: 'grid2 stock-fields' },
        !p ? field('Başlangıç stoğu', inp('initial_stock', { inputmode: 'numeric', class: 'inp mono', value: '0' })) : null,
        field('Kritik stok uyarısı', inp('low_stock', { inputmode: 'numeric', class: 'inp mono', value: p ? String(p.low_stock) : '0' }), 'Bu adetin altına düşünce uyarı verilir.')),
      p ? toggle('is_active', 'Satışta', p.is_active) : null);
    const sf = f.querySelector('.stock-fields'); const ts = f.querySelector('[name=track_stock]');
    const sync = () => { sf.hidden = !ts.checked; }; ts.addEventListener('change', sync); sync();
    const save = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Kaydet');
    const acts = [p && p.is_active ? h('button', { class: 'btn btn-ghost danger-t', type: 'button', onclick: async () => {
      if (!await confirmBox('Satıştan kaldır', `“${p.name}” satıştan kaldırılsın mı? Geçmiş satış kayıtları korunur.`, 'Kaldır', true)) return;
      try { set(await del('/products/' + p.id)); m.close(); toast('Ürün satıştan kaldırıldı.', 'good'); } catch (e) { fail(e); }
    } }, 'Satıştan kaldır') : null, h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), save];
    const m = modal({ title: p ? 'Ürünü düzenle' : 'Yeni ürün', body: f, actions: acts });
    const submit = (ev) => {
      ev && ev.preventDefault();
      busy(save, async () => {
        const fd = new FormData(f);
        const price = inputToCents(fd.get('price'));
        const body = {
          name: fd.get('name'), category: fd.get('category'), price: Number.isFinite(price) && price !== null ? moneyStr(price) : String(fd.get('price') || ''),
          track_stock: ts.checked, low_stock: parseInt(fd.get('low_stock') || '0', 10) || 0, is_active: p ? f.querySelector('[name=is_active]').checked : true,
        };
        if (!p) body.initial_stock = parseInt(fd.get('initial_stock') || '0', 10) || 0;
        try { set(p ? await put('/products/' + p.id, body) : await post('/products', body)); m.close(); toast(p ? 'Ürün güncellendi.' : 'Ürün eklendi.', 'good'); }
        catch (e) { if (!showErrors(f, e)) fail(e); }
      });
    };
    f.addEventListener('submit', submit); save.onclick = submit;
  }

  function stock(p) {
    const products = S.products, set = S.set;
    let mode = 'in';
    const qty = inp('qty', { inputmode: 'numeric', class: 'inp mono', placeholder: 'Adet', autofocus: true });
    const lbl = h('p', { class: 'hint' });
    const paint = () => { lbl.textContent = mode === 'in' ? `Mevcut ${p.stock_qty} adete eklenecek.` : `Sayımda bulduğunuz adedi girin (şu an sistemde ${p.stock_qty}).`; };
    const tabs = h('div', { class: 'seg' },
      h('button', { type: 'button', class: 'on', onclick: (e) => { mode = 'in'; tabs.querySelectorAll('button').forEach((b) => b.classList.remove('on')); e.currentTarget.classList.add('on'); paint(); } }, 'Stok girişi'),
      h('button', { type: 'button', onclick: (e) => { mode = 'count'; tabs.querySelectorAll('button').forEach((b) => b.classList.remove('on')); e.currentTarget.classList.add('on'); paint(); } }, 'Sayım'));
    paint();
    const ok = h('button', { class: 'btn btn-primary', type: 'button' }, 'Kaydet');
    const m = modal({ title: p.name + ' · Stok', body: h('div', { class: 'form' }, tabs, field('Adet', qty), lbl), actions: [h('button', { class: 'btn btn-ghost', type: 'button', onclick: () => m.close() }, 'Vazgeç'), ok] });
    ok.onclick = () => busy(ok, async () => {
      const n = parseInt(qty.value, 10);
      if (!(n >= (mode === 'in' ? 1 : 0))) { toast('Geçerli bir adet girin.', 'bad'); return; }
      try { set(await post(`/products/${p.id}/${mode === 'in' ? 'restock' : 'count'}`, { qty: n })); m.close(); toast('Stok güncellendi.', 'good'); } catch (e) { fail(e); }
    });
  }

  return { edit, stock };
}
