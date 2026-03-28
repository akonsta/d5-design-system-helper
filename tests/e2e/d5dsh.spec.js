/**
 * D5 Design System Helper — Full Screenshot Capture
 */

const { test } = require('@playwright/test');

const SITE = 'http://dfdshtest.local';
const DIR = 'screenshots';
let n = 0;

const snap = async (page, name) => {
    n++;
    await page.screenshot({ path: `${DIR}/${String(n).padStart(2, '0')}-${name}.png` });
    console.log(`📸 ${n}: ${name}`);
};

// Safe click - won't fail if element not found
const safeClick = async (page, selector) => {
    try {
        await page.locator(selector).first().click({ timeout: 2000 });
        return true;
    } catch {
        return false;
    }
};

test('screenshots', async ({ browser }) => {
    test.setTimeout(180000);

    const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
    const page = await context.newPage();

    // Login
    await page.goto(`${SITE}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');
    await page.waitForTimeout(2000);

    const ts = Date.now();

    // === MANAGE TAB ===
    console.log('\n--- MANAGE TAB ---');
    await page.goto(`${SITE}/wp-admin/admin.php?page=d5dsh-design-tool&tab=manage&_=${ts}`);
    await page.waitForTimeout(1500);
    await snap(page, 'manage-variables');

    // Click Group Presets
    if (await safeClick(page, 'button[data-section="group_presets"]')) {
        await page.waitForTimeout(800);
        await snap(page, 'manage-group-presets');
    }

    // Click Element Presets
    if (await safeClick(page, 'button[data-section="element_presets"]')) {
        await page.waitForTimeout(800);
        await snap(page, 'manage-element-presets');
    }

    // Click All Presets
    if (await safeClick(page, 'button[data-section="all_presets"]')) {
        await page.waitForTimeout(800);
        await snap(page, 'manage-all-presets');
    }

    // === EXPORT TAB ===
    console.log('\n--- EXPORT TAB ---');
    await page.goto(`${SITE}/wp-admin/admin.php?page=d5dsh-design-tool&tab=export&_=${ts}`);
    await page.waitForTimeout(1500);
    await snap(page, 'export-tab');

    // === IMPORT TAB ===
    console.log('\n--- IMPORT TAB ---');
    await page.goto(`${SITE}/wp-admin/admin.php?page=d5dsh-design-tool&tab=import&_=${ts}`);
    await page.waitForTimeout(1500);
    await snap(page, 'import-tab');

    // === SNAPSHOTS TAB ===
    console.log('\n--- SNAPSHOTS TAB ---');
    await page.goto(`${SITE}/wp-admin/admin.php?page=d5dsh-design-tool&tab=snapshots&_=${ts}`);
    await page.waitForTimeout(1500);
    await snap(page, 'snapshots-tab');

    // === MODALS ===
    console.log('\n--- MODALS ---');
    await page.goto(`${SITE}/wp-admin/admin.php?page=d5dsh-design-tool&tab=manage&_=${ts}`);
    await page.waitForTimeout(1500);

    // Settings modal
    if (await safeClick(page, '#d5dsh-btn-settings')) {
        await page.waitForTimeout(500);
        await snap(page, 'modal-settings-general');

        // Settings - Appearance tab
        if (await safeClick(page, '.d5dsh-modal-tab[data-tab="appearance"]')) {
            await page.waitForTimeout(300);
            await snap(page, 'modal-settings-appearance');
        }

        // Settings - About tab
        if (await safeClick(page, '.d5dsh-modal-tab[data-tab="about"]')) {
            await page.waitForTimeout(300);
            await snap(page, 'modal-settings-about');
        }

        // Close settings - use specific selector
        await safeClick(page, '.d5dsh-modal-close[data-modal="d5dsh-settings-modal"]');
        await page.waitForTimeout(300);
    }

    // Help panel
    if (await safeClick(page, '#d5dsh-btn-help')) {
        await page.waitForTimeout(500);
        await snap(page, 'help-panel');
        await safeClick(page, '#d5dsh-help-close');
        await page.waitForTimeout(300);
    }

    // Contact modal
    if (await safeClick(page, '#d5dsh-btn-contact')) {
        await page.waitForTimeout(500);
        await snap(page, 'modal-contact');
        await safeClick(page, '.d5dsh-modal-close[data-modal="d5dsh-contact-modal"]');
        await page.waitForTimeout(300);
    }

    // Print modal - need to go back to Variables section first
    await safeClick(page, 'button[data-section="variables"]');
    await page.waitForTimeout(500);

    if (await safeClick(page, '#d5dsh-manage-print')) {
        await page.waitForTimeout(500);
        await snap(page, 'modal-print');
        await safeClick(page, '#d5dsh-print-cancel');
    }

    await context.close();
    console.log(`\n✅ Done! ${n} screenshots captured.`);
});
