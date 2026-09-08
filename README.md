# Security-Asset Correlation (SAC)

GLPI 11 plugin. Correlates **non-CVE security signals** to GLPI assets and emits
`Finding` records into the [`riskregister`](https://github.com/teguh-umar/risk-register-dashboard)
plugin, which owns the risk model (Finding → Risk, 5×5 scoring, ISO 27001 / CIS v8
mapping).

> **Status: Modules 1 & 2 in development.** Building EOL/EOS and License Compliance modules.

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
