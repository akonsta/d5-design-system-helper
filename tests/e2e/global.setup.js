/*`*
 * Global setup - Handles WordPress login before tests run
 */

const { chromium } = require('@playwright/test');

// Configuration - Edit these values for your environment
const CONFIG = {
    siteUrl: 'http://dfdshtest.local',
    username: 'admin',
    password: 'admin',
};

async function globalSetup() {
    console.log('\n🔐 Logging into WordPress...');

    const browser = await chromium.launch();
    const page = await browser.newPage();

    try {
        // Navigate to WordPress login
        await page.goto(`${CONFIG.siteUrl}/wp-login.php`);

        // Fill in credentials
        await page.fill('#user_login', CONFIG.username);
        await page.fill('#user_pass', CONFIG.password);

        // Click login button
        await page.click('#wp-submit');

        // Wait for redirect to admin dashboard
        await page.waitForURL('**/wp-admin/**', { timeout: 10000 });

        console.log('✅ Login successful\n');

        // Save authentication state
        await page.context().storageState({ path: '.auth.json' });
    } catch (error) {
        console.error('❌ Login failed:', error.message);
        console.log('\nTroubleshooting:');
        console.log('  1. Check that WordPress is running at:', CONFIG.siteUrl);
        console.log('  2. Verify username/password in global.setup.js');
        console.log('  3. Ensure the user has admin access\n');
        throw error;
    } finally {
        await browser.close();
    }
}

module.exports = globalSetup;
