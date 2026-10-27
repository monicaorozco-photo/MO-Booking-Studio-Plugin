const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await b.newPage({ viewport: { width: 390, height: 844 } });
  await p.goto(process.env.BOOKING_URL);
  await p.click('[data-action=new-client]');
  await p.waitForSelector('.mo-booking__day--available');
  await p.click('.mo-booking__day--available >> nth=0');
  await p.click('.mo-booking__slot:not([disabled]) >> nth=0');
  const sel = await p.$eval('.mo-booking__slot--selected', e => getComputedStyle(e).backgroundColor);
  const other = await p.$eval('.mo-booking__slot:not(.mo-booking__slot--selected):not([disabled])', e => getComputedStyle(e).backgroundColor).catch(() => 'n/a');
  const day = await p.$eval('.mo-booking__day--selected', e => getComputedStyle(e).backgroundColor);
  const closed = await p.$eval('.mo-booking__day--closed', e => getComputedStyle(e).backgroundColor);
  const primary = await p.$eval('.mo-booking__button--primary', e => getComputedStyle(e).backgroundColor);
  console.log(JSON.stringify({ seleccionado: sel, otro: other, dia: day, cerrado: closed, continuar: primary }));
  await p.screenshot({ path: process.env.SHOTS + '/hostil.png', fullPage: true });
  await b.close();
})();
