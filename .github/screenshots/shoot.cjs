// Temporary: logs in with demo users and captures the main screens (see screenshots.yml).
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = 'http://127.0.0.1:8000';
const OUT = 'shots';
fs.mkdirSync(OUT, { recursive: true });
const errors = [];

function codeFor(mobile) {
    const log = fs.readFileSync('storage/logs/laravel.log', 'utf8');
    const matches = [...log.matchAll(new RegExp(`for ${mobile}: (\\d+)`, 'g'))];
    return matches.length ? matches[matches.length - 1][1] : null;
}

async function login(page, mobile) {
    await page.goto(`${BASE}/login`);
    await page.fill('#mobile', mobile);
    await page.getByRole('button', { name: 'دریافت کد پیامکی' }).click();
    await page.waitForSelector('#code');
    await page.fill('#code', codeFor(mobile));
    await page.getByRole('button', { name: 'ورود' }).click();
    await page.waitForURL(`${BASE}/`);
}

async function shot(page, name, options = {}) {
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/${name}.png`, ...options });
    console.log('captured', name);
}

(async () => {
    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fa-IR' });
    const page = await context.newPage();
    page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
    page.on('console', (m) => m.type() === 'error' && errors.push('console: ' + m.text()));

    try {
        await page.goto(`${BASE}/login`);
        await shot(page, '01-login');

        await login(page, '09120000000');
        await shot(page, '02-dashboard');

        await page.getByRole('link', { name: 'باز کردن شیت ماه' }).click();
        await page.waitForSelector('table.sheet');
        await shot(page, '03-grid');

        // Fill handle: type in an overtime cell and drag down four rows.
        const overtime = page.locator('input[data-col]').nth(1);
        const col = await overtime.getAttribute('data-col');
        await overtime.click();
        await page.waitForTimeout(100);
        await page.keyboard.type('12');
        const handle = await page.locator('.fill-handle').boundingBox();
        const target = await page.locator(`input[data-col="${col}"][data-r="4"]`).boundingBox();
        await page.mouse.move(handle.x + 4, handle.y + 4);
        await page.mouse.down();
        await page.mouse.move(handle.x + 4, target.y + target.height / 2, { steps: 8 });
        await shot(page, '04-fill-drag', { clip: { x: 0, y: 56, width: 1440, height: 420 } });
        await page.mouse.up();
        await page.waitForTimeout(1500);
        await shot(page, '05-fill-done');

        // Out-of-range value is refused.
        const workdays = page.locator('input[data-col]').nth(0);
        await workdays.click();
        await page.waitForTimeout(100);
        await page.keyboard.type('45');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(1500);
        await shot(page, '06-range-error', { clip: { x: 0, y: 56, width: 1440, height: 420 } });
        await page.keyboard.press('Escape');

        await page.getByRole('button', { name: /تنظیمات ستون کارکرد/ }).click();
        await page.waitForSelector('#column-min');
        await shot(page, '07-column-dialog');
        await page.getByRole('button', { name: 'انصراف' }).click();

        await page.getByRole('button', { name: /یادداشت‌های/ }).first().click();
        await page.fill('#new-note', 'کارکرد این ماه با گزارش تردد چک شود.');
        await page.getByRole('button', { name: 'ثبت یادداشت' }).click();
        await page.waitForTimeout(800);
        await shot(page, '08-notes');
        await page.keyboard.press('Escape');

        await page.goto(`${BASE}/members`);
        await shot(page, '09-members', { fullPage: true });

        await page.goto(`${BASE}/projects`);
        await shot(page, '10-projects');

        const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
        const phone = await mobile.newPage();
        await login(phone, '09120000004');
        await shot(phone, '11-dashboard-mobile', { fullPage: true });

        const editorContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const editor = await editorContext.newPage();
        await login(editor, '09120000001');
        await editor.getByRole('link', { name: 'باز کردن شیت ماه' }).click();
        await editor.waitForSelector('table.sheet');
        await shot(editor, '12-grid-editor');
    } catch (e) {
        errors.push('script: ' + e.message);
        await page.screenshot({ path: `${OUT}/99-failure.png`, fullPage: true }).catch(() => {});
    }

    fs.writeFileSync(`${OUT}/errors.txt`, errors.join('\n') || 'no errors');
    console.log(errors.join('\n') || 'no browser errors');
    await browser.close();
})();
