# Changelog

## [1.1.8] - 2026-09-13

### Fixed

- **The actual root cause of EOL scan's "Error" status, finally visible
  thanks to v1.1.4's error-message surfacing:**
  `MySQL query error: Unknown column 'MAX(date_fetched)' in 'field list'`.
  `EolCache::isStale()` (called by `ensureFresh()` between `EolScan`'s two
  passes — on the hot path of every real run) passed
  `'SELECT' => ['MAX(date_fetched) AS newest']` to `$DB->request()`'s array
  query builder. The builder quotes each `SELECT` entry as a literal column
  identifier rather than treating it as a SQL expression, so the whole
  aggregate-plus-alias string got backtick-wrapped verbatim into an
  obviously-nonexistent column name. Rewritten to raw `doQuery()` +
  `fetch_assoc()` (the same pattern already proven in this file's own
  `staleProducts()`), with `$DB->quote()` (VM-proven since `LicenseScan`
  v1.0.0/v1.0.1) for the product value — which traces back to admin-typed
  Reconcile input, not just the shipped seed CSV.
- **Same broken `'SELECT' => ['MAX(id) AS m']` pattern found in 4 more
  places** (a repo-wide grep after finding the first one): the
  `db/writer-riskregister-inactive` selfCheck in `src/Cron.php`, the
  `db/eolcache-ensurefresh` selfCheck in `src/Eol/EolCache.php`, and the
  `scRunFloor()` helper duplicated in `src/Eol/EolScan.php` and
  `src/License/LicenseScan.php` (used by every one of their own selfCheck
  teardowns). None of these run during a normal cron tick, but every one
  would have crashed `tools/selfcheck.php` on first real use. All 5 fixed
  the same way.

## [1.1.7] - 2026-09-13

### Fixed

- **`operatingsystemversions_id` / `ref_subid` were signed, not unsigned** —
  suite-wide rule (`pgn-glpi-suite` §4 rule 2b, GLPI's Setup warning
  *"primary or foreign keys columns are using signed integers"*): GLPI's
  `\Migration::addField()`/`changeField()` only emit `UNSIGNED` for the
  `'fkey'` type keyword (or `'autoincrement'` for a PK) — `'integer'`/`'int'`
  silently produce a signed column even though this file's `CREATE TABLE`
  DDL has always said `int unsigned`. Both v1.1.0 upgrade-path `addField()`
  calls used `'integer'` — same bug class drillreport hit first (v0.8.5).
  Fixed: both now use `'fkey'`; added a `changeField()` retrofit for any VM
  that already upgraded through v1.1.0–v1.1.6 with the signed column,
  gated on a new `Migration::isUnsignedInt()` check so it's a no-op once
  fixed. `ref_subid` doesn't match GLPI's `*_id` naming convention, so it
  needed this explicit retrofit regardless of whether a later
  `migration:unsigned_keys` run would have caught it. `db/migration-eolmap-key`
  now also asserts the migrated column is unsigned.
- No negative sentinel values exist in any SAC key column (audited
  suite-wide) — nothing else to retrofit.

## [1.1.6] - 2026-09-13

### Added

- **Click-to-sort on the Reconcile and Scan-runs tables** — per the
  suite-wide UI rule (`pgn-glpi-suite`, generalizing riskregister's Reconcile
  pattern): any column carrying a single sortable value gets it, control
  columns don't. New shared partial `templates/_sortable-table.html.twig`
  (`{% include '@sac/_sortable-table.html.twig' %}`) — event-delegated
  (bound on `document`, not on the table itself), so it works regardless of
  where the script tag sits relative to the table markup, avoiding the exact
  ordering bug riskregister v1.5.1 hotfixed (a script querying the DOM at
  parse time, before its own table existed further down the page).
  - **Reconcile:** Status, Signal, Reason, Reference, Mapping, First seen,
    Last seen are sortable; Actions (buttons/forms) is not. The Reference
    column sorts on the plain label, ignoring its "(Itemtype #id)" suffix.
  - **Scan runs:** Module, Started, Ended, Status are sortable; Counts is
    not — it's several key/value pairs per cell, not one sortable value.

## [1.1.5] - 2026-09-13

### Fixed

