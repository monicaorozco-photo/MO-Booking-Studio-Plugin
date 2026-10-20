const { chromium } = require('playwright');
const BASE = 'http://localhost:8089';
const SHOTS = process.env.SHOTS;
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' }).catch(async () => chromium.launch());
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/wp-login.php');
  await page.fill('#user_login', 'monica');
  await page.fill('#user_pass', 'clave-de-prueba-123');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  if (!page.url().includes('wp-admin')) { console.log('LOGIN FAILED'); process.exit(1); }
  const pages = ['mo-booking', 'mo-booking-agenda', 'mo-booking-bookings', 'mo-booking-bookings&new=1', 'mo-booking-services', 'mo-booking-services&service=new', 'mo-booking-availability', 'mo-booking-clients', 'mo-booking-clients&client=new', 'mo-booking-payments', 'mo-booking-rates', 'mo-booking-policies', 'mo-booking-notifications', 'mo-booking-finance', 'mo-booking-galleries', 'mo-booking-settings'];
  for (const p of pages) {
    const resp = await page.goto(BASE + '/wp-admin/admin.php?page=' + p);
    const html = await page.content();
    if (page.url().includes('wp-login')) { console.log(p, 'REDIRECTED TO LOGIN'); continue; }
    const bad = /(Fatal error|Warning:|Notice:|Deprecated:|Parse error|Uncaught|critical error)/.exec(html);
    console.log(p.padEnd(36), resp.status(), bad ? 'ERROR: ' + html.substr(html.indexOf(bad[0]), 300).replace(/<[^>]+>/g, '') : 'ok');
    await page.screenshot({ path: `${SHOTS}/admin-${p.replace(/[&=]/g, '_')}.png`, fullPage: true });
  }
  await browser.close();
})();
