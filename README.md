# Plugin Speed Report

A plain-English answer to "which plugin is slowing down my site?", built for site owners rather than developers. It fills the gap left by P3 (Plugin Performance Profiler).

It loads your key pages in the background and measures each plugin's server time, database queries, and CSS and JavaScript weight, then ranks them with a sentence like *"WooCommerce: 0.40 s and 180 KB on every page, including 3 pages that don't use it."* It suggests specific fixes (with a one-click "only load its CSS and JS where it's used"), and compares scans before and after.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## How it measures

A scan is a series of AJAX steps from Content Powered > Plugin Speed Report. Each step makes one loopback request (`wp_remote_get`) to a page on the site as a logged-out visitor, with `?psr_profile=<token>`. The first load of each page is a warm-up; the next ones (3 by default) are kept and the median is used.

- **The token** is 32 random characters. Only its SHA-256 hash is stored, it expires after 60 seconds, and the first request to use it deletes it, so it works once. Requests without a valid token are ordinary page views.
- **The profiler** (`includes/class-plugin-speed-report-profiler.php`) runs only on that request. It wraps every hook callback (through the `all` hook), shortcode handler, block `render_callback` and autoloader in a timer, and works out each callback's owner (plugin folder, must-use plugin, theme or core) from its file by reflection. Time is *exclusive*: a plugin's time inside another plugin's hook counts for the inner one. Plugin include times come from `plugin_loaded` (and `extra_plugin_headers`, which WordPress 6.7+ fires just before each include). The timers' own overhead is calibrated at the start and subtracted.
- **Queries** are counted against the owner running at the time (`log_query_custom_data`, with `SAVEQUERIES` defined for that request).
- **Used or not**: a plugin counts as used on a page when it rendered a shortcode or block, printed visible markup (or a real script of its own), changed the content, or the page is one of its post types or taxonomies.
- **Results** are written as JSON straight to an option row (`plugin_speed_report_sample`) at shutdown. The scanner reads and deletes it, then weighs every `<script>`/`<link rel=stylesheet>` in the returned HTML by mapping URLs to files on disk (realpath-checked, .css/.js only).
- **The must-use loader** (`wp-content/mu-plugins/0-plugin-speed-report.php`) starts the profiler before regular plugins load, so their load times count too. It's written on activation (and re-checked when the admin page loads), removed on deactivation and uninstall, and skipped when `DISALLOW_FILE_MODS` is set. Without it, plugins that load before this one are only partly timed, and the report says so.

## Asset fixes

`includes/class-plugin-speed-report-rules.php` is the only code that runs for visitors, and only when a fix exists. For each plugin with a fix, it dequeues that plugin's own registered files (by the `src` path under its folder) on pages that don't use it. A page uses it when the scan saw it used there, it's one of the plugin's public post types or taxonomies, its content has the plugin's shortcodes or blocks (or a synced pattern), one of its blocks or shortcodes already rendered, or the admin listed the page. Fixes never run in wp-admin, AJAX, REST, feeds, the Customizer or page-builder editors. Admins can preview a page without a plugin's files with a nonce'd `?psr_preview=` link.

## Automatic updates

Updates come from https://github.com/jmsprsns/plugin-speed-report through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `plugin-speed-report.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `plugin-speed-report.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'PLUGIN_SPEED_REPORT_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'PLUGIN_SPEED_REPORT_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
plugin-speed-report.php                         Plugin header, constants, boot, (de)activation
uninstall.php                                   Deletes settings, scans, fixes and the loader
readme.txt                                      WordPress readme; feeds the "View details" popup
includes/
  class-plugin-speed-report-plugin.php          Main singleton, settings, saved scans, fixes, path sanitizer
  class-plugin-speed-report-profiler.php        Per-plugin timer for a scan's own requests (stands alone)
  class-plugin-speed-report-loader.php          Installs and removes the must-use loader
  class-plugin-speed-report-scanner.php         Runs scans: loopbacks, HTML weighing, saving
  class-plugin-speed-report-report.php          Averages, sentences, suggestions, comparisons
  class-plugin-speed-report-rules.php           Front-end asset fixes and admin previews
  class-plugin-speed-report-admin.php           Report, Before & after, Pages and Fixes tabs; AJAX and form handlers
  class-plugin-speed-report-menu.php            Shared top-level "Content Powered" menu
  class-plugin-speed-report-feedback.php        Shared Submit Feedback and Report a Bug pages
  class-plugin-speed-report-updater.php         GitHub update checks (PUC wiring)
  class-plugin-speed-report-icons.php           Inline Lucide icon helper
assets/
  admin.css, admin.js                           Loaded only on the plugin's page
  icon.svg                                      Icon on the update screens
  content-powered.svg                           Sumo logo for the Content Powered menu
icons/                                          The Lucide icons the plugin uses (plus LICENSE)
plugin-update-checker/                          Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-plugin-speed-report-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-plugin-speed-report-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons it uses in `icons/`. Print one inline with:

```php
echo Plugin_Speed_Report_Icons::get( 'package', 20 );           // name, size in px
echo Plugin_Speed_Report_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
