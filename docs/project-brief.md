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

### In scope — three modules (build order)

| # | Module | Data source | Risk trigger | Data availability |
|---|---|---|---|---|
| 1 | **EOL/EOS** | GLPI software/OS/model inventory + `endoflife.date` API | EOL/EOS date < today (or < horizon) | 100% GLPI + 1 free API |
| 2 | **License Compliance** | native GLPI `glpi_softwarelicenses` / install counts | installs > licensed seats | 100% GLPI |
| 3 | **Config + Patch Compliance** | OpenSCAP: CIS XCCDF (config) **+ distro OVAL feeds** (patch, RHEL OVAL / Ubuntu USN) | benchmark score < threshold; per-rule fails; missing security patches | needs OpenSCAP local cron |

Start with **1 + 2** — fully derivable from GLPI data plus one free API, no
infrastructure dependency.

**Decision (2026-09-01) — patch compliance is not a separate module.** For Linux,
"is this host missing security patches" is authoritatively answered by
`oscap oval eval` against distro OVAL content — the same `oscap` run and the same
collection path as CIS config checks. So patch folds into module 3. Windows patch
compliance (inventoried KBs vs a "latest cumulative update per build" baseline) is
a thin later track, listed under §10 Deferred.

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
  `GlpiPlugin\Sac\Cron::*`.
- **Menu node (decided 2026-09-01): own node, Management → Security Assessment.**
  Not a tab under Risk Register — SAC must not depend on `riskregister`'s UI
  loading; the two integrate through Finding data, not the menu. SAC owns its own
  rights and its own operational surface (module config, run status, raw signal
  review, unmatched-asset reconcile, watermarks).
- **Per-module cron task** pulls its source, correlates to `Computer` (reusing the
  approach in `integrasipaman/inc/correlation.class.php` — parse NAME/IP → GLPI
  native), writes findings.
- **SAC keeps its own tables** for raw signal state + last-run watermarks (ETL
  watermark pattern, like `jumpserver_pbi`), so re-runs are incremental and
  idempotent.

### OpenSCAP collection — tiered (decided 2026-09-01)

- **Tier 1 (build now):** `oscap` runs locally on each host via cron (root, local
  — zero inbound access to the host). It writes a one-line summary (score,
  pass/fail counts, benchmark id, timestamp). **GLPI Agent's Collect task** reads
  that line into a per-`Computer` field. Most secure (no SSH keys, no remote exec,
  nothing listening) and easiest (transport already deployed on every server).
  Yields a compliance % per host and a coarse Finding ("host X config score 72%").
  Requires GLPI Inventory Plugin (installed — verify) and the **Deploy** task must
  stay disabled (RCE vector; Collect is read-only).
- **Tier 2 (deferred, §10):** pull the full `results.xml`/ARF for per-rule fails
  mapped to CIS control numbers → granular Findings. Transport: Kerberos/GSSAPI
  where FreeIPA is deployed (DCI Cibitung first), plain SSH elsewhere as interim.

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

- ~~**Patch Compliance source**~~ — RESOLVED 2026-09-01: not a separate module;
  Linux patch = `oscap oval eval` inside module 3; Windows patch deferred (§10).
- ~~**Menu placement**~~ — RESOLVED 2026-09-01: own node, Management → Security
  Assessment.
- ~~**OpenSCAP file collection**~~ — RESOLVED 2026-09-01: Tier 1 (local cron +
  GLPI Agent Collect summary line) now; Tier 2 (full `results.xml` pull) deferred
  to §10.

Still open:

1. **datainjection on GLPI 11** — one VM check settles whether the CSV path is
   permanent or an interim measure for both SAC and riskregister.
2. **Entity model** — single-entity or per-site findings?
3. **Finding lifecycle** — when a signal clears (software patched, license bought),
   does SAC close the Finding, or leave that to the risk owner?
4. **EOL/EOS correlation grain** — per software/version, per OS, per hardware
   model, or all three? (module 1 brainstorm)
5. **License Compliance data completeness** — are `glpi_softwarelicenses` seat
   counts actually maintained on this instance, or sparse? (module 2 brainstorm)

## 9. Next steps

1. Brainstorming pass on module 1 (EOL/EOS) + module 2 (License Compliance) —
   nail the correlation logic, the `code` scheme, severity rules, and the SAC
   table schema.
2. Write the spec.
3. Write the plan.
4. Implement modules 1–2, scaffold plugin structure, `selfcheck.php`.
5. VM verification pass alongside `riskregister`'s.

## 10. Deferred / future

- **OpenSCAP Tier 2** — full `results.xml`/ARF pull for per-rule CIS-mapped
  Findings. Transport: Kerberos/GSSAPI (FreeIPA, DCI Cibitung first) or SSH.
- **Windows patch compliance** — inventoried hotfix KBs vs a maintained "latest
  cumulative update per OS build" baseline.
- **Windows CIS config compliance** — SSG has no official Windows content; needs
  Microsoft Security Compliance Toolkit / CIS-CAT Lite / PowerShell DSC as a
  separate track.
- **CVE-to-asset correlation** — no viable GLPI 11 path today (`glpi-cve-plugin`
  archived 2021, `cve-search`+MongoDB out of scope). Revisit if a maintained
  option appears.
- **integrasipaman → Finding** — high-risk terminal command on a production asset
  as a candidate Finding (owned by the integrasipaman side; noted so neither
  designs it away).
