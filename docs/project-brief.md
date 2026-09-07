# SAC — Project Brief

**Plugin:** Security-Asset Correlation (SAC)
**Repo:** `teguh-umar/security-asset-correlation` (private)
**Session:** `sec-assessmant-korelasi-cve-78` `[5c886d]` (was `-cc`)
**Status:** Design stage — Module 1 design complete, no plugin code yet
**Last updated:** 2026-09-03

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
| 1 | **EOL/EOS** | `fields` "LifeCycle" `EndOfLife` date on Operating Systems + `endoflife.date` API fallback; native warranty for hardware | resolved EOL date < today (or < horizon) | 100% GLPI + 1 free API |
| 2 | **License Compliance** | native GLPI `glpi_softwarelicenses` / install counts | installs > licensed seats | 100% GLPI |
| 3 | **Config + Patch Compliance** | OpenSCAP: CIS XCCDF (config) **+ distro OVAL feeds** (patch, RHEL OVAL / Ubuntu USN) | benchmark score < threshold; per-rule fails; missing security patches | needs OpenSCAP local cron |

Start with **1 + 2** — fully derivable from GLPI data plus one free API, no
infrastructure dependency.

**Decision (2026-09-01) — patch compliance is not a separate module.** For Linux,
"is this host missing security patches" is authoritatively answered by
`oscap oval eval` against distro OVAL content — the same `oscap` run and the same
collection path as CIS config checks. So patch folds into module 3. Windows patch
compliance (inventoried KBs vs a "latest cumulative update per build" baseline) is
a thin later track, listed under §11 Deferred.

### Out of scope

- CVE-to-asset correlation (no viable GLPI 11 path)
- The risk model itself — scoring, treatment, framework mapping, review cycles
  all belong to `riskregister`
- PAMAN (FastAPI) — external consumer, not a GLPI plugin
- Windows CIS via OpenSCAP — SSG has no official Windows content; separate track
- Being an audit-grade CIS assessor — SSG is not CIS-CAT Pro; internal use, note
  the source on formal evidence

## 4. Architecture

