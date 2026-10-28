const { chromium } = require('playwright');
const { execSync } = require('child_process');
const BASE = 'http://localhost:8089', SHOTS = process.env.SHOTS, O = process.env.O;
const php = (args, env) => execSync('php ' + O + '/' + args, { env: Object.assign({}, process.env, { WP: O + '/wpdev' }, env || {}) }).toString().trim();
let failures = 0;
const ok = (c, l, x) => { console.log((c ? 'PASA ' : 'FALLA') + ' · ' + l + (x ? ' · ' + x : '')); if (!c) failures++; };
const notice = async page => (await page.locator('.notice').allInnerTexts()).join(' ').replace(/\s+/g, ' ');
const noPhp = async (page, l) => ok(!/(Fatal error|Warning:|Notice:|Deprecated:|critical error)/.test(await page.content()), 'Sin errores PHP · ' + l);
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  page.on('dialog', d => d.accept());
  await page.goto(BASE + '/wp-login.php'); await page.fill('#user_login', 'monica'); await page.fill('#user_pass', 'clave-de-prueba-123');
  await Promise.all([page.waitForNavigation({ timeout: 90000 }), page.click('#wp-submit')]);

  // Reserva pagada: eliminar el pago registrado por error.
  const [id, portal] = php('b3-booking.php').split(' ');
  const url = BASE + '/wp-admin/admin.php?page=mo-booking-bookings&booking=' + id;
  await page.goto(url);
  await noPhp(page, 'detalle');
  await page.click('.mo-movements summary:has-text("Eliminar")');
  await page.fill('.mo-movements input[name=reason]', 'Pago capturado en la reserva equivocada');
  await Promise.all([page.waitForNavigation(), page.click('.mo-movements button:has-text("Eliminar movimiento")')]);
  const n1 = await notice(page);
  ok(/Movimiento eliminado/.test(n1) && /ya no tiene el anticipo cubierto/.test(n1), 'Movimiento eliminado con aviso de anticipo', n1.slice(-260));
  ok(await page.locator('.mo-movements').count() === 0, 'Ya no hay movimientos');

  // Adjuntar comprobante desde el panel.
  await page.click('summary:has-text("Adjuntar un comprobante")');
  require('fs').writeFileSync(O + '/proof.png', Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64'));
  await page.setInputFiles('input[name=proof_file]', O + '/proof.png');
  await page.fill('form:has(input[name=proof_file]) input[name=note]', 'Lo mandó por WhatsApp');
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Adjuntar comprobante")')]);
  ok(/Comprobante adjuntado/.test(await notice(page)), 'Comprobante adjuntado desde el panel');
  ok(/Desde el panel por/.test(await page.locator('#wpbody').innerText()), 'Registra quién lo subió');
  await page.screenshot({ path: SHOTS + '/b1-detalle.png', fullPage: true });

  // Plantillas: código desconocido, vista previa y guía.
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-notifications&template=new');
  await noPhp(page, 'notificaciones');
  await page.fill('#mo-name', 'Recordatorio de saldo');
  await page.selectOption('#mo-channel', 'whatsapp');
  // El mensaje usa el editor con formato (TinyMCE).
  await page.waitForFunction(() => window.tinymce && window.tinymce.get('mo_body'));
  await page.evaluate(() => { window.tinymce.get('mo_body').setContent('<p>Hola {nombre}, <strong>tu saldo</strong> es </p>'); });
  await page.waitForTimeout(1600);
  ok(/No reconocemos: \{nombre\}/.test(await page.locator('[data-warning-for=mo_body]').innerText()), 'Aviso de código con una sola llave (editor con formato)');
  await page.evaluate(() => { const ed = window.tinymce.get('mo_body'); ed.focus(); ed.selection.select(ed.getBody(), true); ed.selection.collapse(false); });
  await page.click('.mo-code[data-target=mo_body][data-insert="{{saldo}}"]');
  await page.click('[data-mo-preview=mo_body]');
  ok(/\$900\.00 MXN/.test(await page.locator('[data-preview-for=mo_body]').innerText()), 'Vista previa con datos de ejemplo', await page.locator('[data-preview-for=mo_body]').innerText());
  ok(await page.locator('[data-preview-for=mo_body] strong').count() === 1, 'La vista previa muestra el formato');
  await page.screenshot({ path: SHOTS + '/b2-plantilla.png', fullPage: true });
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Crear plantilla")')]);
  ok(/Plantilla guardada/.test(await notice(page)) && /\{nombre\}/.test(await notice(page)), 'Guardada con aviso de código desconocido', (await notice(page)).slice(-200));
  await page.goto(url);
  ok(await page.locator('select[name=event] option:has-text("Recordatorio de saldo")').count() >= 1, 'La plantilla de WhatsApp aparece en la reserva');

  // Enlace privado: botón de contrato y página imprimible.
  const client = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  await client.goto(portal);
  await client.waitForSelector('.mo-booking__print a');
  await client.screenshot({ path: SHOTS + '/b3-portal.png', fullPage: true });
  const [printPage] = await Promise.all([client.context().waitForEvent('page'), client.click('.mo-booking__print a')]);
  await printPage.waitForLoadState();
  const printText = await printPage.locator('body').innerText();
  ok(/Contrato y datos de tu sesión/.test(printText) && /versión \d/.test(printText) && /Trae su perrito/.test(printText), 'Página imprimible con contrato y datos', printText.slice(0, 120).replace(/\s+/g, ' '));
  ok(!/Pago capturado en la reserva equivocada|Lo mandó por WhatsApp/.test(printText), 'Sin notas internas');
  await printPage.screenshot({ path: SHOTS + '/b4-imprimible.png', fullPage: true });
  const anon = await (await browser.newContext()).newPage();
  const r = await anon.goto(BASE + '/?mo_print=1');
  ok(r.status() === 403 && /enlace privado/.test(await anon.locator('body').innerText()), 'Sin enlace privado no se ve nada', String(r.status()));

  // Sin carga de archivos: botón al WhatsApp del negocio.
  php('setting.php save'); php('setting.php nouploads');
  try {
    const [, portal2] = php('b3-booking.php', { MODE: 'pending' }).split(' ');
    const c2 = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    await c2.goto(portal2);
    await c2.waitForSelector('.mo-booking__whatsapp');
    const href = await c2.getAttribute('.mo-booking__whatsapp', 'href');
    ok(href.indexOf('https://api.whatsapp.com/send?phone=526561002000&text=') === 0 && /Hola%21%20Hago%20el%20env/.test(href) && /Sof%C3%ADa%20Portal/.test(href), 'Botón al WhatsApp del negocio con mensaje', decodeURIComponent(href));
    ok(/Da clic en este botón/.test(await c2.locator('.mo-booking__whatsapp').innerText()), 'Texto del botón');
    await c2.screenshot({ path: SHOTS + '/b5-whatsapp.png', fullPage: true });
  } finally {
    php('setting.php restore');
  }
  await browser.close(); process.exit(failures ? 1 : 0);
})();
