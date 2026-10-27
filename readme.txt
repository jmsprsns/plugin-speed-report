=== Plugin Speed Report ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: performance, speed, plugins, profiler, page speed
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find out which plugin is slowing down your site, in plain English. Measures each plugin's server time, database queries and CSS/JS weight, suggests fixes, and compares before and after.

== Description ==

"Which plugin is slowing down my site?" Plugin Speed Report answers that in plain English, for site owners rather than developers. It fills the gap left by P3 (Plugin Performance Profiler), which no longer works on modern PHP.

It loads your key pages in the background, the way a logged-out visitor sees them, and measures every plugin on each one:

* **Server time**: how long each plugin's own code runs, including the time to load it. Time a plugin spends inside another plugin's hook counts for the inner one, so nothing is counted twice.
* **Database queries**: counted against the plugin that ran them.
* **CSS and JavaScript**: every stylesheet and script on the page, weighed file by file (and compressed), including WordPress files like jQuery that are only there because one plugin needs them.

Then it ranks your plugins with a sentence you can act on:

> WooCommerce: 0.40 s and 180 KB on every page, including 3 pages that don't use it.

= Suggested fixes =

For each plugin it suggests specific fixes, such as:

* **Only load its CSS and JS where it's used.** Many plugins add their files to every page even when only one page uses them (a contact form, a slider, a shop). Turn the fix on with one click and the files load only on pages that use the plugin: pages the scan saw it used, its own page types, and any page with its blocks or shortcodes, including ones you add later. Preview a page without the files first, and turn the fix off any time.
* Heavy server time, lots of database queries, render-blocking scripts, plugins that weren't seen doing anything, and pending updates, each with a plain-English next step.

= Before and after =

Every scan is saved. The Before & after tab compares any two scans plugin by plugin, so you can see exactly what a fix, an update or a deactivated plugin changed.

= Safe on live sites =

* Measuring only happens on the scan's own background page loads, which carry a single-use, 60-second token. Your visitors' page views are never timed or slowed.
* Page caches are skipped for those loads, so you see the real cost of building each page.
* A tiny helper in `wp-content/mu-plugins` starts the timer before other plugins load. It's added and removed automatically, and does nothing on normal page views.
* Only administrators can run scans or change fixes.

Find it under Content Powered > Plugin Speed Report in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `plugin-speed-report` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > Plugin Speed Report and click "Run your first scan".

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Will scanning slow down my site for visitors? =

No. A scan loads a handful of pages in the background, one at a time, a few times each. Only those page loads are measured; everyone else's are untouched.

= Why do the numbers change a little between scans? =

Servers are busier at some moments than others. Each page is loaded several times (three by default) and the middle result is kept, which smooths out most of it. Differences of a few hundredths of a second are normal.

= Does it work with page caching? =

Yes. Scans skip the cache so they can measure the real work, which logged-in users, carts and first visits still pay for. If a cache in front of WordPress (at your host or CDN) still serves a stored copy, the scan says so.

= Which pages does it test? =

One of each kind: your home page, a post, a page, your shop and products, an archive, and so on, picked from your own content. You can untick any and add your own (a contact page, for example) under Pages.

== Changelog ==

= 1.0.0 =
* First release.

See https://github.com/jmsprsns/plugin-speed-report/releases for the full release history.
