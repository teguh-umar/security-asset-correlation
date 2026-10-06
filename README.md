# Security-Asset Correlation (SAC)

GLPI 11 plugin. Periodically correlates **non-CVE security signals** to GLPI
assets and emits `Finding` records into the
[`riskregister`](https://github.com/teguh-umar/risk-register-dashboard) plugin,
which owns the risk model (Finding → Risk, 5×5 scoring, ISO 27001 / CIS v8).

SAC is a **feeder** — it never scores risk, maps controls, or runs review cycles.
Its job ends at a well-formed `Finding`.

> **Status: v1.0.0 — Modules 1 & 2 code-complete, not yet run on real GLPI.**
> Built "mode A" (offline: `php -l` + a dependency-free self-check harness).
> A VM verification pass is required before production use — see the checklist
> below.

---

## Modules

| # | Module | Status | Signal | Data source |
|---|---|---|---|---|
| 1 | **EOL/EOS** | ✅ v1.0.0 | OS / hardware past or nearing end-of-life | `fields`-plugin "LifeCycle" date → `endoflife.date` cache → native warranty |
| 2 | **License Governance** | ✅ v1.0.0 | commercial software with no license record; license over-deployment | native GLPI software / `glpi_softwarelicenses` inventory |
| 3 | **Config + Patch Compliance** | ⏳ later spec | CIS benchmark failures; missing security patches | OpenSCAP (CIS XCCDF + distro OVAL) |

---

## Feature list (v1.0.0)

### Module 1 — EOL/EOS

- **Weekly OS EOL scan** (`cronEolScan`) over every `(OperatingSystem,
  OperatingSystemVersion)` pair carrying a live Computer — GLPI 11 splits an
  OS across dropdown dimensions, so the OS name/id alone is not a valid EOL
  key (v1.1.0).
- **Layered EOL-date resolution**, first hit wins:
  1. operator-entered `fields`-plugin "LifeCycle" `EndOfLife` date — checked
     at the OS-Version level first, then the OS level (the more specific
     wins),
  2. an `endoflife.date` product/cycle mapping — either admin-confirmed via
     Reconcile, or auto-matched this run against the shipped seed
     (`install/data/eol_products.csv`),
  3. unresolved → Reconcile queue, no Finding.
- **`endoflife.date` local cache** (`cronEolCacheRefresh` + an inline
  cache-fill during `cronEolScan` itself) — EOL/EOS dates + LTS flag;
  re-fetch throttled by `eol_cache_max_age_days`.
- **Configurable severity ladder** — past-due → `Critical`, ≤90d → `High`, ≤180d → `Medium`, ≤365d → `Low`, beyond horizon → no Finding.
- **One Finding per (OS, version) pair** (`code = SAC-EOL-OS-<os_id>-<ver_id>`,
  category `Obsolescence`), a `Finding_Item` per affected Computer, affected
  hostnames in `content`.
- **Hardware-warranty EOL pass** (optional) — per-Computer Finding (`SAC-EOL-HW-<id>`) from `warranty_date + warranty_duration`. Unaffected by the v1.1.0 re-keying.
- **Signal-cleared handling** — when EOL moves beyond the horizon or the pair leaves inventory, appends a dated "Signal cleared" note (once per day) and stops re-asserting. **Never auto-closes.**
- **Seed-CSV product/cycle matching** — RHEL / Rocky / AlmaLinux / Oracle Linux / CentOS(-Stream) / Ubuntu / Debian / SLES / openSUSE / Amazon Linux / Windows(-Server) / VMware ESXi/vCenter, with version-cycle extraction (`8.6`→`8`, `22.04.3`→`22.04`, `2012 R2`→`2012-R2`). A non-OS name in the OS dropdown (e.g. an appliance's Kubernetes version) is silently skipped, never Reconciled.

### Module 2 — License Governance

- **Weekly license scan** (`cronLicenseScan`) over every Software installed on ≥1 Computer, skipping configured ignore categories.
- **2a — Missing license record** (always on): commercial-publisher software with **zero** `SoftwareLicense` rows → one Finding per publisher (`SAC-LIC-MISSING-<manufacturers_id>`), `Low` / `Medium` by product-count and install-count thresholds.
- **2b — License over-deployment** (config-gated, default off): `installs > licensed seats` → Finding per software (`SAC-LIC-OVER-<softwares_id>`), severity by ratio; an expired license forces `High`.
- **Commercial-publisher watch list** — seeded on install from `install/data/commercial_publishers.csv` (15 vendors), admin-editable.
- **Unlimited-license awareness** — `number <= 0` treated as unlimited.
- Same signal-cleared behaviour as Module 1.

### Finding pipeline (→ riskregister)

- **Single integration point** — every Finding write goes through one `FindingWriter` class, guarded by `\Plugin::isPluginActive('riskregister')`. riskregister absent = SAC degrades cleanly, never fatals.
- **In-process itemtype API** — `Finding->find()` then `->add()` / `->update()`; idempotent upsert on `(entities_id, code)`; `Finding_Item` links per resolved Computer; unresolved hosts → `raw_asset_text`.
- **Deterministic ≤50-char codes** so weekly re-runs update rather than duplicate.
- **Per-entity findings** — the scans run instance-wide, but a Finding is written in the entity of the affected assets, never recursive, and links only that entity's assets. A licence counts for an entity only when it is owned by that entity or is a recursive licence of an ancestor.
- **CSV export** — `tools/export_findings.php` (CLI-only) dumps all SAC-origin Findings for external evidence.

### Operator surface

- **One entry "Security Assessment" in the shared suite sidebar menu** ("ICT Tools"), with a sub-nav between its pages.
- **Overview** — per-module status cards (last run, counts, open-reconcile count, deep link to the Finding list).
- **Reconcile queue** — unmapped OS / bad dates / ambiguous publishers, with inline "Map & resolve" and "Resolve" actions.
- **Scan runs** — last 50 cron runs (module, timing, status, counters) — read-only audit.
- **Setup** — all config keys with type-aware widgets (checkbox / number / entity dropdown / JSON) and server-side validation.
- **Profile rights tab** — standard GLPI rights matrix for `plugin_sac_finding` / `plugin_sac_config`.

### Platform / infrastructure

- GLPI 11.0.0–11.9.99, PHP 8.2+, PSR-4 `GlpiPlugin\Sac\`, GPL-3.0-or-later, author "ICT".
- **5 own tables**: `glpi_plugin_sac_{eolmap, eolcache, commercialpublishers, reconcile, run}` — timestamp-safe DDL, clean install / uninstall.
- **3 weekly cron tasks**: `EolScan`, `EolCacheRefresh`, `LicenseScan` — registered on install.
- **Run audit + stale-run recovery** — each scan opens a `_run` row with per-signal counters; orphaned "running" rows are swept to "stale".
- **Generic Reconcile queue** (`signal_type` + polymorphic `ref_*`) — reused across modules.
- **Failure-tolerant** — network errors, missing `fields` plugin, riskregister absent, malformed data: all logged and skipped, never abort a run.
- **Two test harnesses** — `tools/offline-selfcheck.php` (15 dependency-free checks, CI-able) and `tools/selfcheck.php` (VM runner that seeds / asserts / tears down against a live GLPI). Both are CLI-only (refuse a web SAPI).
- Only GLPI core form helpers — no hand-rolled HTML; survives GLPI's Twig migration.

---

## Install

```bash
# Copy the plugin into the GLPI plugins directory
cp -r . /data/www/glpi/plugins/sac

# Install and activate
php bin/console glpi:plugin:install sac --username=<admin>
php bin/console glpi:plugin:activate sac
```

### Upgrading

The web **Setup → Plugins → Upgrade** button is broken on the suite's GLPI 11
build (`BadRequestHttpException` at `front/plugin.form.php`). Upgrade from the CLI
instead:

```bash
sudo -u nginx php /data/www/glpi/bin/console plugin:install sac --force
sudo -u nginx php /data/www/glpi/bin/console plugin:activate sac
```

`--force` is required for an already-installed plugin. `plugin_sac_install()` is
partially upgrade-safe today — it skips existing tables and tops up new config
keys — but **column additions on existing tables need a real `\Migration` object
first** (see Roadmap). Until then, treat a schema-changing upgrade as
uninstall + reinstall.

---

## Configuration

All settings live in GLPI config context `plugin:sac` — edit them on **Security
Assessment → Setup**, or via the `Config` API. Defaults are seeded on install.

| Key | Default | Meaning |
|---|---|---|
| `scan_entities` | `[]` | Entity ids the scans cover; empty = every entity. |
| `scan_entities_recursive` | `1` | Also scan the child entities of the selected ones. |
| `eol_scan_enabled` | `1` | Master switch for the weekly EOL/EOS scan (`cronEolScan`). |
| `eol_horizon_days` | `365` | Only emit an EOL Finding when end-of-life falls within this many days (past counts as within). |
| `eol_severity_high_days` | `90` | Days-to-EOL at or below this → severity `High` (negative → `Critical`). |
| `eol_severity_medium_days` | `180` | Days-to-EOL at or below this (above the High threshold) → `Medium`; up to the horizon → `Low`. |
| `eol_hardware_enabled` | `1` | Also run the hardware-warranty EOL pass (one Finding per Computer). |
| `eol_hardware_horizon_days` | `180` | Horizon for the hardware-warranty EOL pass. |
| `eol_cache_max_age_days` | `180` | Re-fetch an `endoflife.date` product only when its cached row is older than this (a brand-new mapping is always fetched). |
| `license_scan_enabled` | `1` | Master switch for the weekly License Governance scan (`cronLicenseScan`). |
| `license_check_overdeployment` | `0` | Enable the 2b over-deployment check (installs > licensed seats). Off by default. |
| `license_ignore_categories` | `["system_component"]` | JSON array of `SoftwareCategory` system-names excluded from the license scan. |
| `license_2a_products_threshold` | `5` | A publisher with more unlicensed products than this escalates the 2a Finding from `Low` to `Medium`. |
| `license_2a_installs_threshold` | `20` | Total unlicensed installs for a publisher above this escalates the 2a Finding from `Low` to `Medium`. |

---

## How Module 1 works (EOL/EOS)

`cronEolScan` runs weekly, two-pass. Pass 1 walks every distinct
`(operatingsystems_id, operatingsystemversions_id)` pair linked to at least one
Computer (GLPI 11 splits an OS across dropdown dimensions — the OS id alone is
not a valid EOL key) and resolves each pair's end-of-life date, first hit wins:
(a) the operator-entered `fields` "LifeCycle" date — checked at the
OS-Version level first, then the OS level, the more specific wins; (b) an
existing `_eolmap` mapping (an admin-confirmed row, or the `(os_id, 0)`
"version unknown" fallback); (c) a fresh match against the shipped seed
`install/data/eol_products.csv` (`EolMatch::resolve()`), which auto-writes
`_eolmap` (`confidence = 'auto'`) on the spot. If nothing resolves, or a
seed-matched pattern is a non-OS `_skip` entry (e.g. an appliance's
Kubernetes version), it writes a `no_mapping` row to the Reconcile queue (or
is silently counted as `skipped`) and emits no Finding. Between the two
passes, `EolCache::ensureFresh()` fetches `endoflife.date` for any newly
mapped product still missing from the local cache, so a fresh seed match
still has a dated Finding to emit in Pass 2, the SAME run. A resolved date is
graded against `eol_horizon_days` / the two severity thresholds and one
`Finding` (`code = SAC-EOL-OS-<os_id>-<ver_id>`, category `Obsolescence`) is
upserted through `FindingWriter`, with a `Finding_Item` per affected
Computer. A mapped pair whose cache entry isn't fetched yet bumps a
`cache_pending` counter and is retried next run — self-healing, not
surfaced as a problem. A second, unchanged pass, gated on
`eol_hardware_enabled`, does the same per Computer for
`warranty_date + warranty_duration months`. When a signal drops beyond the
horizon or the pair leaves inventory, SAC appends a dated "Signal cleared"
line to the existing Finding and stops re-asserting — it never auto-closes.
`cronEolCacheRefresh` re-fetches stale `endoflife.date` rows for everything
already in `_eolmap`.

**Upgrading from a pre-v1.1.0 install:** old `SAC-EOL-OS-<id>` Findings (the
single-int code) are closed with a superseded content note — never deleted,
since a promoted riskregister Risk can hold a FK to the Finding. Old
`_reconcile` `eol` rows are cleared (SAC owns that table; the next scan
regenerates them at the new grain); `_eolmap` `auto` rows are purged
(`confirmed` rows are kept as an `(os_id, 0)` fallback). See VM verification
checklist item 11 below.

**Not yet built:** full-catalog auto-discovery against `endoflife.date`'s
`api/all.json` (the seed CSV covers the common distros/platforms; anything
else lands in Reconcile for a one-time admin mapping).

## How Module 2 works (License Governance)

`cronLicenseScan` runs weekly. It walks every Software installed on ≥1 live
Computer, skipping any whose `SoftwareCategory` is in `license_ignore_categories`,
and for each gathers install count, aggregated license seats (`number <= 0` =
unlimited), earliest expiry and publisher. **2a (always on):** software whose
publisher is on the commercial-publisher watch list and which has zero license
rows is grouped by publisher into one `Finding`
(`code = SAC-LIC-MISSING-<manufacturers_id>`), `Low` / `Medium` by the two 2a
thresholds. **2b (gated on `license_check_overdeployment`):** software with finite
seats and `installs > seats` gets one `Finding`
(`code = SAC-LIC-OVER-<softwares_id>`), severity by the installs/seats ratio (an
expired license forces `High`). Both categories use `License Compliance` and clear
the same way Module 1 does. The watch list is seeded on install from
`install/data/commercial_publishers.csv`; ambiguous / unresolvable publisher names
are logged to the Reconcile queue for manual DB correction (there is no re-seed
workflow in the UI yet).

---

## Namespace / footprint

| Resource | Value |
|---|---|
| PSR-4 | `GlpiPlugin\Sac\` → `src/` |
| Tables | `glpi_plugin_sac_{eolmap, eolcache, commercialpublishers, reconcile, run}` |
| Config context | `plugin:sac` |
| Rights | `plugin_sac_finding`, `plugin_sac_config` |
| Menu | entry `sac` ("Security Assessment", `order` 40) in the shared sector `pgnsuite` |
| Cron | `GlpiPlugin\Sac\Cron` — actions `EolScan`, `EolCacheRefresh`, `LicenseScan` (weekly) |

Part of the PGN ICT GLPI 11 plugin suite (`integrasipaman`, `riskregister`,
`drillreport`, `nvd`, SAC), all deployed to one instance. Shared namespace claims
and integration contracts live in `_pgn-glpi-suite.md` (not in this repo).

### Finding pipeline contract (with `riskregister`)

- **Direct in-process API** — `FindingWriter` calls `\GlpiPlugin\Riskregister\Finding`
  (`->find()` → `->add()` / `->update()`) and `\GlpiPlugin\Riskregister\Finding_Item`,
  status-preserving upsert keyed on `(entities_id, code)`. No CSV, no datainjection.
- **`code`** is stable and deterministic per finding (`SAC-EOL-OS-<os_id>-<ver_id>`,
  `SAC-EOL-HW-<id>`, `SAC-LIC-MISSING-<manufacturers_id>`, `SAC-LIC-OVER-<softwares_id>`);
  `scope_label` is always non-empty.
- **Enums**: `severity` ∈ `Critical|High|Medium|Low|Info`,
  `status` ∈ `Open|In Treatment|Treated|Closed`.
- **Category** — `FindingWriter::categoryId()` resolves (find-or-create) the
  `RiskCategory` via `ImportSupport::categoryIdByName()`. SAC relies on
  `riskregister` **v1.1.0+** seeding the canonical names (`Obsolescence`,
  `License Compliance`, and for Module 3 `Patch Compliance` / `Configuration Compliance`)
  so no duplicate dropdown rows are created.
- **`assessment_ref`** marks origin: `SAC/eol` or `SAC/license`.
- Module 3 will carry CIS control numbers mapped onto `riskregister`'s seeded
  `cis_v8.csv` (top-level controls 1–18; SCAP sub-control refs like `5.2` truncate
  to the parent).

---

## VM verification checklist

Run on the target GLPI instance after `php bin/console glpi:plugin:install sac` +
`glpi:plugin:activate sac` (check `files/_log/php-errors.log` after each step):

1. Confirm the `fields` "LifeCycle" table + column. Per pluginsGLPI/fields source
   (`inc/container.class.php`, `inc/field.class.php`) the expected shape is:
   - **table** `glpi_plugin_fields_operatingsystemlifecycles`
     (`getSystemName()` → `operatingsystem` + `lifecycle`, then GLPI pluralises)
   - **date column** `endoflifefield` (`Field::prepareName()` appends `field` to
     the label-derived name — "EndOfLife" → `endoflife` → `endoflifefield`)
   - **row key** `items_id` (= the `operatingsystems_id`) + `itemtype = 'OperatingSystem'`
   `LifeCycleReader` probes the exact table then `…operatingsystem%lifecycle%`,
   tries `endoflifefield` / `endoflife` / `endoflife_date` then the sole non-audit
   date column, and pins `itemtype = 'OperatingSystem'` when that column exists.
   Run `SHOW COLUMNS FROM glpi_plugin_fields_operatingsystemlifecycles;` to verify.
   If `fields` is absent, Module 1 runs `endoflife.date`-only (logged once per run).
2. GLPI Inventory Plugin present; Collect task usable; **Deploy task disabled**
   (needed for Module 3 — verify now).
3. `riskregister` **v1.1.0+ deployed** so `ImportSupport::categoryIdByName`
   resolves the canonical category names (SAC tolerates a `0` category id, but a
   name drift would silently create a duplicate dropdown row).
4. **v1.5.0 upgrade only** — before the first scan or selfcheck, run
   `php plugins/sac/tools/entity_isolate.php` (dry run) and read the report;
   then `--apply`.
5. `php bin/console migration:timestamps` after install → expect **clean** /
   "Database schema is OK".
6. Run each cron once via **Setup → Automatic actions → Execute** (NOT
   `front/cron.php <name>` — numeric only on this host); confirm the
   `glpi_plugin_sac_run` rows close `ok`.
7. `endoflife.date` reachable from the GLPI host — check with
   `php plugins/sac/tools/net_check.php`. It walks the same path as the cron and
   names the first step that fails. If it cannot be reached, Module 1 runs
   LifeCycle-only; note it. The site is behind a CDN, so a firewall rule must be
   written against the hostname, not an IP.
8. `php plugins/sac/tools/selfcheck.php` → **all PASS** (this seeds and tears down
   throwaway rows and, on a VM where `riskregister` is active, may create real
   Findings for genuinely-EOL assets — expected).
9. **Setup → Profiles → "Security Assessment" tab renders and saves.** `Profile.php`
   extends core `\Profile` and posts the rights matrix to `\Profile::getFormURL()`
   with `update` + `id` + `_glpi_csrf_token`; core's `prepareInputForUpdate()` /
   `post_updateItem()` persist the two rights via `ProfileRight::updateProfileRights()`.
   Change a right, save, reload — it must stick.
10. Confirm `csrf_token()` is available in GLPI 11's Twig environment — the
    `config` and `reconcile` templates call it.
11. **v1.1.0 upgrade only** — after `plugin:install sac --force`, confirm: old
    `SAC-EOL-OS-<n>` Findings are `Closed` with a
    "[superseded … re-keyed to (operatingsystems_id, operatingsystemversions_id)]"
    content note (never deleted); `glpi_plugin_sac_reconcile` has zero
    `signal_type='eol'` rows immediately after the upgrade (SAC wipes and
    regenerates them); `glpi_plugin_sac_eolmap` retains only `confidence =
    'confirmed'` rows (now at `operatingsystemversions_id = 0`). Then run
    `cronEolScan` once and confirm `_eolmap`/`_reconcile` repopulate at the new
    `(os,ver)` grain and new Findings carry the two-int `code`.
12. Confirm `glpi_softwares.is_template` and `glpi_items_softwareversions.is_deleted`
    columns exist (the License candidate query filters on both).
13. Confirm `DBmysql::quote()` is available in the running build (used by the
    License candidate query's category-exclusion list).
14. Open the Security Assessment section of the suite Setup tab, save the form
    once, and confirm the values round-trip.

---

## Roadmap / not in v1.0.0

- **Module 3** — Config + Patch Compliance via OpenSCAP (CIS XCCDF + distro OVAL),
  tiered collection: local `oscap` cron + GLPI Agent Collect summary first, full
  `results.xml` pull later. Separate spec.
- **VM verification pass** — the plugin is code-complete but has never run on real
  GLPI.
- Full-catalog auto-discovery against `endoflife.date`'s `api/all.json` (the
  v1.1.0 seed CSV covers the common distros/platforms only).
- Windows patch/CIS tracks; hardware-vendor lifecycle source.
- Browser-based (non-CLI) Finding export.

---

## License

GPL-3.0-or-later.
