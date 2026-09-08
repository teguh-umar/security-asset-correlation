# Changelog

## [1.0.0] - 2026-09-08

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
