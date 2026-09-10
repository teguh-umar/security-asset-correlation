# Changelog

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
