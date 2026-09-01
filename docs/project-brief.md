# SAC — Project Brief

**Plugin:** Security-Asset Correlation (SAC)
**Repo:** `teguh-umar/security-asset-correlation` (private)
**Session:** `sec-assessmant-korelasi-cve-cc`
**Status:** Brainstorm stage — repo scaffolded, no plugin code
**Last updated:** 2026-09-01

---

## 1. Problem

GLPI is an asset/ITSM system, not a security tool. The organisation has security
signals (end-of-life software, license shortfalls, missing patches, CIS
misconfigurations) scattered across scanners, spreadsheets, and OS tooling, with
no link back to *which asset* and *whose risk* each signal represents.

CVE-to-asset correlation was explored first and dropped: no maintained GLPI 11
plugin exists (`glpi-cve-plugin` archived 2021), and a full `cve-search` +
MongoDB deployment is out of scope. The **non-CVE** security signals are higher
value and mostly already in GLPI's own data.

## 2. Goal

A GLPI 11 plugin that periodically correlates non-CVE security signals to GLPI
assets and emits `Finding` records into the `riskregister` plugin, which owns the
risk model. SAC is a **feeder**, not a risk system.

## 3. Scope

### In scope — four modules (build order)

| # | Module | Data source | Risk trigger | Data availability |
|---|---|---|---|---|
| 1 | **EOL/EOS** | GLPI software/OS/model inventory + `endoflife.date` API | EOL/EOS date < today (or < horizon) | 100% GLPI + 1 free API |
| 2 | **License Compliance** | native GLPI `glpi_softwarelicenses` / install counts | installs > licensed seats | 100% GLPI |
| 3 | **Patch Compliance** | patch-status source, TBD (WSUS / Sangfor SCP / other) | pending patches > threshold, or age > SLA | needs external source |
| 4 | **Config Compliance** | OpenSCAP + SCAP Security Guide (CIS-aligned) results XML | benchmark pass score < threshold; per-rule fails | needs OpenSCAP rollout |

Start with **1 + 2** — fully derivable from GLPI data plus one free API, no
infrastructure dependency.

### Out of scope

- CVE-to-asset correlation (no viable GLPI 11 path)
- The risk model itself — scoring, treatment, framework mapping, review cycles
  all belong to `riskregister`
- PAMAN (FastAPI) — external consumer, not a GLPI plugin
- Windows CIS via OpenSCAP — SSG has no official Windows content; separate track
- Being an audit-grade CIS assessor — SSG is not CIS-CAT Pro; internal use, note
  the source on formal evidence

## 4. Architecture (proposed, not final)

```
  GLPI inventory ──┐
  endoflife.date ──┤
  patch source   ──┼──▶  SAC cron tasks  ──▶  normalise to Finding rows  ──▶  CSV
  OpenSCAP XML   ──┘         (per module)          (deterministic code)        │
                                                                               ▼
                              riskregister: ImportSupport upsert on `code`
                              + Management → Risk Register → Reconcile
                                        │
                                        ▼
                              Finding → Risk (5×5, ISO 27001 / CIS v8), review cron
```

