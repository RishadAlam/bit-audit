=== Bit Audit ===
Contributors: bitapps
Tags: bit integrations, bit flows, audit, integrations, report
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit the Bit ecosystem — count integrations, triggers and actions, and inspect every event with Free/Pro/Both tiers.

== Description ==

Bit Audit is an internal, admin-only dashboard that audits the Bit ecosystem by reading each plugin's catalog from a locally installed source checkout (a built/minified release strips the frontend source it needs):

* Pick a plugin — **Bit Integrations** or **Bit Flows** — and Bit Audit combines its Free + Pro sides into one report.
* See **Total Integrations** (triggers + actions), **Platform Integrations** (unique apps), **Triggers**, **Actions**, and **Trigger / Action Events**, each split Free vs Pro.
* Browse a searchable, sortable per-integration table — Type (Trigger / Action / Both) and Tier (Free / Pro / Both) — and click any row to see every event with its name, hook and tier.
* "Latest integrations" reads the plugin changelog and links each new trigger/action straight to its detail page.
* Export the full report as JSON or CSV.

Counts come from the plugins' own catalogs (the Flow builder lists and machine roots) plus the local backend for event detail, so they match what the products actually offer. Reports are cached; use **Refresh** to re-read after the source changes.

Bit Audit is read-only. It stores no settings and creates no tables — only short-lived report caches, which are removed on uninstall.

== Installation ==

1. Install the audited plugins (Bit Integrations / Bit Flows) as SOURCE checkouts via `git clone` into `/wp-content/plugins/`, so their `frontend/src` is present. They need not be active. A built/minified release will not work.
2. Upload the `bit-audit` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload, and activate **Bit Audit**.
3. Open **Bit Audit** in the admin menu.

For development from a checkout, run `composer install` to generate the autoloader.

== Frequently Asked Questions ==

= Does it change anything in my integrations? =
No. Bit Audit only reads the plugin's source and renders a report. It never writes to your flows or integrations.

= Why does it say a plugin could not load? =
It reads the plugin's frontend source from disk. If the plugin isn't installed, or it is a built/minified release (no `frontend/src`), the catalog can't be built — install it as a `git clone` source checkout.

= Why don't the numbers update after I edit a plugin? =
The report is cached. Click **Refresh** to rebuild.

== Changelog ==

= 1.1.2 =
* Bit Integrations trigger names now come from each platform controller's `info()` metadata instead of the directory name, so abbreviated folders such as CF7, WC and WPF display as Contact Form 7, WooCommerce and WPForms.

= 1.1.1 =
* Bit Integrations action tiers now follow the Flow builder's own `is_pro` flag (an action is Pro only when every operation it offers is Pro), instead of re-deriving the tier from backend operation heuristics. Fixes six actions (ActiveCampaign, GetResponse, Keap, Salesmate, Zoho CRM, Zoho Recruit) that were over-counted as Pro: the Free/Pro action split is now 159 Free / 27 Pro.

= 1.1.0 =
* Reads each plugin's catalog from a local source checkout, and only audits plugins that are actually installed (a fresh site no longer shows a phantom catalog).
* Shows a clear notice when a plugin isn't installed or ships without its frontend source (a built/minified release).

= 1.0.0 =
* Initial release: Bit Integrations and Bit Flows audit dashboard with per-event detail, Free/Pro/Both tiers, JSON/CSV export and cached reports.
