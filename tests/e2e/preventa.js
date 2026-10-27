/*
 * Recorrido de navegador de la beta de preventa (AC-01, AC-02, AC-03, AC-04, AC-08, AC-18).
 * Requiere el sitio local descrito en tests/e2e/README.md y una página con
 * [mo_booking service="prueba-minis-navidenas-2026"] en BOOKING_URL.
 *
 *   BOOKING_URL=http://localhost:8089/?page_id=N SHOTS=/tmp/capturas node preventa.js
 *
 * Termina con código 1 si alguna verificación falla.
 */
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://localhost:8089';
const SHOTS = process.env.SHOTS || '.';
const URL_ = process.env.BOOKING_URL;
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');
let failures = 0;
const ok = (cond, label, extra) => {
  console.log((cond ? 'PASA ' : 'FALLA') + ' · ' + label + (extra ? ' · ' + extra : ''));
  if (!cond) { failures++; }
};

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
  ok(w[0] <= w[1], 'Sin desplazamiento horizontal en ' + label, w[0] + ' ≤ ' + w[1]);
}
/** Campos o botones recortados: algún control visible que sale del ancho de la pantalla. */
async function noClipped(page, label) {
  const bad = await page.evaluate(() => {
    const out = [];
    document.querySelectorAll('.mo-booking input, .mo-booking select, .mo-booking textarea, .mo-booking button, .mo-booking summary').forEach(el => {
      const r = el.getBoundingClientRect();
      if (r.width && (r.right > window.innerWidth + 1 || r.left < -1)) { out.push((el.name || el.textContent || el.tagName).trim().slice(0, 30)); }
    });
    return out;
  });
  ok(bad.length === 0, 'Ningún control recortado en ' + label, bad.join(', '));
}
async function toDetails(page) {
  await page.waitForSelector('[data-action=new-client]', { timeout: 15000 });
  await page.click('[data-action=new-client]');
  await page.waitForSelector('.mo-booking__day--available', { timeout: 15000 });
  await page.click('.mo-booking__day--available >> nth=0');
  await page.waitForSelector('.mo-booking__slot');
  const slot = page.locator('.mo-booking__slot:not([disabled])').first();
  const time = await slot.innerText();
  await slot.click();
  await page.click('[data-action=to-details]');
  await page.waitForSelector('[data-form=details]');
  return time;
}
async function fillDetails(page, email) {
  await page.fill('#mo-f-children', '2');
  await page.fill('#mo-f-adults', '3');
  await page.fill('#mo-f-name', 'Laura   Prueba ');
  await page.fill('#mo-f-email', email);
  await page.fill('#mo-f-phone', '656 123 4567');
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' }).catch(() => chromium.launch());
  const admin = await adminLogin(browser);
  await admin.goto(BASE + '/wp-admin/admin.php?page=mo-booking-settings');
  await Promise.all([admin.waitForNavigation(), admin.click('button:has-text("Verificar almacenamiento privado")')]);
  ok(await admin.locator('.notice-success').count() > 0, 'Almacenamiento privado verificado', (await admin.locator('.notice').allInnerTexts()).join(' ').replace(/\s+/g, ' ').slice(0, 160));
  await Promise.all([admin.waitForNavigation(), admin.click('button:has-text("datos de prueba")')]);

  // ---- Teléfono (360 px) ----
  const mobile = await browser.newContext({ viewport: { width: 360, height: 780 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const m = await mobile.newPage();
  const consoleErrors = [];
  m.on('console', msg => { if (msg.type() === 'error') { consoleErrors.push(msg.text()); } });
  m.on('pageerror', e => consoleErrors.push(e.message));
  await m.goto(URL_);
  await m.waitForSelector('[data-action=returning]', { timeout: 15000 });
  // Decisión B (beta.3): nombre, correo y teléfono en campos separados; correo O teléfono + nombre parecido.
  await m.click('[data-action=returning]');
  await m.waitForSelector('[data-form=identify]');
  ok(await m.locator('#mo-f-id-name').count() === 1 && await m.locator('#mo-f-id-email').count() === 1 && await m.locator('#mo-f-id-phone').count() === 1, 'Identificación con nombre, correo y teléfono separados');
  await m.screenshot({ path: SHOTS + '/p0-identificacion.png', fullPage: true });
  await m.fill('#mo-f-id-name', 'Nadie Conocido');
  await m.fill('#mo-f-id-email', 'nadie@example.com');
  await m.click('[data-form=identify] button[type=submit]');
  await m.waitForSelector('[data-action=continue-general]');
  ok(/No pudimos confirmar/.test(await m.locator('.mo-booking__welcome').innerText()), 'Sin coincidencia: mensaje neutro y continuar con precio general');
  await m.click('[data-action=returning]');
  await m.fill('#mo-f-id-name', 'prueba frecuente tres');
  await m.fill('#mo-f-id-email', 'frecuente3@example.com');
  await m.fill('#mo-f-id-phone', '');
  await m.click('[data-form=identify] button[type=submit]');
  await m.waitForSelector('.mo-booking__day--available', { timeout: 15000 });
  const badge = await m.locator('.mo-booking__who').innerText();
  ok(/cliente frecuente/.test(badge), 'Frecuente reconocido: precios de cliente frecuente', badge.replace(/\s+/g, ' '));
  await m.screenshot({ path: SHOTS + '/p0-frecuente.png', fullPage: true });
  await m.goto(URL_);
  const slotTime = await toDetails(m);

  // AC-01: orden exacto y sin valores predeterminados.
  const order = await m.evaluate(() => Array.from(document.querySelectorAll('[data-form=details] .mo-booking__selected, [data-form=details] .mo-booking__total-people, [data-form=details] label.mo-booking__label, [data-form=details] legend')).map(e => e.textContent.replace(/\s+/g, ' ').trim()));
  // Rediseño (beta.5): a petición del estudio, primero los datos de contacto y después los asistentes.
  const expected = ['Fecha y horario', 'Nombre y apellido:', 'Correo electrónico:', 'Teléfono (WhatsApp):', 'Niños', 'Adultos', 'Mascotas', 'Número de asistentes:', 'Alguna información importante que debamos saber antes de tu sesión:', '¿Deseas agregar entrega rápida de 2 días hábiles a tu sesión?'];
  const positions = expected.map(t => order.findIndex(o => o.indexOf(t) === 0));
  ok(positions.every(p => p >= 0) && positions.every((p, i) => i === 0 || p > positions[i - 1]), 'AC-01 orden exacto de los 10 campos', JSON.stringify(order));
  ok(order[order.length - 1].indexOf('¿Deseas agregar entrega rápida') === 0, 'AC-01 entrega rápida es el último campo');
  ok(await m.inputValue('#mo-f-children') === '' && await m.inputValue('#mo-f-adults') === '' && await m.inputValue('#mo-f-pets') === '', 'AC-01 niños, adultos y mascotas vacíos');
  ok(await m.locator('[data-attendees]').innerText() === '0', 'AC-01 asistentes sin 6 predeterminado', await m.locator('[data-attendees]').innerText());
  ok((await m.content()).indexOf('no cuentan como participantes') === -1, 'AC-01 sin la leyenda de mascotas');
  ok(!(await m.isChecked('#mo-f-fast_delivery')), 'AC-01 entrega rápida desmarcada');
  ok((await m.locator('#mo-help-fast').innerText()) === 'Entrega regular de 5 a 7 días hábiles.', 'Texto inferior exacto de entrega rápida');
  await m.screenshot({ path: SHOTS + '/p1-formulario-vacio.png', fullPage: true });

  await m.fill('#mo-f-children', '2');
  await m.fill('#mo-f-adults', '3');
  ok(await m.locator('[data-attendees]').innerText() === '5', 'AC-01 asistentes = niños + adultos', await m.locator('[data-attendees]').innerText());
  await m.fill('#mo-f-pets', '1');
  ok(await m.locator('[data-attendees]').innerText() === '5', 'AC-01 mascotas no suman al renglón de asistentes');
  await m.fill('#mo-f-important_info', 'Mi hija usa lentes.');
  await m.fill('#mo-f-name', 'Laura   Prueba ');
  // "Cambiar" regresa al calendario sin borrar lo capturado.
  await m.click('.mo-booking__selected [data-action=to-calendar]');
  await m.waitForSelector('.mo-booking__slot');
  await m.click('[data-action=to-details]');
  await m.waitForSelector('[data-form=details]');
  ok(await m.inputValue('#mo-f-adults') === '3' && await m.inputValue('#mo-f-name') === 'Laura   Prueba ', 'Cambiar fecha conserva los datos');

  // Errores accesibles junto al campo.
  await m.fill('#mo-f-children', '-1');
  await m.click('[data-form=details] button[type=submit]');
  const err = await m.locator('[data-error-for=children]').innerText();
  ok(/entero/.test(err) && await m.getAttribute('#mo-f-children', 'aria-invalid') === 'true', 'Número negativo rechazado junto al campo', err);
  await m.fill('#mo-f-children', '2');
  await m.fill('#mo-f-email', 'laura@clientes-mo.mx');
  await m.fill('#mo-f-phone', '656 123 4567');
  // Contrato obligatorio.
  ok(await m.locator('.mo-booking__contract summary').count() === 1, 'AC-08 contrato en acordeón debajo del formulario');
  await m.click('[data-form=details] button[type=submit]');
  ok(/acepta el contrato/.test(await m.locator('[data-error-for=accept_contract]').innerText()), 'Sin aceptar el contrato no avanza');
  await m.click('.mo-booking__contract summary');
  ok((await m.locator('.mo-booking__contract-body').innerText()).indexOf('Puntualidad') >= 0, 'El acordeón muestra el contrato');
  await m.check('#mo-f-accept_contract');
  await m.click('[data-form=details] button[type=submit]');
  await m.waitForSelector('[data-form=summary]');
  const totalWithout = await m.locator('.mo-booking__lines tfoot tr >> nth=0').innerText();
  await m.click('[data-action=to-details]');
  await m.waitForSelector('[data-form=details]');
  await m.check('#mo-f-fast_delivery');
  await m.click('[data-form=details] button[type=submit]');
  await m.waitForSelector('[data-form=summary]');
  const summaryText = (await m.locator('[data-form=summary]').innerText()).replace(/\s+/g, ' ');
  const totalWith = await m.locator('.mo-booking__lines tfoot tr >> nth=0').innerText();
  ok(totalWith !== totalWithout && /Entrega rápida/.test(summaryText), 'AC-01 entrega rápida actualiza el total', totalWithout.replace(/\s+/g, ' ') + ' → ' + totalWith.replace(/\s+/g, ' '));
  ['Niños', 'Adultos', 'Total de asistentes', 'Mascotas', 'Anticipo requerido', '12 horas', 'Contrato aceptado'].forEach(t => ok(summaryText.indexOf(t) >= 0, 'Resumen incluye "' + t + '"'));
  await noHScroll(m, 'resumen');
  await noClipped(m, 'resumen');
  await m.screenshot({ path: SHOTS + '/p2-resumen.png', fullPage: true });

  // AC-02: otra persona intenta el mismo horario al mismo tiempo.
  const other = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await other.goto(URL_);
  await toDetails(other);
  await fillDetails(other, 'otra@clientes-mo.mx');
  await other.check('#mo-f-accept_contract');
  await other.click('[data-form=details] button[type=submit]');
  await other.waitForSelector('[data-form=summary]');
  // beta.4: la casilla de avisos es obligatoria.
  await m.click('[data-form=summary] button[type=submit]');
  ok(/acepta recibir los avisos/.test(await m.locator('[data-error-for=whatsapp_consent]').innerText()), 'Casilla de avisos obligatoria');
  await m.check('#mo-f-whatsapp_consent');
  await other.check('#mo-f-whatsapp_consent');
  await Promise.all([m.click('[data-form=summary] button[type=submit]'), other.click('[data-form=summary] button[type=submit]')]);
  await m.waitForTimeout(9000);
  const mWon = await m.locator('.mo-booking__ref').count() > 0;
  const oWon = await other.locator('.mo-booking__ref').count() > 0;
  const loserMsg = mWon ? await other.locator('.mo-booking__alert, .mo-booking__message').allInnerTexts() : await m.locator('.mo-booking__alert, .mo-booking__message').allInnerTexts();
  ok(mWon !== oWon, 'AC-02 solo una persona obtiene el horario ' + slotTime);
  ok(/ya no está disponible|alguien acaba de apartar/.test(loserMsg.join(' ')), 'AC-02 la otra recibe un mensaje claro', loserMsg.join(' ').slice(0, 120));
  const winner = mWon ? m : other;
  await winner.waitForSelector('.mo-booking__welcome-new', { timeout: 15000 });
  ok(/mo_nuevo/.test(winner.url()) === false && /horario está apartado/.test(await winner.locator('.mo-booking__welcome-new').innerText()), 'Al enviar llega a su vista de reserva (sin token en la dirección)', winner.url());
  const portalText = await winner.locator('.mo-booking-portal').innerText();
  ok(!/Indicaciones/.test(portalText) && (!/Lugar/.test(portalText) || /Te lo compartiremos/.test(portalText)), 'Ubicación e indicaciones ocultas hasta confirmar');
  ok(await winner.locator('.mo-booking__contract summary').count() === 1, 'Contrato aceptado en la vista de reserva');
  await winner.screenshot({ path: SHOTS + '/p3-confirmacion.png', fullPage: true });
  const deadline = await winner.locator('.mo-booking__deadline').innerText();
  ok(/antes del .*\d{1,2}:\d{2}/i.test(deadline), 'AC-03 muestra la hora exacta de vencimiento', deadline.replace(/\s+/g, ' '));
  const ref = await winner.locator('.mo-booking__ref strong').innerText();

  // AC-04: carga del comprobante desde la confirmación (enlace privado).
  await winner.setInputFiles('[data-mo-upload] input[type=file]', { name: 'comprobante.png', mimeType: 'image/png', buffer: PNG });
  await winner.click('[data-mo-upload] button[type=submit]');
  await winner.waitForSelector('.mo-booking__upload-wrap .mo-booking__success', { timeout: 15000 });
  ok(true, 'AC-04 comprobante cargado; aviso dentro del recuadro', await winner.locator('.mo-booking__upload-wrap .mo-booking__success').innerText());
  ok(await winner.locator('.mo-booking__proofs a').count() === 1, 'Puede volver a ver su comprobante');
  const ownProof = await winner.request.get(await winner.getAttribute('.mo-booking__proofs a', 'href'));
  ok(ownProof.status() === 200 && /image\/png/.test(ownProof.headers()['content-type'] || ''), 'Su comprobante se abre con su sesión', String(ownProof.status()));
  const strangerProof = await (await browser.newContext()).request.get(await winner.getAttribute('.mo-booking__proofs a', 'href'));
  ok(strangerProof.status() !== 200, 'Sin su sesión no se abre', String(strangerProof.status()));

  // El panel autorizado lo ve; sin sesión no.
  await admin.goto(BASE + '/wp-admin/admin.php?page=mo-booking-bookings&search=' + encodeURIComponent(ref) + '&status=');
  await admin.click('text=' + ref);
  await admin.waitForSelector('text=Ver copia segura');
  const proofHref = await admin.locator('a:has-text("Ver copia segura")').getAttribute('href');
  const authed = await admin.request.get(proofHref);
  ok(authed.status() === 200 && /image\/png/.test(authed.headers()['content-type'] || ''), 'AC-04 el panel autorizado ve el comprobante', String(authed.status()));
  const anon = await (await browser.newContext()).request.get(proofHref, { maxRedirects: 0 });
  ok(anon.status() !== 200 || !/image/.test(anon.headers()['content-type'] || ''), 'AC-04 sin sesión no se puede ver', String(anon.status()));
  const detail = (await admin.locator('#wpbody').innerText()).replace(/\s+/g, ' ');
  ok(/niños 2, adultos 3/.test(detail) && /Mi hija usa lentes/.test(detail) && /Entrega rápida/.test(detail), 'Detalle administrativo muestra asistentes, información y extra');
  ok(/Hash SHA-256/.test(detail) && /copia íntegra/.test(detail), 'AC-08 el panel muestra la copia del contrato con su hash');
  await admin.screenshot({ path: SHOTS + '/p4-detalle-admin.png', fullPage: true });

  // ---- Teclado (escritorio): orden de foco del formulario ----
  const kb = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await kb.goto(URL_);
  await kb.waitForSelector('[data-action=new-client]');
  await kb.focus('[data-action=new-client]');
  await kb.keyboard.press('Enter');
  await kb.waitForSelector('.mo-booking__day--available');
  await kb.focus('.mo-booking__day--available >> nth=1');
  await kb.keyboard.press('Enter');
  await kb.waitForSelector('.mo-booking__slot:not([disabled])');
  await kb.focus('.mo-booking__slot:not([disabled]) >> nth=0');
  await kb.keyboard.press('Enter');
  await kb.focus('[data-action=to-details]');
  await kb.keyboard.press('Enter');
  await kb.waitForSelector('[data-form=details]');
  await kb.focus('.mo-booking__selected [data-action=to-calendar]');
  const seq = [];
  for (let i = 0; i < 9; i++) {
    await kb.keyboard.press('Tab');
    seq.push(await kb.evaluate(() => document.activeElement.name || document.activeElement.id));
  }
  ok(JSON.stringify(seq.slice(0, 9)) === JSON.stringify(['name', 'email', 'phone_country', 'phone', 'children', 'adults', 'pets', 'important_info', 'fast_delivery']), 'AC-18 el tabulador sigue el orden del formulario', seq.join(' → '));
  await kb.keyboard.type(' ');
  ok(await kb.isChecked('#mo-f-fast_delivery'), 'AC-18 la casilla se marca con teclado');

  ok(consoleErrors.length === 0, 'Sin errores de JavaScript', consoleErrors.join(' | '));
  await browser.close();
  console.log(failures ? failures + ' verificaciones fallaron' : 'Todas las verificaciones pasaron');
  process.exit(failures ? 1 : 0);
})();