- **Namespace (claimed, hub §2):** tables `glpi_plugin_sac_*`, config context
  `plugin:sac`, PSR-4 `GlpiPlugin\Sac\` → `src/`, rights `plugin_sac_*`, cron
  `GlpiPlugin\Sac\Cron::*`, menu node TBD (Management → Security Assessment, or a
  tab under Risk Register).
- **Per-module cron task** pulls its source, correlates to `Computer` (reusing the
  approach in `integrasipaman/inc/correlation.class.php` — parse NAME/IP → GLPI
  native), writes findings.
- **SAC keeps its own tables** for raw signal state + last-run watermarks (ETL
  watermark pattern, like `jumpserver_pbi`), so re-runs are incremental and
  idempotent.

## 5. Integration contract with `riskregister`

Confirmed by the `risk-register-dashboard-df` session:

- **Pipeline = CSV path.** SAC writes a CSV; import via
  `\GlpiPlugin\Riskregister\Import\ImportSupport` (`categoryIdByName`,
  `placeholderOwnerGroupId`) + the Reconcile page for unmatched asset/owner text.
- **`Finding` has UNIQUE `(entities_id, code)`.** SAC must set a **stable,
  deterministic `code`** per finding for idempotent upsert, e.g.
  `SAC-EOL-<computers_id>-<software-slug>` (hash the tail if long).
- **`scope_label` must be non-empty** (asset name / area).
- **Enums:** `severity` = `Critical|High|Medium|Low|Info`,
  `status` = `Open|In Treatment|Treated|Closed`.
- **`datainjection` / `FindingInjection` is dormant** — not used unless a
  VM-HQ-GLPI check proves GLPI 11 injection types work.
- **CIS mapping:** SCAP `<reference>` CIS sub-control refs (e.g. `5.2`) truncate
  to the parent control number to match `riskregister`'s seeded `cis_v8.csv`
  (top-level controls 1–18 only). Ask `riskregister` to seed sub-controls if finer
  grain is needed.
- Fields SAC may set on `Finding`: `name, code, plugin_riskregister_riskcategories_id,
  content, cause, scope_label, severity, recommendation, status, date_due,
  assessment_ref, raw_asset_text, raw_control_text`.

## 6. Deployment target — VM-HQ-GLPI

Shared with `integrasipaman` (live) and `riskregister` (pending verification).
Facts that constrain SAC (hub §3):

- Host `10.129.2.174` via JumpServer bastion `paman.pgn.co.id:2222`
- GLPI root `/data/www/glpi`, plugins at `/data/www/glpi/plugins/<name>`
- nginx + php-fpm **~490 s request timeout** — heavy correlation runs from CLI
- **No `bin/console` cron** — `sudo -u nginx php /data/www/glpi/front/cron.php <name>`
- `bin/console glpi:plugin:install|activate|list` **do** exist
- No `tmux`/`screen` — `nohup` / `setsid`
- Shared `/etc/cron.d/glpi` (1-minute tick) drives all plugins' cron tasks
- Installed base plugins: `datainjection`, `glpiinventory`, `mreporting`,
  `reports`, `pdf`, `fields`, `tag`, `telegrambot`
- **No local GLPI runtime** — `C:\php\php.exe` is PHP 8.4 CLI, no mbstring, no
  php.ini. `php -l` + offline stubs only. Runtime verification = a pass on
  VM-HQ-GLPI with the user running commands.

## 7. Suite coordination protocol (hub §0)

1. Read `_pgn-glpi-suite.md` at session start.
2. Before creating any shared-namespace resource (table, right, config
   key/context, menu node, cron task/action, PSR-4 namespace, `$PLUGIN_HOOKS`
   entry) or inter-plugin integration point → check hub §2 for collision, append
   a dated §7 entry.
3. Real-time handoff → `SendMessage` to the §6 address; reply to sender.
4. After editing the hub, bump the header "Last updated" line.
5. Never raw-write another plugin's tables — use its itemtype API or importer.

Peer sessions: `risk-register-dashboard-df` (riskregister),
`jumpserver-integration-dashboard-32` (integrasipaman).

## 8. Open questions

1. **Patch Compliance source** — WSUS? Sangfor SCP? Something else? Determines
   whether module 3 is native-ish or needs a connector.
2. **Menu placement** — own node (Management → Security Assessment) vs a tab under
   Risk Register. Affects rights design.
3. **OpenSCAP file collection** — GLPI Agent Collect can only extract one summary
   line; full `results.xml` needs SSH pull. FreeIPA/Kerberos vs plain SSH (GLPI
   has full server access). Pilot: DCI Cibitung, RHEL first.
4. **datainjection on GLPI 11** — one VM check settles whether the CSV path is
   permanent or an interim measure for both SAC and riskregister.
5. **Entity model** — single-entity or per-site findings?
6. **Finding lifecycle** — when a signal clears (software patched, license bought),
   does SAC close the Finding, or leave that to the risk owner?

## 9. Next steps

1. Brainstorming pass on module 1 (EOL/EOS) + module 2 (License Compliance) —
   nail the correlation logic, the `code` scheme, severity rules, and the SAC
   table schema.
2. Write the spec.
3. Write the plan.
4. Implement modules 1–2, scaffold plugin structure, `selfcheck.php`.
5. VM verification pass alongside `riskregister`'s.
