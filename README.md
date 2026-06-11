# Bit Audit

> A read‑only WordPress admin dashboard that audits the **Bit ecosystem** — counts every integration, trigger and action, and lets you inspect each event with its Free / Pro / Both tier.

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb4.svg)
![WordPress](https://img.shields.io/badge/WordPress-%3E%3D5.6-21759b.svg)

Bit Audit answers a simple question for the [Bit Apps](https://bitapps.pro) plugins: **exactly what does each plugin ship?** Pick a plugin, and Bit Audit combines its Free + Pro sides into one report — total/platform integrations, triggers, actions, and the full per‑event breakdown — sourced from the plugins' own catalogs so the numbers match what the products actually offer.

It audits two plugins:

| Plugin | Source of truth |
| --- | --- |
| **Bit Integrations** | the Flow builder's `trigger/list` registry + `SelectAction.jsx`, backend `RecordApiHelper`/Controller operations, and frontend module labels |
| **Bit Flows** | each app's `_<app>Machines.ts` root machine (`triggers[]` / `actions[]`) |

Bit Audit is **read‑only**: it never writes to your flows or integrations, stores no settings, and creates no tables — only a short‑lived report cache that is removed on uninstall.

---

## Features

- **One report per plugin** — Free + Pro combined.
- **Headline metrics**, each split Free vs Pro:
  - **Total Integrations** (triggers + actions) and **Platform Integrations** (unique apps)
  - **Triggers** and **Actions** (app counts)
  - **Trigger Events** and **Action Events**
- **Per‑integration table** — searchable, with **Type** (Trigger / Action / Both) and **Tier** (Free / Pro / Both). Click any row for the full event list.
- **Event detail** — every trigger event (name + WordPress hook) and every action operation, with its tier. Dynamically‑registered events surface as a single **"Dynamic Event"**.
- **Latest integrations** — reads the plugin changelog and links each newly added trigger/action straight to its detail page.
- **Export** — full report as **JSON** or **CSV**.
- **Cached** for performance, with a **Refresh** button to rebuild after the source plugins change.
- Instant first paint, async plugin switch with a skeleton loader, count‑up animations, accessible tab pattern, full i18n.

> **Internal tool.** Bit Audit reads each plugin's catalog from a **locally installed source checkout** (`git clone`). A built/minified release strips the frontend source it needs, so install the audited plugins from source. No tokens, no network. Meant for Bit Apps team sites.

## Requirements

- WordPress **5.6+**
- PHP **7.4+**
- A **source checkout** of the plugins you want to audit (Bit Integrations and/or Bit Flows) in `wp-content/plugins/` — i.e. `git clone`, so the `frontend/src/…` source is present (a built/minified release won't work). They do **not** need to be active, only installed.

## Installation

### From a release zip
1. Download the latest `bit-audit.zip`.
2. **Plugins → Add New → Upload Plugin**, choose the zip, **Install** and **Activate**.
3. Open **Bit Audit** in the admin menu.

### Manually
1. Copy the `bit-audit` folder into `wp-content/plugins/`.
2. Activate **Bit Audit** on the Plugins screen.

### From source (for development)
```bash
git clone https://github.com/RishadAlam/bit-audit.git wp-content/plugins/bit-audit
cd wp-content/plugins/bit-audit
composer install            # generates the PSR-4 autoloader + dev tooling
```
The plugin runs even without `composer install` — it falls back to a built‑in PSR‑4 loader.

## Usage

1. Open **Bit Audit** from the admin menu.
2. Use the tabs to switch between **Bit Integrations** and **Bit Flows** — the report loads asynchronously.
3. Read the Overview cards, filter the per‑integration table, and click any row to drill into its events.
4. **Refresh** rebuilds the cached report after you update a source plugin. **JSON** / **CSV** export the current report.

## How it works

Everything is read from the locally installed plugin's source — install the audited plugins via `git clone` so the frontend is present:

- **Bit Integrations triggers (173):** the `AllTriggersName` Pro catalog + Free trigger controllers exposing `info()`. Trigger events come from each `{platform}/get` callback (`StaticData::tasks()`).
- **Bit Integrations actions (186):** the `integs[]` list in `SelectAction.jsx` plus each integration's `modules` list (operation labels + tiers); operations fall back to the backend (`RecordApiHelper`/Controller) when an integration declares no frontend modules.
- **Bit Flows (294 apps):** each `_<app>Machines.ts` root machine (`triggers[]` / `actions[]`, `label`, `machineSlug`, `isPro`); per‑trigger WP hooks come from the backend `Hooks.php`.

Reports are cached in a transient; **Refresh** rebuilds after the source changes.

**Tiers.** `Free` / `Pro` / `Both` is derived from the events themselves: an integration with both Free and Pro events is **Both**.

**Metrics.** *Total Integrations* = triggers + actions (an app offering both counts on each side). *Platform Integrations* = the unique union.

## Caveats

- **Needs the frontend source on disk.** If an audited plugin is a built/minified release (no `frontend/src`), or isn't installed, its report shows a notice instead of a catalog. Install it via `git clone`.
- A handful of actions whose operations are selected dynamically at runtime (e.g. the Zoho module pickers) collapse to a single **"Store Record"** / **"Dynamic Event"** — there is no static operation list to read.
- Counts reflect the source checked out on disk. Use **Refresh** to re-read after you update the source.

## Development

```bash
composer install        # autoloader + dev dependencies (PHPCS, PHPCompatibility)
composer lint           # phpcs against phpcs.xml.dist
composer fix            # phpcbf auto-fix
composer compat         # PHP 7.4–8.4 compatibility check
```

Coding standards: WordPress (via `phpcs.xml.dist`) tuned to the Bit Apps house style — security, escaping, i18n and SQL‑prepare kept strict.

### Project structure
```
bit-audit.php                 # bootstrap: constants, autoload, hooks, i18n
includes/
  Detector.php                # locates the plugins, presence/active state, cached report()
  AdminPage.php               # menu, enqueue, render, AJAX, export/refresh handlers
  AuditorInterface.php        # report() + events() contract
  BitIntegrationsAuditor.php  # Bit Integrations catalog + event parsing
  BitPiAuditor.php            # Bit Flows (root-machine) catalog + events
  CatalogScanner.php          # shared source-parsing helpers
  Exporter.php                # JSON / CSV export
templates/                    # dashboard, report-body, detail (presentation only)
assets/                       # admin.css, admin.js
```

## Contributing

Issues and pull requests are welcome. Please run `composer lint` and `composer compat` before opening a PR, and keep the parsing logic verified against the source plugins.

## License

[GPL‑2.0‑or‑later](LICENSE) © [Bit Apps](https://bitapps.pro)