- **Reconcile page's "Resolved"/"Open" badges had no explicit text colour**
  (`templates/reconcile.html.twig`) — unlike the status badges added in
  v1.1.3 elsewhere in the plugin. Two real contrast bugs, found on a
  suite-wide audit prompted by `pgn-glpi-suite-*`'s badge-contrast rule:
  the "Resolved" badge (`bg-success`) sat inside a row with
  `class="text-muted"`, so it inherited muted grey text on a green
  background instead of setting its own; the "Open" badge (`bg-warning`)
  set no text colour at all, so in a dark theme it likely rendered light
  text on its pale-yellow background. Both now set an explicit,
  contrast-correct colour: `text-white` for the green "Resolved" badge,
  `text-dark` for the yellow "Open" badge (warning/yellow backgrounds need
  dark text, unlike success/danger/secondary, which need white — the same
  pairing already used correctly for the OK/Error badges elsewhere in this
  plugin).

## [1.1.4] - 2026-09-13

### Added

- **A failed run's error message is now visible in the UI.** A run's `status`
  can be `error` (an uncaught exception escaped `EolScan`/`LicenseScan`
  entirely — a bug, distinct from the internal `errors` counter, which only
  counts caught/handled per-item failures) with the exception message
  stored in `glpi_plugin_sac_run.message` — but neither `front/overview.php`
  nor `front/run.php` ever passed that column to their templates, so an
  admin had no way to see *why* a run failed short of querying the database
  directly. Both front controllers now include `message`; both Overview
  (a small `alert-danger`) and "Scan runs" (red text under the status
  badge) render it when a run's `status` is `error` and it has one.

## [1.1.3] - 2026-09-13

### Fixed

