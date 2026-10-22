/*
 * Panel de la beta de preventa: contrato versionado (AC-08), edición con vista previa,
 * archivo/restauración (AC-13) y cancelación con destino del anticipo.
 * Ejecutar después de preventa.js (usa la reserva de prueba que crea).
 *
 *   SHOTS=/tmp/capturas node admin-preventa.js
 */
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://localhost:8089';
const SHOTS = process.env.SHOTS || '.';
let failures = 0;
const ok = (cond, label, extra) => {
  console.log((cond ? 'PASA ' : 'FALLA') + ' · ' + label + (extra ? ' · ' + extra : ''));
  if (!cond) { failures++; }
};
const notice = async page => (await page.locator('.notice').allInnerTexts()).join(' ').replace(/\s+/g, ' ');

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' }).catch(() => chromium.launch());
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  page.on('dialog', d => d.accept());
  await page.goto(BASE + '/wp-login.php');
  await page.fill('#user_login', 'monica');
  await page.fill('#user_pass', 'clave-de-prueba-123');
  await Promise.all([page.waitForNavigation({ timeout: 90000 }), page.click('#wp-submit')]);

  // Reserva de prueba activa más reciente.
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-bookings&status=active');
  const href = await page.locator('td[data-label=Reserva] a').first().getAttribute('href');
  await page.goto(href);
  const ref = (await page.locator('h1').innerText()).replace('Reserva ', '').trim().split(/\s/)[0];
  const before = (await page.locator('.mo-contract-copy').innerHTML());

  // AC-08: publicar una versión nueva de la plantilla.
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-contracts');
  await page.click('td a >> nth=0');
  await page.waitForSelector('#mo_contract_body');
  // Editor visual de WordPress (TinyMCE); si no cargó, el área de texto.
  await page.evaluate(html => {
    if (window.tinymce && window.tinymce.get('mo_contract_body')) { window.tinymce.get('mo_contract_body').setContent(html); window.tinymce.triggerSave(); }
    document.getElementById('mo_contract_body').value = html;
  }, '<p>Versión DOS del contrato, con cambios.</p>');
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Guardar borrador")')]);
  ok(/Borrador guardado/.test(await notice(page)), 'Borrador guardado sin publicar');
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Publicar nueva versión")')]);
  ok(/versión nueva/.test(await notice(page)), 'Versión nueva publicada');
  ok((await page.locator('table >> text=Versión DOS').count()) >= 0 && /Versión publicada vigente: 2/.test(await page.locator('#wpbody').innerText()), 'La plantilla quedó en la versión 2');
  await page.screenshot({ path: SHOTS + '/a1-contrato-v2.png', fullPage: true });
  await page.goto(href);
  const after = await page.locator('.mo-contract-copy').innerHTML();
  ok(after === before && !/Versión DOS/.test(after), 'AC-08 la reserva sigue mostrando palabra por palabra la versión aceptada', ref);
  ok(/versión 1/.test(await page.locator('#wpbody').innerText()), 'La reserva indica la versión 1');

  // 12.2: editar con vista previa.
  await page.goto(href + '&edit=1#editar');
  await page.fill('#editar input[name=adults]', '5'); // 7 personas: una adicional cambia el total.
  await page.fill('#editar textarea[name=important_info]', 'Cambio: vienen los abuelos.');
  await Promise.all([page.waitForNavigation(), page.click('#editar button:has-text("Ver efecto del cambio")')]);
  const preview = (await page.locator('#editar .mo-preview').innerText()).replace(/\s+/g, ' ');
  ok(/Adultos 3 5/.test(preview) && /Total/.test(preview), 'Vista previa muestra antes y después', preview.slice(0, 160));
  await page.screenshot({ path: SHOTS + '/a2-editar-vista-previa.png', fullPage: true });
  await page.fill('#editar input[name=reason]', 'Llamó por teléfono');
  await Promise.all([page.waitForNavigation(), page.click('#editar button:has-text("Guardar cambios")')]);
  ok(/Cambios guardados/.test(await notice(page)), 'Cambios guardados');
  ok(/adultos 5/.test(await page.locator('#wpbody').innerText()), 'La reserva refleja el cambio');

  // AC-13: archivar y restaurar.
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Archivar")')]);
  ok(/archivada/.test(await notice(page)), 'Reserva archivada');
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-bookings&status=active&archived=0');
  ok((await page.locator('text=' + ref).count()) === 0, 'No aparece entre las no archivadas');
  await page.goto(BASE + '/wp-admin/admin.php?page=mo-booking-bookings&status=active&archived=1');
  ok((await page.locator('text=' + ref).count()) > 0, 'Aparece con el filtro de archivadas');
  await page.goto(href);
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Restaurar (quitar de archivadas)")')]);
  ok(/restaurada/.test(await notice(page)), 'Reserva restaurada');

  // Eliminar una reserva con historial se bloquea y explica dependencias.
  await page.click('summary:has-text("Eliminar definitivamente")');
  const blockers = (await page.locator('details:has(summary:has-text("Eliminar definitivamente"))').innerText()).replace(/\s+/g, ' ');
  ok(/No se puede eliminar/.test(blockers) && /comprobante/.test(blockers) && /Contrato aceptado/.test(blockers), 'AC-13 eliminar bloqueado con dependencias', blockers.slice(0, 160));

  // 7.7: cancelar con destino del anticipo.
  await page.goto(href + '&cancel=business');
  await page.selectOption('select[name=deposit_outcome]', 'manual');
  await page.fill('.mo-preview input[name=reason]', 'Prueba de cancelación');
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Confirmar cancelación")')]);
  ok(/cancelada/i.test(await notice(page)), 'Reserva cancelada');
  const text = await page.locator('#wpbody').innerText();
  ok(/Cancelada/.test(text) && /Ver copia segura|Descargar original/.test(text), 'Cancelada conserva comprobante y contrato');
  await page.screenshot({ path: SHOTS + '/a3-cancelada.png', fullPage: true });

  const php = /(Fatal error|Warning:|Notice:|Deprecated:|critical error)/.exec(await page.content());
  ok(!php, 'Sin errores de PHP en el panel');
  await browser.close();
  console.log(failures ? failures + ' verificaciones fallaron' : 'Todas las verificaciones pasaron');
  process.exit(failures ? 1 : 0);
})();