```
  fields "LifeCycle" ─┐
  endoflife.date API ─┤
  native warranty    ─┼─▶  SAC cron tasks  ─▶  normalise to Finding input  ─▶  direct in-process
  OpenSCAP summary   ─┘      (per module)       (deterministic `code`)          itemtype API
                                                                                    │
                              \GlpiPlugin\Riskregister\Finding->add()/update()  ◀────┘
                              + Finding_Item links + raw_asset_text for unresolved
                                        │
                                        ▼
                              Finding → Risk (5×5, ISO 27001 / CIS v8), review cron  [riskregister]
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
- **SAC keeps its own tables** for signal mapping, API cache, reconcile queue, and
  last-run watermarks, so re-runs are incremental and idempotent.

### Design principle — upgrade resilience (decided 2026-09-01)

SAC must survive GLPI minor/major version bumps (and a future core shift of more
rendering to Twig) with minimal rework:

- **Only call GLPI core helpers for form controls** — `Dropdown::showFromArray()`,
  `Class::dropdown()`, or Twig `fields.*` macros. All delegate to core internals;
  when core moves them to Twig, SAC's call sites are unaffected.
- **Never hand-roll `<select>` / form HTML in strings** — the one pattern that
  breaks on a version bump.
- **One render site per itemtype** (`showForm()` or a thin per-field helper) so a
  deliberate full-Twig rewrite of a screen is a single localized change.
- Matches `riskregister`'s existing pattern — suite-consistent and upgrade-safe.

### OpenSCAP collection — tiered (decided 2026-09-01)

- **Tier 1 (build now):** `oscap` runs locally on each host via cron (root, local
  — zero inbound access to the host). It writes a one-line summary (score,
  pass/fail counts, benchmark id, timestamp). **GLPI Agent's Collect task** reads
  that line into a per-`Computer` field. Most secure (no SSH keys, no remote exec,
  nothing listening) and easiest (transport already deployed on every server).
  Yields a compliance % per host and a coarse Finding ("host X config score 72%").
  Requires GLPI Inventory Plugin (installed — verify) and the **Deploy** task must
  stay disabled (RCE vector; Collect is read-only).
- **Tier 2 (deferred, §11):** pull the full `results.xml`/ARF for per-rule fails
  mapped to CIS control numbers → granular Findings. Transport: Kerberos/GSSAPI
  where FreeIPA is deployed (DCI Cibitung first), plain SSH elsewhere as interim.

## 5. Integration contract with `riskregister`

Per the **pinned external `Finding` API contract** (hub §7, 2026-09-01, and hub §5
as updated by `risk-register-dashboard-06` on 2026-09-03):

- **Primary path = direct in-process itemtype API** (not CSV). SAC's cron runs in
  the same GLPI process:

  ```php
  if (!\Plugin::isPluginActive('riskregister')) { /* log "skipped", exit 0 */ }

  use GlpiPlugin\Riskregister\Finding;
  use GlpiPlugin\Riskregister\Import\ImportSupport;

  $f   = new Finding();
  $hit = $f->find(['entities_id' => $eid, 'code' => $code]);
  $hit ? $f->update(['id' => array_key_first($hit)] + $input) : $f->add($input);

  // link resolved assets:
  (new \GlpiPlugin\Riskregister\Finding_Item())->add([
      'plugin_riskregister_findings_id' => $id,
      'itemtype' => 'Computer', 'items_id' => $cid,
  ]);
  ```

- **`Finding` fields** SAC sets: `name` (**required**, ≤255), `code` (**required**,
  deterministic, ≤50, unique key `(entities_id, code)`), `scope_label`
  (**required**, non-empty), `severity` (`Critical|High|Medium|Low|Info`),
  `status` (`Open|In Treatment|Treated|Closed` — set on create only),
  `plugin_riskregister_riskcategories_id` (via `ImportSupport::categoryIdByName()`),
  `content`, `cause`, `recommendation`, `date_due`, `assessment_ref` (source
  marker, e.g. `SAC/eol`), `raw_asset_text` (unresolved hosts → Reconcile).
  **Never** set `id`, `is_deleted`, `date_creation`, `date_mod`.
- **Active detection:** `\Plugin::isPluginActive('riskregister')` —
  `class_exists('PluginRiskregisterFinding')` is always false (no legacy alias).
- **CSV export** is kept as a **secondary / audit** output only (and for the
  external-evidence use case, e.g. the server-refresh KAK). Not the pipeline.
- **`datainjection` / `FindingInjection` is dormant** — not used unless a
  VM-HQ-GLPI check proves GLPI 11 injection types work.
- **Risk categories** — riskregister **v1.1.0** (shipped 2026-09-03, tag `1.1.0`,
  merge `7619e40`) seeds 4 flat `RiskCategory` names: `Obsolescence` (M1),
  `License Compliance` (M2), `Patch Compliance` (M3 patch),
  `Configuration Compliance` (M3 config). SAC calls
  `ImportSupport::categoryIdByName()` with these exact strings.
  **`categoryIdByName()` returns 0 on miss — it does NOT auto-create.** So until
  v1.1.0 is deployed on VM-HQ-GLPI, SAC Findings get category 0 (uncategorized) —
  harmless, self-corrects on deploy. SAC must tolerate a 0 return.
- **CIS mapping:** SCAP `<reference>` CIS sub-control refs (e.g. `5.2`) truncate
  to the parent control number to match `riskregister`'s seeded `cis_v8.csv`
  (top-level controls 1–18 only). Ask `riskregister` to seed sub-controls if finer
  grain is needed (module 3).
- **riskregister does NOT auto-promote Finding → Risk** — a human does that. SAC's
  job ends at emitting a Finding.

## 6. Module 1 — EOL/EOS (design complete 2026-09-03)

### 6.1 Data flow

Cron task `GlpiPlugin\Sac\Cron::EolScan`, **weekly**.

1. **Build OS→assets map** — `glpi_operatingsystems` joined through
   `glpi_items_operatingsystems` → per OS, the list of asset IDs + their entities.
2. **Resolve the EOL date per OS**, in priority order:
   - **a.** `fields` "LifeCycle" container on Operating Systems (Block 16) →
     `EndOfLife` **Date** field, read-only from the fields-generated table (name to
     confirm on VM, likely `glpi_plugin_fields_operatingsystemlifecycles`). If set
     → use it. Source tag: `lifecycle`.
   - **b.** else — look up the OS in `glpi_plugin_sac_eolmap` → read `eol_date`
     from `glpi_plugin_sac_eolcache`. Source tag: `endoflife.date`.
   - **c.** else — write/refresh a `glpi_plugin_sac_reconcile` row, emit no Finding.
3. **Compute severity** from the resolved date vs. run date:

   | Condition | Severity |
   |---|---|
   | EOL date in the past | `Critical` |
   | EOL within 90 days | `High` |
   | EOL within 180 days | `Medium` |
   | EOL within 365 days | `Low` |
   | EOL > 365 days away | *no Finding* |

4. **Upsert one Finding per (OS, entity)** where severity ≠ none:
   - `code` = `SAC-EOL-OS-<operatingsystems_id>` (stable, deterministic, ≤50)
   - `name` = e.g. `"EOL: Windows Server 2012 R2"`
   - `scope_label` = `"<OS name> ×<host count>"`
   - `severity` = computed
   - `content` = OS name, resolved EOL date, source, list of affected hostnames
   - `assessment_ref` = `"SAC/eol"`
   - category via `ImportSupport::categoryIdByName("Obsolescence", $eid)`
   - `status` = `Open` **on create only** — never touched on update
   - `Finding_Item` rows for each **resolved** Computer; `raw_asset_text` carries
     any hostnames that don't resolve (→ riskregister Reconcile)
5. **Hardware EOL** — native warranty (`warranty_date` + `warranty_duration`) on
   Computer; a per-asset Finding when warranty end is past / near. (Hardware-vendor
   lifecycle source deferred, §11.)
6. **Signal cleared** (EOL date pushed past 365 days, or OS gone from inventory):
   SAC appends a "signal cleared on `<date>`" line to `content` and stops
   re-asserting. It does **not** auto-close — the risk owner closes.

### 6.2 SAC's own tables

All `glpi_plugin_sac_*`, created by the `Migration` helper (hub §4). Timestamp
columns use GLPI's canonical `timestamp NULL DEFAULT NULL` (hub §7 —
`migration:timestamps` gotcha).

| Table | Purpose | Key columns |
|---|---|---|
| `glpi_plugin_sac_eolmap` | OS → `endoflife.date` identity, admin-confirmed via Reconcile | `operatingsystems_id`, `eol_product` (slug), `eol_cycle`, `confidence` (`auto`/`confirmed`), `confirmed_by`, `date_confirmed` |
| `glpi_plugin_sac_eolcache` | local mirror of `endoflife.date`, own refresh cron | `eol_product`, `eol_cycle`, `eol_date`, `eos_date`, `support_date`, `is_lts`, `date_fetched` |
| `glpi_plugin_sac_reconcile` | unresolved items needing an admin decision — **generic across modules** | `signal_type` (`eol`/`license`/`config`), `ref_itemtype`, `ref_id`, `reason` (`no_mapping`/`bad_date`/`api_disagreement`), `detail`, `date_firstseen`, `date_lastseen`, `is_resolved` |
| `glpi_plugin_sac_run` | per-module run audit / watermark | `module`, `date_start`, `date_end`, `status` (`running`/`ok`/`error`/`stale`), `counts_json` (scanned / findings_upserted / reconcile_open / errors) |

`eolcache` is the only module-1-specific table. `eolmap`/`reconcile` are built
generic (`signal_type` + polymorphic `ref_*`) so modules 2–3 reuse them.

### 6.3 Error handling

| Condition | Behavior |
|---|---|
| `endoflife.date` unreachable / timeout / bad HTTP | Use existing cache; log warning; **don't fail the run**. `errors++`. |
| LifeCycle `EndOfLife` = `0000-00-00` / empty / garbage | Skip OS; `_reconcile` row `reason=bad_date`. |
| `fields` inactive or LifeCycle table missing | `$DB->tableExists()` guard → `endoflife.date`-only mode; log once. |
| `riskregister` inactive | Log "skipped: riskregister inactive"; exit 0 — not an error. |
| `Finding->add()` returns `false` | Log input + reason; `errors++`; **continue** — one bad Finding never aborts the batch. |
| PHP fatal mid-loop | `_run` row left `running`; next run marks it `stale`, proceeds. |

### 6.4 Testing

- **Offline** (`php -l` + stubs, `C:\php`): pure functions — `severityForDate()`
  (5 bands + boundaries), the source-priority resolver, the `code` builder, slug
  lookup. No DB.
- **VM** (`tools/selfcheck.php`, `GlpiPlugin\Sac\*::selfChecks()`, hub §4 pattern):
  seed OS + Computer, LifeCycle past date → assert one `Critical` Finding with the
  right `code`, a `Finding_Item`, category `Obsolescence`; date → today+120 →
  assert `Medium` + **no duplicate** (idempotency); blank date + no map → no
  Finding + one `_reconcile` row; cleared signal → "signal cleared" content line,
  `status` untouched; teardown.

### 6.5 Cron

- `GlpiPlugin\Sac\Cron::EolScan` — weekly. `CronTask::register(\GlpiPlugin\Sac\Cron::class,
  'EolScan', WEEK_TIMESTAMP, [...])` → GLPI dispatches `Cron::cronEolScan($task)`.
- `GlpiPlugin\Sac\Cron::EolCacheRefresh` — weekly, offset a few days before
  `EolScan`.
- Both light — no 490 s risk. Manual trigger for testing = Setup → Automatic
  actions → **Execute** (hub §3: `front/cron.php <taskname>` does not work — it
  takes a numeric count only).

## 7. Deployment target — VM-HQ-GLPI

Shared with `integrasipaman` (live) and `riskregister` (first real VM run passed
2026-09-03). Facts that constrain SAC (hub §3):

- Host `10.129.2.174` via JumpServer bastion `paman.pgn.co.id:2222`
- GLPI root `/data/www/glpi`, plugins at `/data/www/glpi/plugins/<name>`
- nginx + php-fpm **~490 s request timeout** — heavy correlation runs from CLI
- **No `bin/console` cron.** `sudo -u nginx php /data/www/glpi/front/cron.php <N>`
  — **numeric count only**; a task name casts to 0. Force one task via Setup →
  Automatic actions → Execute, or let the every-minute system cron pick it up.
- `bin/console glpi:plugin:install|activate|list` **do** exist
- No `tmux`/`screen` — `nohup` / `setsid`
- Shared `/etc/cron.d/glpi` (1-minute tick) drives all plugins' cron tasks
- **`migration:timestamps` gotcha** — hand-written raw `timestamp` columns can get
  flagged on this long-upgraded instance. Use GLPI's canonical timestamp form for
  `date_creation`/`date_mod`. Watch for `Plugin.php:882` "Unable to load plugin
  information" on first upload = clear OPcache (`systemctl reload php-fpm`).
- **`canView(): bool` gotcha** (hub §4, cost riskregister a VM outage) — GLPI
  11.0.x `CommonGLPI::canView()` declares `: bool`. An override **without** the
  return type is a fatal compile error that takes down every page including login.
  SAC's `Menu` class → `public static function canView(): bool`; same for the full
  can* set (`canCreate`/`canView`/`canUpdate`/`canDelete`/`canPurge`) on any
  `CommonDBRelation` subclass. Leave other core overrides untyped (`defineTabs`,
  `rawSearchOptions`, `prepareInputFor*`, `post_*Item`, `cleanDBonPurge`,
  `getSpecificValueTo*` — no parent return type).
- **Tab-hook static-ness gotcha** (hub §4, cost riskregister v1.1.2) — match the
  *immediate parent* class's static-ness for tab hooks, not `CommonGLPI`'s. If SAC
  adds a rights tab by extending `\Profile`: `getTabNameForItem()` is a **non-static
  instance** method, `displayTabContentForItem()` stays **static** (asymmetric —
  `\Profile` keeps the former non-static while making the latter static). `grep`
  the parent file for `function getTabNameForItem` before writing any override.
- **Menu `options` sub-entry gotcha** (hub §4, riskregister v1.1.3) — SAC's `Menu`
  has sub-entries (per-module finding views + Reconcile + Setup). For the
  breadcrumb "+ Add" button to appear: each front controller passes the sub-menu
  key as `Html::header()`'s **5th arg** (`Html::header($t,'','management',Menu::class,'<key>')`);
  `displayFullPageForItem()` sets `$menus['central'] = ['management', Menu::class, '<key>']`;
  `getMenuContent()` sets `options.<key>.links['add'] = X::getFormURL(false)` gated
  on `X::canCreate()`.
- Installed base plugins: `datainjection`, `glpiinventory`, `mreporting`,
  `reports`, `pdf`, **`fields` v1.23.2** (GLPI 11 compatible; has the "LifeCycle"
  container on Operating Systems), `tag`, `telegrambot`
- **`addressing` plugin** (`glpi_plugin_addressing_addressings`, `begin_ip..end_ip`,
  `is_deleted`-guarded) is the production-scope source on the VM — **not** core
  `glpi_ipnetworks`. Named ranges: "Prodcution IT" 10.129.10.1–10.129.11.254,
  "Production OT" 10.129.18.0–10.129.19.254. SAC module 3 uses this for asset
  scoping.
- **No local GLPI runtime** — `C:\php\php.exe` is PHP 8.4 CLI, no mbstring, no
  php.ini. `php -l` + offline stubs only. Runtime verification = a pass on
  VM-HQ-GLPI with the user running commands.
- **Author/license** (SUITE DIRECTIVE, hub §7): `setup.php` `'author' => 'PGN ICT'`,
  `'license' => 'GPL-3.0-or-later'`; `composer.json` `"GPL-3.0-or-later"`; repo
  `LICENSE` = full GPL-3.0 text (done); README/CHANGELOG → `GPL-3.0-or-later`; no
  file-header retrofit.

## 8. Suite coordination protocol (hub §0)

1. Read `_pgn-glpi-suite.md` at session start.
2. Before creating any shared-namespace resource (table, right, config
   key/context, menu node, cron task/action, PSR-4 namespace, `$PLUGIN_HOOKS`
   entry) or inter-plugin integration point → check hub §2 for collision, append
   a dated §7 entry, bump the header "Last updated" line.
3. Real-time handoff → `SendMessage` to the §6 address; reply to sender.
4. Never raw-write another plugin's tables — use its itemtype API or importer.

Peer sessions (names rotate — re-check with `ListAgents`): `risk-register-dashboard-06`
(riskregister), `jumpserver-integration-dashboard-46` (integrasipaman),
`drill-report-glpi-a4` (drillreport). `refreshment-critical-server-eos-*` are
**not** suite plugins (procurement KAK/ToR; downstream consumer of SAC EOL findings
at most).

## 9. Open questions

Resolved: patch-source (→ M3), menu placement (own node), OpenSCAP collection
(tiered), EOL correlation grain (per-OS), EOL severity thresholds, Finding write
path (direct API), risk-category names.

Still open:

1. **datainjection on GLPI 11** — one VM check settles whether the CSV path ever
   matters (it's already secondary for SAC).
2. **Entity model** — single-entity or per-site findings? (affects the
   `(entities_id, code)` upsert loop — currently designed per-entity)
3. **License Compliance data completeness** — are `glpi_softwarelicenses` seat
   counts actually maintained on this instance, or sparse? (module 2 brainstorm)
4. **VM verification** of: the `fields` LifeCycle generated-table name; whether the
   GLPI Inventory Plugin Collect task is usable; Deploy task disabled.

## 10. Next steps

1. ~~Brainstorm module 1 (EOL/EOS)~~ — **done**, §6.
2. Brainstorm module 2 (License Compliance) — correlation logic, `code` scheme,
   severity, whether it reuses the generic tables only.
3. Write the spec (`docs/superpowers/specs/`).
4. Write the plan.
5. Implement: plugin scaffold (`setup.php`, `composer.json` PSR-4, `Migration`,
   `Menu`, `RightDefinition`, `Config`, `selfcheck.php`) → module 1 → module 2.
6. VM verification pass alongside `riskregister`'s.

## 11. Deferred / future

- **OpenSCAP Tier 2** — full `results.xml`/ARF pull for per-rule CIS-mapped
  Findings. Transport: Kerberos/GSSAPI (FreeIPA, DCI Cibitung first) or SSH.
- **Windows patch compliance** — inventoried hotfix KBs vs a maintained "latest
  cumulative update per OS build" baseline.
- **Windows CIS config compliance** — SSG has no official Windows content; needs
  Microsoft Security Compliance Toolkit / CIS-CAT Lite / PowerShell DSC as a
  separate track.
- **Hardware-vendor lifecycle source** — for hardware-model EOL beyond native
  warranty dates.
- **CVE-to-asset correlation** — no viable GLPI 11 path today. Revisit if a
  maintained option appears.
- **EOL evidence export for the server-refresh KAK** — a CSV "these N OSes on M
  hosts are past EndOfLife as of `<date>`" for `refreshment-critical-server-eos-*`.
- **integrasipaman → Finding** — high-risk terminal command on a production asset
  as a candidate Finding (owned by the integrasipaman side).