- **Status pills still weren't readable after v1.1.2** — the Tabler
  `status`/`status-dot` classes used on both Overview and "Scan runs"
  render as a flat neutral-gray pill in this GLPI install's theme, with no
  colour distinction between `OK` and `Error` at all. Replaced with plain
  `badge bg-success` / `badge bg-danger` / `badge bg-secondary` + explicit
  `text-white` — the same colour classes already proven to render
  correctly (they already colour the Overview card's top bar) — on both
  `templates/overview.html.twig` and `templates/run.html.twig`.

## [1.1.2] - 2026-09-13

### Fixed

- **"Scan runs" page (`templates/run.html.twig`) was unreadable** — every
  count rendered as a dark `badge bg-secondary` pill (the same low-contrast
  issue Overview had before v1.0.2, but this page never got that fix). Now
  matches Overview's convention: theme-aware text (`text-muted` label /
  `text-body` value, no fixed background colour) instead of solid badges, a
  colour-coded `status`/`status-dot` pill for the Status column, human
  labels for every counter via the same `count_labels` map Overview uses
  (now including the v1.1.0 `skipped` / `cache_pending` counters, which this
  page didn't know about either), and a non-zero `errors` row rendered in
  red.

## [1.1.1] - 2026-09-12

First real v1.1.0 upgrade attempt on VM-HQ-GLPI failed with `Attempted to
call an undefined method named "indexExists" of class "DB"` inside
`Migration::migrateEolmapVersionKey()`.

### Fixed

- **`src/Migration.php`** — GLPI's `DB`/`DBmysql` class has no `indexExists()`
  method; both schema-migration helpers (`migrateEolmapVersionKey()`,
  `migrateReconcileSubId()`) called it directly and crashed the upgrade
  before any of the v1.1.0 schema/data migration ran. Replaced with a new
  `Migration::keyExists()` using a direct `SHOW KEYS FROM <table>` query,
  which needs no database/schema name at all (also sidesteps hardcoding
  `TABLE_SCHEMA = 'glpi'` — VM-HQ-GLPI's actual database is `itsm`, per the
  suite-wide `nvd` 2.4.1 gotcha). `Migration::install()` is idempotent, so a
  VM that hit this crash mid-upgrade (partially migrated: the
  `operatingsystemversions_id` column may already be present, the key swap
  and everything after it did not run) just needs `install()` re-run —
  no manual cleanup required.

## [1.1.0] - 2026-09-11

GLPI 11 splits an operating system across dropdown dimensions
(`operatingsystems_id`, `operatingsystemversions_id`, …) — VM data showed a
single `operatingsystems_id` ("Oracle") spanning Oracle Linux 7.x through
9.7. Module 1 (EOL/EOS) is re-keyed to the correct grain.

### Changed

- **EOL Findings are now per `(operatingsystems_id, operatingsystemversions_id)`
  pair**, not per OS name/id alone. New `code` format:
  `SAC-EOL-OS-<os_id>-<ver_id>`. Hardware-warranty EOL
  (`SAC-EOL-HW-<computers_id>`) is unchanged.
- **`endoflife.date` mapping is now seed-CSV driven** —
  `install/data/eol_products.csv` maps an OS name pattern to a product slug +
  cycle-extraction rule (`EolMatch::resolve()` + `EolProducts`). A fresh match
  auto-writes `_eolmap` (`confidence = 'auto'`) and the Finding is emitted the
  **same run** — the scan is now two-pass, with a cache-fill step
  (`EolCache::ensureFresh()`) between them. `fields` "LifeCycle" stays the
  higher-priority override when an admin has entered a date, and is now read
  at **both** the OS level and the OS-Version level (the more specific
  version-level date wins).
- `_eolmap` gains `operatingsystemversions_id` (UNIQUE re-keyed to the pair);
  `_reconcile` gains `ref_subid` (UNIQUE widened) so two versions of the same
  OS no longer collide in the Reconcile queue. Migrated in place by a real
  `\Migration` object (idempotent — safe to re-run).
- Old-format EOL Findings (`SAC-EOL-OS-<n>`) are **closed** with a superseded
  content note on upgrade — never deleted (a promoted riskregister Risk holds
  a FK to the Finding). Pre-upgrade `_reconcile` `eol` rows are cleared and
  regenerated by the next scan; `_eolmap` `auto` rows are purged (`confirmed`
  rows are kept as an `(os_id, 0)` fallback).
- Reconcile page: the Reference column now shows the OS **and** its version;
  the help text and inline "Map & resolve" examples are version-aware.

### Added

- Two new run counters: `skipped` (a seed-CSV `_skip` pattern, e.g.
  Kubernetes, matched an inventory row that isn't really an OS lifecycle) and
  `cache_pending` (mapped, but `endoflife.date` hasn't been fetched yet —
  self-healing on the next run).

## [1.0.4] - 2026-09-10

### Added

- Reconcile page: an inline help panel — what an `eol` / `no_mapping` row means,
  how to fill `product` / `cycle` (with an endoflife.date link + examples), the
  LifeCycle-tab alternative, and what plain "Resolve" does.

## [1.0.3] - 2026-09-10

### Added

- **Reconcile page: "Re-open" button on resolved rows** — sends a signal back to
  the open queue so a wrong mapping can be corrected. `Reconcile::reopen()`.
- **"Mapping" column** — every OS reconcile row shows its current
  `_eolmap` product/cycle (`—` when unmapped). The inline "Map & resolve" form is
  now **pre-filled** with the existing mapping, so re-mapping is an edit, not a
  retype. `EolMap::put()` already upserts, so the next `EolScan` updates the
  affected Finding in place.

## [1.0.2] - 2026-09-10

### Added

- **Reconcile page: "Show resolved" toggle** (`?resolved=1`). Resolved rows carry
  a green "Resolved" badge, greyed text, and no action buttons; open rows a
  yellow "Open" badge. `listForDisplay()` now returns `is_resolved` per row.

### Changed

- **Overview cards rebuilt on Tabler components** (`card-status-top`, `status`
  dot, `datagrid`) instead of dark `badge bg-secondary` pills — readable in both
  light and dark themes. Counters get human labels ("Findings written",
  "Reconcile added", …); a non-zero error count renders red.

## [1.0.1] - 2026-09-10

First real deploy on VM-HQ-GLPI (GLPI 11) surfaced three schema-drift bugs.

### Fixed

- **EOL hardware pass** queried `glpi_computers.warranty_date` /
  `warranty_duration` — those columns do not exist in GLPI 11. Warranty data
  lives in `glpi_infocoms` (keyed by `itemtype` + `items_id`). `runHardware()`
  now joins `glpi_infocoms`, is `tableExists`/`fieldExists`-guarded, skips
  `warranty_duration <= 0` (permanent / none), and the call is wrapped so a
  hardware-pass failure logs + bumps `errors` instead of failing the whole EOL
  run (the OS pass already stands).
- **License aggregate query** used `MIN(NULLIF(`expire`, '0000-00-00'))` — MySQL
  in `NO_ZERO_DATE` / strict mode rejects the `'0000-00-00'` DATE literal, so
  `LicenseScan` aborted on the first software with a legacy zero expiry. Now
  `CAST(`expire` AS CHAR)` before the compare. The per-software loop is also
  wrapped in try/catch so one bad row bumps `errors` and continues.
- `reconcile_open` run counter is now bumped with the count of OSes routed to the
  Reconcile queue this run (was always `0`).

### Changed

- Overview cards: counters render as a plain two-column table instead of dark
  low-contrast badges; the status badge is colour-coded (`ok` green, `error`
  red).
- `LifeCycleReader` date-column probe tries **`endoflifefield`** first —
  pluginsGLPI/fields `Field::prepareName()` appends `field` to the label-derived
  name, so "EndOfLife" becomes `endoflifefield` (falls through to `endoflife`,
  `endoflife_date`, then the sole non-audit date column). **VM-confirmed**: table
  `glpi_plugin_fields_operatingsystemlifecycles`, column `endoflifefield`
  (`varchar(255)`), keyed `items_id` + `itemtype = 'OperatingSystem'`.

## [1.0.0] - 2026-09-10

Released `sacv1.0.0.zip`. First tagged release — code-complete, **never run on a
real GLPI instance** (mode-A); the deploy must follow the README VM verification
checklist.

### Fixed

- Config screen: `finding_entity` (and every other key) now renders from an
  explicit `Config::FIELD_TYPES` map instead of guessing checkbox-vs-text from
  the default value — an int-`0` entity id is no longer silently reset to 0 on
  Save. `front/config.php` validates every posted value per type (bool / int /
  entity / JSON array) and rejects bad input with an error message.
- Setup → Profiles rights tab: ported to the riskregister approach — a real
  `<form>` posting to core `front/profile.form.php`, so rights actually persist;
  removed the `\Profile::getRightsFor()` call that could fatal on GLPI 11.
- `tools/selfcheck.php` / `tools/export_findings.php`: refuse non-CLI (HTTP)
  invocation.
- `FindingWriter::appendClearedNote()` is idempotent per day — no more unbounded
  `content` growth / repeated `findings_cleared` when a signal stays cleared.
- EOL hardware pass and the License candidate/count queries now filter
  `is_template` / `is_deleted` consistently.
- Per-iteration `Config::get()` / category-id lookups hoisted out of the scan
  loops.
- License candidate query: ignored-category names are `$DB->quote()`d verbatim
  (no character stripping) so accented names keep their ignore rule.
- Ambiguous / unresolvable commercial-publisher names get a distinct
  `ref_id = crc32(name)` on the Reconcile row so they no longer collide; they
  are **logged to the Reconcile queue for manual DB correction** — there is no
  "re-seed from Setup" workflow.
- `LifeCycleReader`: `items_id` query guarded by `fieldExists`, pinned to
  `itemtype = 'OperatingSystem'` when that column exists, and the table LIKE
  fallback constrained to `glpi_plugin_fields_operatingsystem%lifecycle%` so it
  cannot latch onto a Computer-attached container.
- selfChecks: EOL-1 now exercises the LifeCycle path only; added
  `db/eol-lifecycle-wins`, `db/eol-bad-date`, `db/writer-riskregister-inactive`
  (spec §9.2 WRITER-1). `db/` seeds create core rows via `->add()` (captured
  ids) instead of hardcoded ~2.1e9 ids.

### Added

- Plugin scaffold: PSR-4 `GlpiPlugin\Sac\` → `src/`, own top-level menu sector
  (`sac`), 2 rights (`plugin_sac_finding`, `plugin_sac_config`), 14 `plugin:sac`
  config keys, 5 tables (`glpi_plugin_sac_{eolmap,eolcache,commercialpublishers,reconcile,run}`).
- Module 1 — EOL/EOS: per-OS end-of-life resolution (`fields` "LifeCycle" date →
  `endoflife.date` cache fallback → Reconcile queue when neither resolves); one
  `Finding` per OperatingSystem graded against the configured horizon; a
  hardware-warranty EOL pass (`warranty_date + warranty_duration`), gated on
  `eol_hardware_enabled`.
- Module 2 — License Governance: **2a** missing-license-record Findings (one per
  commercial publisher with unlicensed products, always on); **2b**
  over-deployment Findings (installs > licensed seats), gated on
  `license_check_overdeployment`.
- Reconcile queue + UI (`front/reconcile.php`) for unmapped OS EOL signals and
  ambiguous publisher names, with a "confirm mapping" / "resolve" workflow.
- `tools/export_findings.php` — CSV export of emitted Findings for audit /
  server-refresh KAK evidence.
- 3 weekly cron tasks: `GlpiPlugin\Sac\Cron::{EolScan, EolCacheRefresh,
  LicenseScan}`.
- `tools/selfcheck.php` (full VM self-check runner, bootstraps GLPI and runs
  every `GlpiPlugin\Sac\*::selfChecks()` including the `db/` fixtures) and
  `tools/offline-selfcheck.php` (dependency-free pure-logic subset).
