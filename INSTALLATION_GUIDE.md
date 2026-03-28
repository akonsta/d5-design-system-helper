# D5 Design System Helper — Installation Guide

| Version | Date | Notes |
|---------|------|-------|
| 0.1.0 | 2026-03-27 | Initial public release |

For web designers and developers using Divi 5 on WordPress.

---

## 1. Requirements

Before installing, confirm your site meets the following requirements. All three are mandatory — the plugin will refuse to activate if any are missing.

| Requirement | Minimum Version |
|---|---|
| WordPress | 6.2 |
| PHP | 8.1 |
| Divi | 5.0 |

**Important:** This plugin works exclusively with Divi 5. It does not support Divi 4.x or earlier. If your site runs Divi 4, the plugin will not activate.

**ZipArchive (PHP extension):** Multi-file exports are packaged as a `.zip` bundle. This requires a PHP extension called ZipArchive. The vast majority of web hosts enable it by default. If yours does not, see the Troubleshooting section at the end of this guide.

**No extra software needed:** PhpSpreadsheet (the library used to generate Excel files) is bundled inside the plugin zip. You do not need to install Composer or any other tools.

---

## 2. Downloading the Plugin

The plugin is not listed in the WordPress plugin directory. You download it directly from GitHub.

1. Open your browser and go to: https://github.com/akonsta/d5-design-system-helper
2. Click the **Releases** link on the right side of the page (under "About").
3. On the Releases page, find the latest release at the top.
4. Under "Assets", click the file named `d5-design-system-helper-vX.X.X.zip` (where X.X.X is the version number) to download it.
5. Save the zip file to your computer. Do not unzip it — WordPress installs the file as a zip.

---

## 3. Installing via WordPress Admin

1. Log in to your WordPress admin dashboard.
2. In the left-hand menu, go to **Plugins → Add New**.
3. Near the top of the page, click the **Upload Plugin** button.
4. Click **Choose File** (or **Browse**) and select the zip file you downloaded in the previous section.
5. Click **Install Now**. WordPress will upload and unpack the file. You will see a progress screen.
6. Once installation is complete, click **Activate Plugin**.

The plugin is now installed and active.

---

## 4. Verifying Installation

After activation, confirm the plugin is working correctly:

1. Look at the left-hand admin menu. If Divi is installed and active, you should see a new item under the **Divi** menu group called **Design System Helper**.
2. If the Divi menu is not visible (this can happen on some configurations), check under **Tools → Design System Helper** instead.
3. Click the menu item. The Design System Helper page should load, showing the **Manage**, **Export**, and **Import** tabs. With Beta Preview enabled, three additional tabs appear: **Analysis**, **Style Guide**, and **Snapshots**.

If the page loads without errors, the installation is complete and the plugin is ready to use.

---

## 5. First Steps After Installation

No configuration is required. The plugin reads your design data directly from the Divi database as soon as it is activated.

A few things worth doing on your first visit:

- **Browse the Export tab.** This shows all the design data types available: Global Variables, Module Presets, Layouts, Pages, Theme Customizer settings, and Builder Templates. Nothing happens until you click Export — browsing is safe.
- **Review the import analysis.** If you plan to use the Import feature, upload a file and review the preliminary analysis panel before clicking Import. The analysis shows exactly what will be added, what will be updated, and any dependency warnings. Nothing is written until you confirm.
- **Check the Snapshots tab.** The plugin saves an automatic snapshot of your data before every import and export. The Snapshots tab lets you view and restore any of these if you ever need to roll back.
- **Enable Beta Preview.** Click the gear icon (⚙) in the top-right corner and check **Enable Beta Preview** in the Advanced tab. This unlocks the Analysis, Style Guide, and Snapshots tabs, plus features like Bulk Label Change, Merge Variables, and Categories. Beta features are fully functional.

The plugin is designed to be non-destructive. It never deletes Divi data and never makes changes unless you explicitly confirm an import after reviewing the analysis.

---

## 6. Troubleshooting Common Issues

### Activation error mentioning PHPUnit or a vendor directory

If activation fails with a message referencing PHPUnit, autoloader, or a missing vendor directory, you likely downloaded the raw source code from GitHub rather than the packaged release zip.

**Fix:** Go back to the Releases page on GitHub (not the main repository page) and download the release zip file, which has a filename like `d5-design-system-helper-vX.X.X.zip`. This file includes the pre-built vendor folder. The main repository zip (downloaded via the green "Code" button) does not include it and is intended for developers only.

### The plugin menu is not visible after activation

The Design System Helper menu appears under **Divi → Design System Helper** when the Divi theme or Divi Builder plugin is active. If that menu item is missing:

- First check under **Tools → Design System Helper** — this is the automatic fallback location.
- If it is not there either, go to **Plugins** and confirm the plugin shows as "Active". If it is deactivated, try activating it again and watch for any error messages.

### ZipArchive missing — cannot export multiple file types

If you attempt to export more than one data type at a time and receive an error about ZipArchive, the PHP extension is not enabled on your server.

**What to try:**
- Contact your web host and ask them to enable the `zip` PHP extension (it is a standard extension and most hosts enable it without charge).
- As a workaround, export one data type at a time — individual exports download as a single `.xlsx` or `.json` file and do not require ZipArchive.

### Divi version mismatch — plugin will not activate

If you see an error stating that Divi 5.0 or higher is required, your site is running an older version of Divi.

This plugin only supports Divi 5. If your site uses Divi 4.x, you will need to upgrade Divi before using this plugin. The two versions store design data in different ways, and the plugin cannot read Divi 4 data.

---

## 7. Uninstalling

To remove the plugin from your site:

1. Go to **Plugins** in the WordPress admin menu.
2. Find **D5 Design System Helper** in the list.
3. Click **Deactivate**, then click **Delete**.
4. Confirm the deletion when prompted.

**What gets removed:** The plugin deletes its own database entries when uninstalled — this includes any snapshots and temporary data it created. No Divi data is touched. Your colors, fonts, presets, layouts, and all other Divi design settings remain completely intact.
