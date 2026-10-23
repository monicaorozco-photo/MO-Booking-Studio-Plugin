const { chromium } = require('playwright');
const BASE = 'http://localhost:8089', SHOTS = process.env.SHOTS;
let failures = 0;
const ok = (c, l, x) => { console.log((c ? 'PASA ' : 'FALLA') + ' · ' + l + (x ? ' · ' + x : '')); if (!c) failures++; };
const notice = async page => (await page.locator('.notice').allInnerTexts()).join(' ').replace(/\s+/g, ' ');
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' }).catch(() => chromium.launch());
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  page.on('dialog', d => d.type() === 'prompt' ? d.accept('ELIMINAR') : d.accept());
  await page.goto(BASE + '/wp-login.php');
  await page.fill('#user_login', 'monica'); await page.fill('#user_pass', 'clave-de-prueba-123');
  await Promise.all([page.waitForNavigation({ timeout: 90000 }), page.click('#wp-submit')]);
  const php = async l => ok(!/(Fatal error|Warning:|Notice:|Deprecated:|critical error)/.test(await page.content()), 'Sin errores PHP · ' + l);

  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-clients');
  await php('lista clientes');
  ok(await page.locator('.subsubsub').count() === 1 && /Papelera/.test(await page.locator('.subsubsub').innerText()), 'Pestañas con conteo');
  ok(await page.locator('#bulk-action-selector-top').count() === 1, 'Acciones masivas');
  await page.screenshot({ path: SHOTS + '/c1-clientes.png', fullPage: true });

  await page.click('.subsubsub a:has-text("Posibles duplicados")');
  await php('duplicados');
  const dup = await page.locator('#wpbody').innerText();
  ok(/Nombre parecido/.test(dup) && /Mismo teléfono/.test(dup), 'Sugerencias de duplicados', dup.replace(/\s+/g, ' ').slice(0, 200));
  await page.screenshot({ path: SHOTS + '/c2-duplicados.png', fullPage: true });

  await page.click('tr:has-text("Mismo teléfono") a:has-text("Fusionar")');
  await php('fusionar');
  await page.screenshot({ path: SHOTS + '/c3-fusionar.png', fullPage: true });
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Fusionar")')]);
  ok(/fusionados/.test(await notice(page)), 'Fusión hecha', await notice(page));
  const after = await page.locator('#wpbody').innerText();
  ok(/Otros correos y teléfonos/.test(after), 'Ficha con contactos adicionales');
  await page.screenshot({ path: SHOTS + '/c4-ficha.png', fullPage: true });

  // Agregar un contacto adicional.
  await page.fill('[name=contact_value]', 'angelica.trabajo@example.com');
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Agregar")')]);
  ok(/Contacto agregado/.test(await notice(page)), 'Contacto agregado');

  // Papelera y restaurar desde la lista.
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-clients&s=Pedro');
  await page.hover('tr:has-text("Pedro Prueba")');
  await Promise.all([page.waitForNavigation(), page.click('tr:has-text("Pedro Prueba") .row-actions a:has-text("Papelera")')]);
  ok(/papelera: 1/.test(await notice(page)), 'Fila a la papelera', await notice(page));
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-clients&view=trash');
  ok(/Pedro Prueba/.test(await page.locator('#wpbody').innerText()), 'Aparece en Papelera');
  await page.screenshot({ path: SHOTS + '/c5-papelera.png', fullPage: true });
  await page.check('tr:has-text("Pedro Prueba") input[name="ids[]"]');
  await page.selectOption('#bulk-action-selector-top', 'purge');
  await Promise.all([page.waitForNavigation(), page.click('#doaction')]);
  ok(/eliminados permanentemente: 1/.test(await notice(page)), 'Eliminación masiva con ELIMINAR', await notice(page));

  for (const p of ['mo-booking-bookings', 'mo-booking-bookings&view=trash', 'mo-booking-services', 'mo-booking-contracts']) {
    await page.goto(BASE + '/wp-admin/admin.php?page=' + p);
    await php(p);
    await page.screenshot({ path: SHOTS + '/l-' + p.replace(/[^a-z]/g, '') + '.png', fullPage: true });
  }
  await browser.close(); process.exit(failures ? 1 : 0);
})();
