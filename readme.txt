=== Bit Audit ===
Contributors: bitapps
Tags: bit integrations, bit flows, audit, integrations, report
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.8
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

= 1.1.8 =
* CSV export no longer breaks when a report has no catalog. Exporting while a plugin is not installed, or is a built release without its frontend source, produced a sheet of blank cells and a PHP warning for every one; it now exports the reason the report could not be built.
* Exported CSV files no longer carry a PHP 8.4 deprecation notice in their contents, and a field ending in a backslash is written correctly.
* An unreadable directory inside a scanned plugin no longer takes down the audit page — it is skipped instead.

= 1.1.7 =
* Bit Audit now updates itself from its GitHub releases. A new version appears on the Plugins screen like any other update — one-click update and the auto-update toggle both work — so upgrading no longer means uploading a zip by hand.

= 1.1.6 =
* A platform that offers both a trigger and an action is no longer listed twice when the two catalogs word its name differently. Power Coupons for WooCommerce / PowerCoupons, ConvertForce Popup Builder / ConvertForce, LearnDash LMS / LearnDash, wpForo Forum / WPForo and Webba Booking Calendar / WebbaBooking each collapse into one Both row carrying its trigger and action events together; platform integrations 329 to 324, with every other count unchanged.

= 1.1.5 =
* Trigger events now carry their own Pro flag. A Free trigger module can gate individual events behind Pro, so WooCommerce's 27 events are reported as 14 Free and 13 Pro (Restore Product, Restore Order, Coupon Created or Updated, the seven Order Status events, Product Status Changed and the two cart events) instead of all Free, and the platform reads Both again.
* Per-operation Pro tiers an integration declares are no longer overwritten. SureContact marks all 42 of its operations Pro and Brilliant Directories all 11, while both still declare `is_pro: false` at the catalog level; the free-user reconciliation now applies only to tiers inferred from backend heuristics.
* Operation lists rendered through a custom dropdown rather than a `select` are now found, so MasterStudy LMS shows its real operation names and its four Pro operations.
* Trigger events split 112/920 to 99/933 Free/Pro; action events 440/430 to 383/487; action apps 162/48 to 160/50.

= 1.1.4 =
* Event catalogs are now read from the lists the Flow builder actually offers, correcting 55 platforms. Commented-out entries are no longer counted (WooCommerce dropped 6 deprecated Subscription/Booking events), labels containing an apostrophe are no longer truncated (Ultimate Member showed `User\`), and operation dropdowns are found under whatever name an integration gives them (GamiPress reported 5 transient cache keys as Pro actions; WooCommerce's 5 modules were guessed from the backend).
* Trigger lists forwarded to another method are now followed, so Voxel reports its 27 named events instead of 21 raw hook slugs; Mail Mint, WP Courseware and Fluent Booking gain their real event names.
* Event tiers now honour the catalogs' own trailing " Pro" label marker instead of leaving it in the name, correcting the Free/Pro split for BuddyBoss, LearnDash, WooCommerce, Hubspot, Freshdesk and SendPulse.

= 1.1.3 =
* Bit Integrations trigger events are now read from the module's own task list even when it is declared inside the controller rather than a `StaticData.php`, so Bit CRM reports its 66 named events instead of 58 raw hook names.
* Fixed two trigger task-list parsing faults: modules that list the hook before its label (Post) paired every event with the wrong hook, and labels containing an apostrophe (WooCommerce Memberships) were truncated. Nine modules' event counts are corrected and 69 more now show their real event names.

= 1.1.2 =
* Bit Integrations trigger names now come from each platform controller's `info()` metadata instead of the directory name, so abbreviated folders such as CF7, WC and WPF display as Contact Form 7, WooCommerce and WPForms.

= 1.1.1 =
* Bit Integrations action tiers now follow the Flow builder's own `is_pro` flag (an action is Pro only when every operation it offers is Pro), instead of re-deriving the tier from backend operation heuristics. Fixes six actions (ActiveCampaign, GetResponse, Keap, Salesmate, Zoho CRM, Zoho Recruit) that were over-counted as Pro: the Free/Pro action split is now 159 Free / 27 Pro.

= 1.1.0 =
* Reads each plugin's catalog from a local source checkout, and only audits plugins that are actually installed (a fresh site no longer shows a phantom catalog).
* Shows a clear notice when a plugin isn't installed or ships without its frontend source (a built/minified release).

= 1.0.0 =
* Initial release: Bit Integrations and Bit Flows audit dashboard with per-event detail, Free/Pro/Both tiers, JSON/CSV export and cached reports.
