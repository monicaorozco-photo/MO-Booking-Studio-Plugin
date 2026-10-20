const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://localhost:8089';
const SHOTS = process.env.SHOTS;
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');
const log = (...a) => console.log('•', ...a);
async function adminLogin(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  page.on('dialog', d => d.accept());
  await page.goto(BASE + '/wp-login.php');
  await page.fill('#user_login', 'monica');
  await page.fill('#user_pass', 'clave-de-prueba-123');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  return page;
}
async function noHScroll(page, label) {
  const w = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  log(label, 'scrollWidth', w[0], 'viewport', w[1], w[0] <= w[1] ? 'OK sin desplazamiento horizontal' : 'FALLA: desplazamiento horizontal');
}
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const admin = await adminLogin(browser);

  await admin.goto(BASE + '/wp-admin/admin.php?page=mo-booking-settings');
  await Promise.all([admin.waitForNavigation(), admin.click('button:has-text("Verificar almacenamiento privado")')]);
  log('Verificación de almacenamiento:', (await admin.locator('.notice-success, .notice-error').first().innerText()).slice(0, 120));
  await Promise.all([admin.waitForNavigation(), admin.click('button:has-text("Crear datos de prueba")')]);
  log('Datos de prueba:', (await admin.locator('.notice-success').first().innerText()).slice(0, 100));
  await admin.fill('#mo-bank-instructions', 'Banco: BANCO DE PRUEBA\nTitular: Mónica Orozco\nCLABE: 000000000000000000');
  await Promise.all([admin.waitForNavigation(), admin.click('button:has-text("Guardar ajustes")')]);
  log('Ajustes:', (await admin.locator('.notice-success').first().innerText()).slice(0, 60));

  const url = process.env.BOOKING_URL;
  const mobile = await browser.newContext({ viewport: { width: 360, height: 780 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const m = await mobile.newPage();
  const consoleErrors = [];
  m.on('console', msg => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
  m.on('pageerror', e => consoleErrors.push(e.message));
  await m.goto(url);
  await m.waitForSelector('.mo-booking__day--available', { timeout: 15000 });
  log('Mes mostrado:', await m.locator('.mo-booking__month-name').innerText());
  await m.screenshot({ path: SHOTS + '/m1-calendario.png', fullPage: true });
  await noHScroll(m, 'Calendario');
  await m.click('.mo-booking__day--available >> nth=0');
  await m.waitForSelector('.mo-booking__slot');
  await m.screenshot({ path: SHOTS + '/m2-horarios.png', fullPage: true });
  const firstSlot = m.locator('.mo-booking__slot:not([disabled])').first();
  const slotTime = await firstSlot.innerText();
  await firstSlot.click();
  await m.click('[data-action=to-details]');
  await m.waitForSelector('[data-form=details]');
  await m.fill('#mo-f-participants', '8');
  await m.fill('#mo-f-pets', '1');
  await m.fill('#mo-f-name', 'Laura Prueba');
  await m.fill('#mo-f-email', 'laura@example.com');
  await m.fill('#mo-f-phone', '6561234567');
  await m.screenshot({ path: SHOTS + '/m3-datos.png', fullPage: true });
  await noHScroll(m, 'Datos');
  await m.click('[data-form=details] button[type=submit]');
  await m.waitForSelector('[data-form=summary]');
  const total = await m.locator('.mo-booking__lines tfoot tr >> nth=0').innerText();
  log('Hora elegida', slotTime, '· Resumen total:', total.replace(/\s+/g, ' '));
  await m.screenshot({ path: SHOTS + '/m4-resumen.png', fullPage: true });
  await m.click('[data-form=summary] button[type=submit]');
  const termsError = await m.locator('[data-error-for=accept_terms]').innerText();
  log('Sin aceptar condiciones:', termsError);
  await m.check('[name=accept_terms]');
  await m.click('[data-form=summary] button[type=submit]');
  await m.waitForSelector('.mo-booking__ref', { timeout: 15000 });
  const ref = (await m.locator('.mo-booking__ref strong').innerText()).trim();
  log('Reserva creada:', ref, '·', (await m.locator('.mo-booking__deadline').innerText()).replace(/\s+/g, ' '));
  await m.screenshot({ path: SHOTS + '/m5-listo.png', fullPage: true });
  await noHScroll(m, 'Confirmación');
  const portalUrl = await m.locator('.mo-booking__portal-link a').getAttribute('href');
  fs.writeFileSync('/tmp/claude-0/-home-user/b438b3b3-75ee-55b2-8887-cb312ea10238/scratchpad/portal-url.txt', portalUrl);
  const tmpPng = '/tmp/claude-0/-home-user/b438b3b3-75ee-55b2-8887-cb312ea10238/scratchpad/comprobante.png';
  fs.writeFileSync(tmpPng, PNG);
  await m.setInputFiles('#mo-done-file', tmpPng);
  await m.click('[data-mo-upload] button');
  await m.waitForSelector('.mo-booking__success', { timeout: 15000 });
  log('Subida:', await m.locator('.mo-booking__success').innerText());
  await m.screenshot({ path: SHOTS + '/m6-comprobante.png', fullPage: true });

  // Otra persona intenta el mismo horario.
  const other = await (await browser.newContext({ viewport: { width: 360, height: 780 } })).newPage();
  await other.goto(url);
  await other.waitForSelector('.mo-booking__day--available');
  await other.click('.mo-booking__day--available >> nth=0');
  await other.waitForSelector('.mo-booking__slot');
  const takenState = await other.locator('.mo-booking__slot >> nth=0').innerText();
  log('Para otra persona, el horario', slotTime, 'aparece como:', takenState.replace(/\s+/g, ' '));

  // Administradora aprueba.
  await admin.goto(BASE + '/wp-admin/admin.php?page=mo-booking-bookings&status=');
  await admin.click(`a:has-text("${ref}")`);
  await admin.waitForSelector('.mo-proof');
  await admin.screenshot({ path: SHOTS + '/a1-detalle-antes.png', fullPage: true });
  const amount = await admin.inputValue('.mo-proof [name=amount]');
  log('Importe sugerido para aprobar:', amount);
  await Promise.all([admin.waitForNavigation(), admin.click('.mo-proof button:has-text("Aprobar pago")')]);
  log('Tras aprobar:', (await admin.locator('.notice').first().innerText()).slice(0, 100));
  await admin.screenshot({ path: SHOTS + '/a2-detalle-despues.png', fullPage: true });

  // Portal privado en otro dispositivo (sin cookie previa).
  const p2 = await (await browser.newContext({ viewport: { width: 360, height: 780 } })).newPage();
  await p2.goto(portalUrl);
  await p2.waitForSelector('.mo-booking__pill', { timeout: 15000 });
  log('Portal: estado', await p2.locator('.mo-booking__pill').innerText(), '· URL sin token:', !p2.url().includes('acceso='));
  await p2.screenshot({ path: SHOTS + '/m7-portal.png', fullPage: true });
  await noHScroll(p2, 'Portal');
  await p2.reload();
  await p2.waitForSelector('.mo-booking__pill', { timeout: 15000 });
  log('Portal tras recargar (cookie de sesión):', await p2.locator('.mo-booking__pill').innerText());

  // Token alterado.
  const p3 = await (await browser.newContext()).newPage();
  await p3.goto(portalUrl.slice(0, -3) + 'AAA');
  await p3.waitForSelector('.mo-booking__alert', { timeout: 15000 });
  log('Token alterado:', await p3.locator('.mo-booking__alert').innerText());

  log('Errores de consola JS:', consoleErrors.length ? consoleErrors : 'ninguno');
  await browser.close();
})().catch(e => { console.error('FALLÓ:', e.message); process.exit(1); });
