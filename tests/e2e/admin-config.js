const { chromium } = require('playwright');
const BASE = 'http://localhost:8089';
const SHOTS = process.env.SHOTS;
const log = (...a) => console.log('•', ...a);
async function notice(page) { const n = page.locator('.notice-success, .notice-error').first(); return (await n.count()) ? (await n.innerText()).split('\n')[0].slice(0, 160) : '(sin aviso)'; }
async function submit(page, selector) { await Promise.all([page.waitForNavigation(), page.click(selector)]); return notice(page); }
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  page.on('dialog', d => d.accept());
  await page.goto(BASE + '/wp-login.php');
  await page.fill('#user_login', 'monica'); await page.fill('#user_pass', 'clave-de-prueba-123');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

  // 1. Servicio.
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-services&service=new');
  await page.fill('#mo-title', 'Sesión de estudio');
  await page.fill('#mo-brand', 'Mónica Orozco Fotografía');
  await page.selectOption('#mo-status', 'published');
  await page.fill('#mo-new-category', 'Estudio');
  await page.fill('#mo-duration', '60');
  await page.fill('#mo-buffer-after', '15');
  await page.fill('#mo-min-notice-hours', '0');
  await page.fill('#mo-participants-included', '2');
  await page.fill('#mo-participants-max', '4');
  await page.fill('#mo-extra-participant', '150');
  await page.fill('#mo-price', '2,500');
  await page.selectOption('#mo-deposit-type', 'percent');
  await page.fill('#mo-deposit-value', '30');
  await page.fill('input[name="extras[0][label]"]', 'Impresión 8x10');
  await page.fill('input[name="extras[0][price]"]', '120');
  await page.fill('input[name="extras[0][max_qty]"]', '3');
  await page.fill('input[name="questions[0][label]"]', '¿Cómo nos conociste?');
  await page.selectOption('select[name="questions[0][type]"]', 'select');
  await page.fill('textarea[name="questions[0][options]"]', 'Instagram\nRecomendación\nOtro');
  log('Guardar servicio:', await submit(page, 'button:has-text("Guardar servicio")'));
  const serviceUrl = page.url();

  // Error esperado: duración inválida conserva lo escrito.
  await page.fill('#mo-price', 'abc');
  log('Precio inválido:', await submit(page, 'button:has-text("Guardar servicio")'));

  // 2. Disponibilidad.
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-availability');
  await page.fill('#mo-weekly-6', '10:00-14:00');
  log('Horario semanal:', await submit(page, 'button:has-text("Guardar horario semanal")'));
  await page.click('summary:has-text("Cargar varias fechas")');
  await page.fill('#mo-lines', '2026-11-05 10:00-13:00\n2026-11-06 16:00-19:00, 9:00-11:00');
  log('Carga masiva:', await submit(page, 'button:has-text("Guardar fechas")'));
  await page.click('summary:has-text("Cargar varias fechas")');
  await page.fill('#mo-lines', '2026-11-07 25:00-26:00');
  log('Carga con error:', await submit(page, 'button:has-text("Guardar fechas")'));
  const preview = await page.locator('.mo-card:has-text("Vista previa de espacios") p strong').first().innerText().catch(() => '?');
  log('Vista previa:', preview);
  await page.screenshot({ path: SHOTS + '/a3-disponibilidad.png', fullPage: true });

  // 3. Tarifa y conflicto.
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-rates');
  await page.selectOption('select[name=service]', { label: 'Sesión de estudio' });
  await Promise.all([page.waitForNavigation(), page.click('.mo-filter button')]);
  await page.fill('#mo-name', 'Noviembre especial');
  await page.fill('#mo-value', '2200');
  await page.fill('#mo-conditions-session-from', '2026-11-01');
  await page.fill('#mo-conditions-session-to', '2026-11-30');
  log('Tarifa:', await submit(page, 'button:has-text("Agregar regla")'));
  await page.fill('#mo-name', 'Duplicada');
  await page.fill('#mo-value', '2000');
  await page.fill('#mo-conditions-session-from', '2026-11-10');
  log('Tarifa en conflicto:', await submit(page, 'button:has-text("Agregar regla")'));
  await page.fill('#mo-sim-start', '2026-11-05T10:00');
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Simular")')]);
  log('Simulador total:', (await page.locator('.mo-sim tr:has-text("Total")').innerText()).replace(/\s+/g, ' '));

  // 4. Política general con rango ambiguo y luego válida.
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-policies');
  const card = page.locator('.mo-card:has-text("Cancelación")').first();
  await card.locator('input[name="ranges[0][min_hours]"]').fill('0');
  await card.locator('input[name="ranges[0][max_hours]"]').fill('48');
  await card.locator('select[name="ranges[0][deposit]"]').selectOption('retain');
  await card.locator('input[name="ranges[1][min_hours]"]').fill('24');
  log('Política con solape:', await submit(page, '.mo-card:has-text("Cancelación") button:has-text("Guardar nueva versión")'));
  const card2 = page.locator('.mo-card:has-text("Cancelación")').first();
  await card2.locator('input[name="ranges[0][min_hours]"]').fill('0');
  await card2.locator('input[name="ranges[0][max_hours]"]').fill('48');
  await card2.locator('select[name="ranges[0][deposit]"]').selectOption('retain');
  await card2.locator('input[name="ranges[1][min_hours]"]').fill('48');
  await card2.locator('input[name="ranges[1][max_hours]"]').fill('');
  await card2.locator('select[name="ranges[1][deposit]"]').selectOption('refund');
  log('Política válida:', await submit(page, '.mo-card:has-text("Cancelación") button:has-text("Guardar nueva versión")'));
  log('Resumen generado:', (await page.locator('.mo-card:has-text("Cancelación") .mo-policy-summary').innerText()).replace(/\n/g, ' | '));

  // 5. Reserva manual desde el panel con anticipo ya recibido.
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-bookings&new=1');
  await page.selectOption('select[name=service_id]', { label: 'Sesión de estudio' });
  await page.fill('#mo-date', '2026-11-05');
  await Promise.all([page.waitForNavigation(), page.click('.mo-filter button')]);
  await Promise.all([page.waitForNavigation(), page.click('.mo-slot-links a.button >> nth=0')]);
  await page.fill('#mo-name', 'Carla Manual');
  await page.fill('#mo-email', 'carla@example.com');
  await page.fill('#mo-phone', '6569876543');
  await page.fill('#mo-participants', '3');
  await page.fill('#mo-paid', '705');
  log('Reserva manual:', await submit(page, 'button:has-text("Crear reserva")'));
  log('Estado:', await page.locator('.mo-detail-status').innerText());
  await page.screenshot({ path: SHOTS + '/a4-reserva-manual.png', fullPage: true });

  // 6. Cancelación con vista previa (a petición del cliente).
  await page.click('a:has-text("a petición del cliente")');
  await page.waitForSelector('.mo-preview');
  log('Vista previa cancelación:', (await page.locator('.mo-preview p').nth(1).innerText()));
  await page.fill('.mo-preview input[name=reason]', 'Cambio de planes');
  log('Cancelar:', await submit(page, '.mo-preview button'));
  log('Estado final:', await page.locator('.mo-detail-status').innerText());
  await browser.close();
})().catch(e => { console.error('FALLÓ:', e.message); process.exit(1); });
