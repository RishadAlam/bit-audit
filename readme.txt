=== Bit Audit ===
Contributors: bitapps
Tags: bit integrations, bit flows, audit, integrations, report
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit the Bit ecosystem — count integrations, triggers and actions, and inspect every event with Free/Pro/Both tiers.

== Description ==

Bit Audit is an admin-only dashboard that audits the Bit ecosystem from the source it ships:

* Pick a plugin — **Bit Integrations** or **Bit Flows** — and Bit Audit combines its Free + Pro sides into one report.
* See **Total Integrations** (triggers + actions), **Platform Integrations** (unique apps), **Triggers**, **Actions**, and **Trigger / Action Events**, each split Free vs Pro.
* Browse a searchable, sortable per-integration table — Type (Trigger / Action / Both) and Tier (Free / Pro / Both) — and click any row to see every event with its name, hook and tier.
* "Latest integrations" reads the plugin changelog and links each new trigger/action straight to its detail page.
* Export the full report as JSON or CSV.

Counts are sourced from the plugins' own registries (the Flow builder lists and machine catalogs), so they match what the products actually offer. Reports are cached; use **Refresh** to rebuild after the source plugins change.

Bit Audit is read-only. It stores no settings and creates no tables — only short-lived report caches, which are removed on uninstall.

== Installation ==

1. Upload the `bit-audit` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate **Bit Audit** through the Plugins screen.
3. Open **Bit Audit** in the admin menu.

For development from a checkout, run `composer install` to generate the autoloader.

== Frequently Asked Questions ==

= Does it change anything in my integrations? =
No. Bit Audit only reads source files and renders a report. It never writes to your flows or integrations.

= Why don't the numbers update after I edit a plugin? =
The report is cached for performance. Click **Refresh** to rebuild it.

== Changelog ==

= 1.0.0 =
* Initial release: Bit Integrations and Bit Flows audit dashboard with per-event detail, Free/Pro/Both tiers, JSON/CSV export and cached reports.
