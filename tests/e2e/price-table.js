const { chromium } = require('playwright');
const BASE = 'http://localhost:8089';
const log = (...a) => console.log('•', ...a);
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await page.goto(BASE + '/wp-login.php');
  await page.fill('#user_login', 'monica'); await page.fill('#user_pass', 'clave-de-prueba-123');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-services');
  await Promise.all([page.waitForNavigation(), page.click('tr:has-text("PRUEBA") a:has-text("Editar")')]);
  log('Preventa hasta (cargado):', await page.inputValue('#mo-price-table-presale-until'));
  log('Fila 1:', await page.inputValue('input[name="price_table[rows][0][from]"]'), '→', await page.inputValue('input[name="price_table[rows][0][to]"]'), 'público preventa', await page.inputValue('input[name="price_table[rows][0][presale_general]"]'), 'especial', await page.isChecked('input[name="price_table[rows][0][special]"]'));
  await page.locator('.mo-card:has-text("Precios por temporada")').screenshot({ path: process.env.SHOTS + '/a5-tabla-precios.png' });
  await page.fill('input[name="price_table[rows][1][from]"]', '2026-12-01');
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Guardar servicio")')]);
  log('Rangos encimados:', (await page.locator('.notice-error').first().innerText()).split('\n')[0]);
  log('Formulario conserva lo escrito:', await page.inputValue('input[name="price_table[rows][1][from]"]'));
  // beta.5: tras un error, los importes se muestran en pesos tal como se escribieron (antes el anticipo $500 se volvía $5).
  log('Importes tras el error (anticipo, entrega rápida):', await page.inputValue('[name=deposit_value]'), await page.inputValue('[name="fast_delivery[price]"]'));
  await page.fill('input[name="price_table[rows][1][from]"]', '2026-12-05');
  await page.fill('#mo-price-table-presale-until', '2026-10-25T18:00');
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Guardar servicio")')]);
  log('Cambiar fecha límite:', (await page.locator('.notice-success').first().innerText()).split('\n')[0], '→', await page.inputValue('#mo-price-table-presale-until'));
  await b.close();
})().catch(e => { console.error('FALLÓ', e.message); process.exit(1); });
