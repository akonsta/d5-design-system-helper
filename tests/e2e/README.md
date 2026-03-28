# D5 Design System Helper — E2E Tests

Automated end-to-end tests using [Playwright](https://playwright.dev/).

## Prerequisites

1. **Local WordPress site** with the D5 Design System Helper plugin installed and activated
2. **Divi 5 theme** active (required for the plugin to function)
3. **Node.js 18+** installed

## Quick Start

```bash
# Navigate to the e2e test directory
cd tests/e2e

# Install dependencies
npm install

# Install Playwright browsers (first time only)
npx playwright install chromium

# Run tests
npm test
```

## Configuration

Edit the credentials in two files:

### `global.setup.js`
```javascript
const CONFIG = {
    siteUrl: 'http://localhost:8888',  // Your WordPress URL
    username: 'admin',                   // Admin username
    password: 'admin',                   // Admin password
};
```

### `d5dsh.spec.js`
```javascript
const CONFIG = {
    siteUrl: 'http://localhost:8888',  // Your WordPress URL
    username: 'admin',                   // Admin username
    password: 'admin',                   // Admin password
    screenshotDir: 'tests/e2e/screenshots',
    viewport: { width: 1400, height: 900 },
};
```

## Running Tests

```bash
# Run all tests (headless)
npm test

# Run tests with visible browser
npm run test:headed

# Interactive UI mode (recommended for debugging)
npm run test:ui

# Debug mode (step through tests)
npm run test:debug

# View HTML report after tests
npm run report
```

## Test Coverage

The test suite covers:

| Test | Description |
|------|-------------|
| **Navigation** | Plugin page loads, all tabs visible |
| **Header buttons** | Help, Settings, Contact buttons work |
| **Manage tab** | Variables, Group Presets, Element Presets, All Presets sections |
| **Import tab** | File upload area visible |
| **Snapshots tab** | Snapshot list and controls |
| **Audit tab** | Audit interface loads |
| **Settings modal** | Opens, tabs switch, closes |
| **Help panel** | Opens and closes |
| **Contact modal** | Opens and closes |
| **Print modal** | Opens and closes |
| **Table interactions** | Type filter, search, bulk mode |
| **JavaScript errors** | Checks console for errors |

## Screenshots

Screenshots are saved to `tests/e2e/screenshots/`:

```
screenshots/
├── 01-plugin-main-page.png
├── 02-header-buttons.png
├── 03-manage-tab-variables.png
├── 04-manage-tab-group-presets.png
├── 05-manage-tab-element-presets.png
├── ...
└── 18-fullpage-audit.png
```

## Troubleshooting

### "Login failed" error
1. Check WordPress is running at the configured URL
2. Verify admin credentials in `global.setup.js`
3. Try logging in manually first

### "Timeout waiting for selector" error
1. Plugin may not be activated
2. Divi 5 may not be active (plugin requires it)
3. Check browser console for JavaScript errors

### Tests pass but screenshots are blank
1. Increase viewport size in config
2. Add `await page.waitForTimeout(1000)` before screenshots

## Adding New Tests

```javascript
test('My new test', async ({ page }) => {
    await page.goto(`${CONFIG.siteUrl}/wp-admin/admin.php?page=d5dsh-design-tool`);
    await waitForAjax(page);

    // Your test assertions
    await expect(page.locator('.my-element')).toBeVisible();

    // Take screenshot
    await screenshot(page, 'my-test');
});
```

## Visual Regression Testing

The final test creates a baseline screenshot. On subsequent runs, it compares against the baseline:

```bash
# Update baseline after intentional UI changes
npx playwright test --update-snapshots
```

## CI/CD Integration

For GitHub Actions, create `.github/workflows/e2e.yml`:

```yaml
name: E2E Tests
on: [push]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: 18
      - name: Install dependencies
        run: cd tests/e2e && npm ci
      - name: Install Playwright
        run: cd tests/e2e && npx playwright install --with-deps chromium
      - name: Run tests
        run: cd tests/e2e && npm test
      - uses: actions/upload-artifact@v4
        if: always()
        with:
          name: playwright-report
          path: tests/e2e/playwright-report/
```

Note: CI requires a running WordPress instance. Consider using `wp-env` or Docker.
