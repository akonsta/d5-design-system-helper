// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Playwright configuration for D5 Design System Helper E2E tests
 */
module.exports = defineConfig({
    testDir: '.',
    testMatch: '*.spec.js',

    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,

    reporter: [
        ['html', { outputFolder: 'playwright-report' }],
        ['list'],
    ],

    use: {
        baseURL: 'http://dfdshtest.local',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        viewport: { width: 1400, height: 900 },
    },

    outputDir: 'screenshots',
    timeout: 60000,

    expect: {
        timeout: 10000,
    },
});
