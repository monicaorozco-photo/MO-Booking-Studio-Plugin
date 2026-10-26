const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext();
  const p = await ctx.newPage();
  await p.goto('http://localhost:8089/wp-login.php'); await p.fill('#user_login','monica'); await p.fill('#user_pass','clave-de-prueba-123');
  await Promise.all([p.waitForNavigation({timeout:90000}), p.click('#wp-submit')]);
  await p.goto('http://localhost:8089/wp-admin/admin.php?page=mo-booking-bookings&booking=' + process.argv[2]);
  let wa = null;
  ctx.on('response', r => { const loc = r.headers()['location']; if (loc && /wa\.me/.test(loc)) { wa = loc; } });
  await p.selectOption('select[name=event]', 'booking_confirmed');
  const [popup] = await Promise.all([ctx.waitForEvent('page'), p.click('button:has-text("Abrir WhatsApp")')]);
  await popup.waitForTimeout(3000);
  console.log(wa ? (/%0A/.test(wa) ? 'SALTOS OK ' : 'SIN SALTOS ') + decodeURIComponent(wa).slice(0, 160).replace(/\n/g, '⏎') : 'sin redirección');
  await b.close();
})();
