// End-to-end in a real browser: look up a street, sign up, confirm, get a reminder,
// change it, unsubscribe, as a resident would.
// Usage: bash tests/browser/setup.sh <dir>; php -S 127.0.0.1:8140 -t <dir>/public &
//        node tests/browser/walkthrough.test.js http://127.0.0.1:8140 <dir> [screenshot-dir]
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');

const [BASE, DIR] = process.argv.slice(2);
const SHOTS = process.argv[4] || fs.mkdtempSync(os.tmpdir() + '/bins-');
let passes = 0, failures = 0;
const check = (ok, msg) => { if (ok) passes++; else { failures++; console.log('  FAIL: ' + msg); } };
const php = (...args) => execFileSync('php', args, { cwd: DIR, encoding: 'utf8' });
const mails = () => fs.existsSync(DIR + '/data/cache/mail.log')
  ? fs.readFileSync(DIR + '/data/cache/mail.log', 'utf8').trim().split('\n').filter(Boolean).map(l => JSON.parse(l)) : [];
const link = (text, page) => text.match(new RegExp('(http\\S+' + page + '\\.php\\?\\S+)'))[1];

(async () => {
  const browser = await chromium.launch();
  const errors = [];

  console.log('- Sign-up closed: look-up still works');
  php('bin/signup.php', 'close');
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(String(e)));
  await p.goto(BASE + '/');
  check(await p.$eval('#signup', e => e.hidden), 'form hidden while closed');
  await p.selectOption('#street', 'harlaxton-drive');
  await p.waitForSelector('#upcoming .day');
  check(/Thursday/.test(await p.textContent('#upcoming .day')) || /Today|Tomorrow/.test(await p.textContent('#upcoming .day')), 'Harlaxton Drive shows Thursday collections');
  check(p.url().includes('street=harlaxton-drive'), 'address bar remembers the street');
  check(await p.$eval('button.js-hide', e => e.hidden), 'Show button hidden when the script runs');

  console.log('- Without JavaScript');
  const nojs = await browser.newContext({ javaScriptEnabled: false });
  const q = await nojs.newPage();
  await q.goto(BASE + '/?street=pelham-crescent');
  check((await q.$$('#upcoming .day')).length === 6, 'server lists six bin days');
  await nojs.close();

  console.log('- Sign-up open');
  php('bin/signup.php', 'open');
  await p.goto(BASE + '/?street=pelham-crescent');
  check(!(await p.$eval('#signup', e => e.hidden)), 'form shown');
  check(await p.$eval('#su-street', e => e.value) === 'pelham-crescent', 'form street follows the look-up');
  await p.screenshot({ path: SHOTS + '/home-desktop.png', fullPage: true });
  await p.fill('#su-email', 'not an email');
  await p.click('#signup-form .button');
  check(/valid email/.test(await p.textContent('#signup-result')), 'bad address caught');
  await p.fill('#su-email', 'Resident@Example.org');
  await p.click('#signup-form .button');
  check(/Tick the box/.test(await p.textContent('#signup-result')), 'consent required');
  await p.check('input[name="timing"][value="evening"]');
  await p.check('input[name="garden"]');
  await p.check('input[name="consent"]');
  await p.click('#signup-form .button');
  await p.waitForFunction(() => /Check your inbox/.test(document.getElementById('signup-result').textContent));
  await p.screenshot({ path: SHOTS + '/signup-done.png', fullPage: true });

  const confirm = mails().filter(m => m.subject === 'Confirm your BINS bin day reminders').pop();
  check(confirm && confirm.to === 'resident@example.org' && /Pelham Crescent/.test(confirm.text), 'confirmation email');
  fs.writeFileSync(SHOTS + '/confirm-email.html', confirm.html);

  console.log('- Confirm');
  await p.goto(link(confirm.text, 'confirm'));
  check(/Confirm your email address/.test(await p.textContent('h1')), 'button page, not instant');
  await p.click('form .button');
  check(/You’re subscribed/.test(await p.textContent('h1')), 'subscribed');
  check(/6pm the evening before/.test(await p.textContent('main')) && /Pelham Crescent/.test(await p.textContent('main')), 'says what to expect');

  console.log('- Reminder');
  const json = JSON.parse(fs.readFileSync(DIR + '/public/data/collections.json', 'utf8'));
  const next = json.streets['pelham-crescent'].next.find(d => d.day > new Date().toISOString().slice(0, 10));
  const eve = new Date(next.day + 'T12:00:00'); eve.setDate(eve.getDate() - 1);
  const at = eve.toISOString().slice(0, 10) + ' 18:05';
  php('bin/send-reminders.php', '--at=' + at);
  const reminder = mails().filter(m => /^Bins tomorrow/.test(m.subject)).pop();
  check(reminder && reminder.to === 'resident@example.org', 'reminder sent the evening before (' + at + ')');
  check(reminder && reminder.headers['List-Unsubscribe'], 'one-click unsubscribe header');
  fs.writeFileSync(SHOTS + '/reminder-email.html', reminder.html);
  php('bin/send-reminders.php', '--at=' + at.replace('18:05', '18:20'));
  check(mails().filter(m => /^Bins tomorrow/.test(m.subject)).length === 1, 'not sent twice');

  console.log('- Change reminders');
  await p.goto(link(reminder.text, 'manage'));
  check(/Your reminders/.test(await p.textContent('h1')), 'manage page');
  await p.check('input[name="timing"][value="morning"]');
  await p.selectOption('#m-street', 'harlaxton-drive');
  await p.click('form .button');
  check(/Saved.*Harlaxton Drive.*6:30am/.test(await p.textContent('.notice')), 'saved');
  await p.screenshot({ path: SHOTS + '/manage.png', fullPage: true });

  console.log('- Unsubscribe');
  await p.goto(link(reminder.text, 'unsubscribe'));
  check(/Unsubscribe from bin reminders/.test(await p.textContent('h1')), 'button page, not instant');
  await p.click('form .button');
  check(/You’ve unsubscribed/.test(await p.textContent('h1')), 'unsubscribed');

  console.log('- Phone width');
  const phone = await browser.newContext({ viewport: { width: 375, height: 812 }, deviceScaleFactor: 2 });
  const m = await phone.newPage();
  m.on('pageerror', e => errors.push(String(e)));
  await m.goto(BASE + '/?street=newcastle-drive');
  await m.waitForSelector('#upcoming .day');
  check(await m.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no sideways scrolling');
  await m.screenshot({ path: SHOTS + '/home-phone.png', fullPage: true });

  check(errors.length === 0, 'no script errors: ' + errors.join('; '));
  await browser.close();
  console.log(`\n${passes} checks passed, ${failures} failed. Screenshots in ${SHOTS}`);
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
