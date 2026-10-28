/* beta.6: avisos con emojis (base utf8), paginación, cortesía, cambio de fecha por la clienta. */
const { chromium } = require('playwright');
const BASE = 'http://localhost:8089';
const SHOTS = process.env.SHOTS || '.';
const PORTAL = process.env.PORTAL;
const COURTESY = process.env.COURTESY;
const FONTS = 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Cormorant+Garamond:wght@400;500;600&display=swap';
let failures = 0;
const ok = (c, l, x) => { console.log((c ? 'PASA ' : 'FALLA') + ' · ' + l + (x ? ' · ' + x : '')); if (!c) failures++; };
async function look(page) {
  await page.addStyleTag({ url: FONTS }).catch(() => {});
  await page.addStyleTag({ content: 'body{background:#FAF7F2 !important} .is-layout-constrained > .mo-booking, .entry-content > .mo-booking, .wp-block-post-content > *{max-width:1120px !important}' });
  await page.evaluate(() => document.fonts.ready);
}
(async () => {
  const browser = await chromium.launch();
  const admin = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  admin.on('dialog', d => d.accept());
  await admin.goto(BASE + '/wp-login.php');
  await admin.fill('#user_login', 'monica'); await admin.fill('#user_pass', 'clave-de-prueba-123');
  await Promise.all([admin.waitForNavigation(), admin.click('#wp-submit')]);

  // 1. Avisos con emojis en una base de datos sin utf8mb4.
  await admin.goto(BASE + '/wp-admin/admin.php?page=mo-booking-notifications');
  const det = admin.locator('details.mo-template', { hasText: 'Cita confirmada' }).first();
  await det.locator('summary').click();
  await admin.fill('input[name="tpl[booking_confirmed][subject]"]', '¡Tu cita está confirmada! 🎄 ({{referencia}})');
  await admin.fill('textarea[name="tpl[booking_confirmed][whatsapp]"]', '¡Hola *{{nombre}}*! 📸\nTu sesión está confirmada ✨');
  await Promise.all([admin.waitForNavigation(), admin.click('button:has-text("Guardar avisos del sistema")')]);
  const notice = (await admin.locator('.notice').allInnerTexts()).join(' ');
  await admin.locator('details.mo-template', { hasText: 'Cita confirmada' }).first().locator('summary').click();
  const subj = await admin.inputValue('input[name="tpl[booking_confirmed][subject]"]');
  const wa = await admin.inputValue('textarea[name="tpl[booking_confirmed][whatsapp]"]');
  ok(/🎄/.test(subj) && /📸/.test(wa) && /✨/.test(wa), 'Avisos con emojis se guardan (tabla utf8)', subj + ' | ' + wa.replace(/\n/g, '⏎') + ' | ' + notice.slice(0, 60));
  const pag = await admin.locator('.mo-pagination').count();
  ok(pag === 1, 'Registro de mensajes paginado', await admin.locator('.mo-pagination .displaying-num').innerText().catch(() => ''));
  await admin.locator('.mo-pagination a.page-numbers', { hasText: '2' }).first().click();
  await admin.waitForLoadState('load');
  ok(/pagina=2/.test(admin.url()), 'Página 2 del registro', admin.url());

  // 2. Ajustes: activar el cambio de fecha (con emoji en otro texto para probar el guardado).
  await admin.goto(BASE + '/wp-admin/admin.php?page=mo-booking-settings');
  const notice0 = await admin.inputValue('textarea[name=contract_notice]');
  await admin.check('input[name=self_reschedule_enabled]');
  await admin.fill('textarea[name=contract_notice]', 'Lee con calma tu contrato 💛');
  await Promise.all([admin.waitForNavigation(), admin.click('form button[type=submit].button-primary >> nth=0')]);
  ok(await admin.isChecked('input[name=self_reschedule_enabled]') && /💛/.test(await admin.inputValue('textarea[name=contract_notice]')), 'Ajustes guardados con emoji y cambio de fecha activo');
  await admin.locator('.mo-card:has-text("Cambio de fecha por la clienta")').screenshot({ path: SHOTS + '/b6-ajustes-cambio-fecha.png' });
  // Se regresa el texto sin emoji (el emoji hace que WordPress cargue su imagen de internet).
  await admin.fill('textarea[name=contract_notice]', notice0);
  await Promise.all([admin.waitForNavigation(), admin.click('form button[type=submit].button-primary >> nth=0')]);

  // 3. Cortesía.
  await admin.goto(BASE + '/wp-admin/admin.php?page=mo-booking-bookings&booking=' + COURTESY);
  await admin.locator('summary:has-text("Marcar como cortesía")').click();
  await admin.fill('form:has(button:has-text("Marcar como cortesía")) input[name=reason]', 'Mi tía');
  await Promise.all([admin.waitForNavigation(), admin.click('button:has-text("Marcar como cortesía")')]);
  const status = await admin.locator('.mo-detail-status').innerText();
  ok(/Confirmada/.test(status) && /Cortesía/.test(status), 'Cortesía: confirmada con etiqueta', status.replace(/\s+/g, ' '));
  await admin.locator('.mo-preview:has-text("Sin costo")').first().screenshot({ path: SHOTS + '/b6-cortesia.png' });
  await admin.goto(BASE + '/wp-admin/admin.php?page=mo-booking-bookings');
  ok(await admin.locator('.mo-pill--courtesy').count() > 0, 'Etiqueta Cortesía en la lista');

  // 4. Cambio de fecha por la clienta (computadora y celular).
  for (const [name, vp] of [['pc', { width: 1280, height: 900 }], ['cel', { width: 390, height: 844 }]]) {
    const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: vp.width < 500 ? 2 : 1 });
    const p = await ctx.newPage();
    const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.goto(PORTAL);
    await p.waitForSelector('.mo-booking__hero');
    await look(p);
    if (name === 'cel') {
      // El celular solo mira (el cambio ya se hizo en computadora).
      await p.locator('.mo-booking__reschedule').screenshot({ path: SHOTS + '/b6-cel-ya-usado.png' });
      ok(/Ya usaste tu cambio de fecha/.test(await p.locator('.mo-booking__reschedule').innerText()), 'Después del cambio: aviso fijo en lugar del botón');
      ok(errs.length === 0, 'Sin errores de JavaScript (celular)', errs.join(' '));
      await ctx.close();
      continue;
    }
    await p.locator('.mo-booking__reschedule').screenshot({ path: SHOTS + '/b6-' + name + '-boton.png' });
    await p.click('[data-action=reschedule-open]');
    await p.waitForSelector('.mo-booking__modal');
    await p.waitForTimeout(200);
    await p.screenshot({ path: SHOTS + '/b6-' + name + '-politica.png' });
    const pol = await p.locator('.mo-booking__modal').innerText();
    ok(/UNA VEZ/.test(pol) && /36 horas/.test(pol), 'Ventana con la política');
    await p.click('[data-rs=ok]');
    await p.waitForSelector('.mo-booking__day--available');
    // Fecha de diciembre (más cara) para ver la diferencia.
    for (let i = 0; i < 3; i++) {
      if (/diciembre/i.test(await p.locator('.mo-booking__month-name').innerText())) break;
      await p.click('[data-rs=next]'); await p.waitForTimeout(600);
    }
    await p.locator('.mo-booking__day--available').nth(4).click();
    await p.locator('.mo-booking__slot:not([disabled])').first().click();
    await p.waitForSelector('.mo-booking__rs-preview, .mo-booking__alert', { timeout: 15000 });
    await look(p);
    await p.locator('.mo-booking').first().screenshot({ path: SHOTS + '/b6-' + name + '-nueva-fecha.png' });
    const prev = await p.locator('.mo-booking__rs-preview').innerText().catch(() => '');
    ok(/Nuevo total/.test(prev), 'Vista previa con nuevo total', prev.replace(/\s+/g, ' ').slice(0, 160));
    const accept = p.locator('[data-rs=accept]');
    if (await accept.count()) {
      await p.click('[data-rs=confirm]');
      ok(/acepta la diferencia/.test(await p.locator('[data-rs-error]').innerText()), 'Pide aceptar la diferencia');
      await accept.check();
    }
    await p.click('[data-rs=confirm]');
    await p.waitForSelector('.mo-booking__hero', { timeout: 20000 });
    await look(p);
    const msg = await p.locator('.mo-booking__live').innerText();
    ok(/cambió de fecha/.test(msg), 'Mensaje de cambio listo', msg);
    await p.locator('.mo-booking').first().screenshot({ path: SHOTS + '/b6-' + name + '-despues.png' });
    ok(errs.length === 0, 'Sin errores de JavaScript', errs.join(' '));
    await ctx.close();
  }
  await browser.close();
  console.log(failures ? failures + ' verificaciones fallaron' : 'Todas las verificaciones pasaron');
  process.exit(failures ? 1 : 0);
})();
