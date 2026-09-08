# Security-Asset Correlation (SAC)

GLPI 11 plugin. Correlates **non-CVE security signals** to GLPI assets and emits
`Finding` records into the [`riskregister`](https://github.com/teguh-umar/risk-register-dashboard)
plugin, which owns the risk model (Finding → Risk, 5×5 scoring, ISO 27001 / CIS v8
mapping).

> **Status: v1.0.0 — Modules 1 & 2 code-complete.** EOL/EOS and License Governance
> shipped; pending the VM verification pass (see checklist below).

## Scope

| Module | Data source | Risk trigger |
|---|---|---|
| EOL/EOS | GLPI software/model inventory + `endoflife.date` API | EOL date < today |
| License Compliance | native GLPI software inventory (`glpi_softwarelicenses`) | installs > licensed seats |
| Patch Compliance | patch status source (TBD — WSUS / Sangfor SCP) | pending patches > threshold |
| Config Compliance | OpenSCAP + SCAP Security Guide (CIS-aligned) | benchmark score < threshold |

SAC **stops at emitting `Finding` rows**. It does not build its own risk model.

## Install

```bash
# Copy plugin to GLPI plugins directory
cp -r . /data/www/glpi/plugins/sac

# Install and activate
bin/console glpi:plugin:install sac --username=<admin>
glpi:plugin:activate sac
```

## Configuration

All settings live in GLPI config context `plugin:sac` (Setup → General → SAC, or
`Config` API). Defaults are seeded on install.

| Key | Default | Meaning |
|---|---|---|
| `finding_entity` | `0` | Entity emitted `Finding` rows are created in (`0` = Root). |
| `finding_recursive` | `1` | Create Findings recursive, so a Root Finding is visible to every sub-entity. |
| `eol_scan_enabled` | `1` | Master switch for the weekly EOL/EOS scan (`cronEolScan`). |
| `eol_horizon_days` | `365` | Only emit an EOL Finding when end-of-life falls within this many days (past counts as within). |
| `eol_severity_high_days` | `90` | Days-to-EOL at or below this → severity `High` (negative → `Critical`). |
| `eol_severity_medium_days` | `180` | Days-to-EOL at or below this (but above the High threshold) → `Medium`; up to the horizon → `Low`. |
| `eol_hardware_enabled` | `1` | Also run the hardware-warranty EOL pass (one Finding per Computer). |
| `eol_hardware_horizon_days` | `180` | Horizon for the hardware-warranty EOL pass. |
| `eol_cache_max_age_days` | `180` | Re-fetch an `endoflife.date` product only when its cached row is older than this (a brand-new mapping is always fetched). |
| `license_scan_enabled` | `1` | Master switch for the weekly License Governance scan (`cronLicenseScan`). |
| `license_check_overdeployment` | `0` | Enable the 2b over-deployment check (installs > licensed seats). Off by default. |
| `license_ignore_categories` | `["system_component"]` | JSON array of `SoftwareCategory` system-names excluded from the license scan. |
| `license_2a_products_threshold` | `5` | A publisher with more unlicensed products than this escalates the 2a Finding from `Low` to `Medium`. |
| `license_2a_installs_threshold` | `20` | Total unlicensed installs for a publisher above this escalates the 2a Finding from `Low` to `Medium`. |

## How Module 1 works (EOL/EOS)

`cronEolScan` runs weekly. It walks every `OperatingSystem` that carries at least
one non-deleted Computer and resolves an end-of-life date, first hit wins: (a) the
operator-entered `fields` "LifeCycle" date, then (b) a confirmed/auto
`_eolmap → _eolcache` mirror of `endoflife.date`. If neither resolves, it writes a
`no_mapping` row to the Reconcile queue and emits no Finding. A resolved date is
graded against `eol_horizon_days` / the two severity thresholds and one `Finding`
(`code = SAC-EOL-OS-<id>`, category `Obsolescence`) is upserted through
`FindingWriter`, with a `Finding_Item` per affected Computer. A second pass, gated
on `eol_hardware_enabled`, does the same per Computer for
`warranty_date + warranty_duration months`. When a signal drops beyond the horizon
or the OS leaves inventory, SAC appends a dated "Signal cleared" line to the
existing Finding and stops re-asserting — it never auto-closes. `cronEolCacheRefresh`
re-fetches stale `endoflife.date` rows.

## How Module 2 works (License Governance)

`cronLicenseScan` runs weekly. It walks every Software installed on ≥1 Computer,
skipping any whose `SoftwareCategory` is in `license_ignore_categories`, and for
each gathers install count, aggregated license seats (`number <= 0` = unlimited),
earliest expiry and publisher. **2a (always on):** software whose publisher is on
the commercial-publisher watch list and which has zero license rows is grouped by
publisher into one `Finding` (`code = SAC-LIC-MISSING-<manufacturers_id>`),
`Low`/`Medium` by the two 2a thresholds. **2b (gated on
`license_check_overdeployment`):** software with finite seats and
`installs > seats` gets one `Finding` (`code = SAC-LIC-OVER-<softwares_id>`),
severity by the installs/seats ratio (an expired license forces `High`). Both
categories use `License Compliance` and clear the same way Module 1 does. The
watch list is seeded on install from `install/data/commercial_publishers.csv`;
ambiguous / unresolvable publisher names go to the Reconcile queue.

## VM verification checklist

Run on VM-HQ-GLPI after `bin/console glpi:plugin:install sac` +
`glpi:plugin:activate sac` (check `files/_log/php-errors.log` after each step):

1. Confirm the `fields` "LifeCycle" generated table + column names, and whether it
   keys rows by `items_id`/`itemtype` or `operatingsystems_id` — `LifeCycleReader`
   probes `glpi_plugin_fields_operatingsystemlifecycles`, column `endoflife`.
   If `fields` is absent, Module 1 runs `endoflife.date`-only (logged once/run).
2. GLPI Inventory Plugin present; Collect task usable; **Deploy task disabled**
   (needed for Module 3 — verify now).
3. `riskregister` **v1.1.0+ deployed** so `ImportSupport::categoryIdByName`
   resolves the 4 category names (SAC tolerates `0` until then).
4. Decide `finding_entity` — Root (`0`) vs a dedicated `ICT` entity.
5. `bin/console migration:timestamps` after install → expect **clean** / "schema OK".
6. Run each cron once via **Setup → Automatic actions → Execute** (NOT
   `front/cron.php <name>` — numeric only on this host); confirm the
   `glpi_plugin_sac_run` rows close `ok`.
7. `endoflife.date` reachable from the GLPI host (egress) — if not, Module 1 runs
   LifeCycle-only; note it.
8. `php plugins/sac/tools/selfcheck.php` → **all PASS**.
9. Confirm `\Profile::dropdownRights` / `getRightsFor` signatures — `Profile.php`
   carries a `Dropdown::showFromArray` fallback if they have drifted.
10. Confirm `csrf_token()` is registered in GLPI 11's Twig environment — the
    `config` and `reconcile` templates call it.
11. Confirm `glpi_softwares.is_template` and `glpi_items_softwareversions.is_deleted`
    columns exist (the License candidate query filters on both).

## Suite coordination

Part of the PGN ICT GLPI 11 plugin suite (`integrasipaman`, `riskregister`, SAC),
all deployed to one instance (VM-HQ-GLPI). Shared namespace claims and integration
contracts live in `_pgn-glpi-suite.md` (not in this repo).

Claimed namespace: tables `glpi_plugin_sac_*`, config context `plugin:sac`,
PSR-4 `GlpiPlugin\Sac\` → `src/`, rights `plugin_sac_*`, cron `GlpiPlugin\Sac\Cron::*`.

### Finding pipeline contract (from `riskregister`)

- Primary path: SAC writes a CSV; import via `riskregister`'s
  `\GlpiPlugin\Riskregister\Import\ImportSupport` helper + **Management → Risk
  Register → Reconcile** for unmatched asset/owner text.
- `Finding` has UNIQUE `(entities_id, code)` — SAC must set a **stable,
  deterministic `code`** per finding for idempotent upsert
  (e.g. `SAC-EOL-<computers_id>-<software-slug>`), and a non-empty `scope_label`.
- Enums: `severity` = `Critical|High|Medium|Low|Info`,
  `status` = `Open|In Treatment|Treated|Closed`.
- `datainjection` / `FindingInjection` is **dormant** — not used until a
  VM-HQ-GLPI check proves GLPI-11 injection types work.
- SCAP `<reference>` CIS sub-control refs (e.g. `5.2`) truncate to the parent
  control number to match `riskregister`'s seeded `cis_v8.csv` (controls 1–18).

## License

GPL-3.0-or-later (GLPI plugin convention).
