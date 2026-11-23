# SAC — Modules 1 & 2 Design Spec

**Plugin:** Security-Asset Correlation (`sac`) — GLPI 11 plugin
**Repo:** `teguh-umar/security-asset-correlation`
**Scope of this spec:** the plugin scaffold + Module 1 (EOL/EOS) + Module 2
(License Governance). Module 3 (Config + Patch Compliance / OpenSCAP) is a later
spec.
**Date:** 2026-09-07
**Status:** for review

Companion: `docs/project-brief.md` (living overview + decision log). Suite context:
`…/Documents/_pgn-glpi-suite.md` (hub §2 namespace, §3 VM facts, §4 GLPI 11
patterns, §5 Finding contract).

---

## 1. Goal

A GLPI 11 plugin that, on a schedule, correlates two non-CVE security signals to
GLPI assets and emits `Finding` records into the `riskregister` plugin:

1. **EOL/EOS** — operating systems (and hardware) past or nearing end-of-life.
2. **License Governance** — commercial software with no license record, and
   (optionally) license over-deployment.

SAC is a **feeder**. It does not score risk, map controls, or run review cycles —
that is `riskregister`'s job. SAC's responsibility ends at a well-formed `Finding`.

## 2. Non-goals

- CVE-to-asset correlation (no viable GLPI 11 path).
- The risk model — scoring, treatment, framework mapping, review cadence.
- Being an audit-grade license or configuration assessor.
- Writing to any `glpi_plugin_riskregister_*` table directly.
- Windows-specific tracks (CIS config, patch KB baseline) — deferred.

## 3. Architecture

### 3.1 Plugin skeleton

| Concern | Choice |
|---|---|
| Namespace | PSR-4 `GlpiPlugin\Sac\` → `src/` (`composer.json` `autoload.psr-4`) |
| Table prefix | `glpi_plugin_sac_*` |
| Config context | `plugin:sac` |
| Profile rights | `plugin_sac_finding` (view SAC's finding views + reconcile), `plugin_sac_config` (Setup) |
| Menu | own **top-level sidebar sector** — `$PLUGIN_HOOKS['menu_toadd']['sac'] = ['sac' => [Menu::class]]` |
| Cron | `GlpiPlugin\Sac\Cron` with actions `EolScan`, `EolCacheRefresh`, `LicenseScan` |
| `csrf_compliant` | `$PLUGIN_HOOKS['csrf_compliant']['sac'] = true` |
| `author` / `license` | `'ICT'` / `'GPL-3.0-or-later'` (suite directive) |

Files: `setup.php`, `composer.json`, `hook.php` (install/uninstall), `src/*.php`,
`templates/*.html.twig`, `front/*.php`, `ajax/*.php`, `install/data/*.csv` (seeds),
`tools/selfcheck.php`, `LICENSE` (present), `README.md`, `CHANGELOG.md`.

### 3.2 Menu (`GlpiPlugin\Sac\Menu extends CommonGLPI`)

`getMenuContent()` returns:

```php
[
  'is_multi_entries' => true,
  'title' => self::getTypeName(),          // sector label — REQUIRED sibling
  'icon'  => 'ti ti-shield-search',
  'content' => [
    'eol'       => ['title' => __('EOL / EOS', 'sac'),        'page' => '/plugins/sac/front/eolfinding.php',     'icon' => '...'],
    'license'   => ['title' => __('License governance', 'sac'),'page' => '/plugins/sac/front/licensefinding.php', 'icon' => '...'],
    'reconcile' => ['title' => __('Reconcile', 'sac'),         'page' => '/plugins/sac/front/reconcile.php',       'icon' => '...'],
    'runs'      => ['title' => __('Scan runs', 'sac'),         'page' => '/plugins/sac/front/run.php',             'icon' => '...'],
    'setup'     => ['title' => __('Setup', 'sac'),             'page' => '/plugins/sac/front/config.php',          'icon' => '...'],
  ],
]
```

- `canView(): bool` — **must** carry the `: bool` return type (GLPI 11.0.x; an
  untyped override is a fatal that takes down every page). Returns
  `Session::haveRight('plugin_sac_finding', READ) || Session::haveRight('plugin_sac_config', READ)`.
- Each front controller: `Html::header($title, '', 'sac', '<entry key>')`.
- Landing entry: `eol`.

### 3.3 Config (`GlpiPlugin\Sac\Config`, context `plugin:sac`)

| Key | Default | Meaning |
|---|---|---|
| `finding_entity` | `0` | Entity all SAC Findings are created in (Root Entity) |
| `finding_recursive` | `true` | create Findings with `is_recursive = 1` so every sub-entity under `finding_entity` sees them (decided 2026-09-07: "Root entity with sub-entities checked") |
| `eol_scan_enabled` | `true` | Module 1 master switch |
| `eol_horizon_days` | `365` | max days-to-EOL that still produces a Finding |
| `eol_severity_high_days` | `90` | ≤ this → High |
| `eol_severity_medium_days` | `180` | ≤ this → Medium |
| `eol_hardware_enabled` | `true` | also emit hardware-warranty EOL Findings (in v1) |
| `eol_hardware_horizon_days` | `180` | warranty-end horizon |
| `eol_cache_max_age_days` | `180` | `EolCacheRefresh` only re-fetches an `endoflife.date` product whose `date_fetched` is older than this (configurable 6-monthly / yearly) |
| `license_scan_enabled` | `true` | Module 2 master switch |
| `license_check_overdeployment` | `false` | enable Finding type 2b |
| `license_ignore_categories` | `["system_component"]` | software category system-names excluded from 2a |
| `license_2a_products_threshold` | `5` | > this many unlicensed products from one publisher → 2a Medium |
| `license_2a_installs_threshold` | `20` | > this many unlicensed installs from one publisher → 2a Medium |

`Config::DEFAULTS` const; `setDefaults()` tops up missing keys via
`array_diff_key` + `\Config::setConfigurationValues($context, $missing)`.

### 3.4 Tables (created by `GlpiPlugin\Sac\Migration`)

All timestamp columns use GLPI's canonical `timestamp NULL DEFAULT NULL`
(`migration:timestamps` gotcha, hub §7). Uninstall drops all
`glpi_plugin_sac_%` by prefix.

**`glpi_plugin_sac_eolmap`** — OS → `endoflife.date` identity (admin-confirmed).

| col | type | notes |
|---|---|---|
| `id` | int unsigned AI PK | |
| `operatingsystems_id` | int unsigned | FK `glpi_operatingsystems`; UNIQUE |
| `eol_product` | varchar(100) | endoflife.date product slug |
| `eol_cycle` | varchar(50) | cycle key, e.g. `2019`, `8`, `20.04` |
| `confidence` | enum(`auto`,`confirmed`) | `auto` = suggested by matcher; `confirmed` = admin approved |
| `users_id_confirmed` | int unsigned | nullable |
| `date_confirmed` | timestamp | nullable |
| `date_creation`,`date_mod` | timestamp | |

**`glpi_plugin_sac_eolcache`** — local mirror of `endoflife.date`.

| col | type | notes |
|---|---|---|
| `id` | int unsigned AI PK | |
| `eol_product` | varchar(100) | UNIQUE (`eol_product`,`eol_cycle`) |
| `eol_cycle` | varchar(50) | |
| `eol_date` | date | nullable (endoflife.date `eol` can be bool/false → null) |
| `eos_date` | date | nullable (`support`) |
| `is_lts` | tinyint(1) | |
| `raw_json` | text | the raw API row, for audit |
| `date_fetched` | timestamp | |

**`glpi_plugin_sac_commercialpublishers`** — Module 2 scope.

| col | type | notes |
|---|---|---|
| `id` | int unsigned AI PK | |
| `manufacturers_id` | int unsigned | FK `glpi_manufacturers`; UNIQUE |
| `is_active` | tinyint(1) default 1 | |
| `date_creation`,`date_mod` | timestamp | |

Seeded on install from `install/data/commercial_publishers.csv` (publisher name
strings; install resolves each to a `manufacturers_id`, creating the
`Manufacturer` if absent, and writes rows). ~15 names: Microsoft, Oracle, VMware,
Adobe, Autodesk, Red Hat, IBM, SAP, Citrix, Broadcom, Veritas, Micro Focus,
Atlassian, JetBrains, Fortinet.

**`glpi_plugin_sac_reconcile`** — generic across modules.

| col | type | notes |
|---|---|---|
| `id` | int unsigned AI PK | |
| `signal_type` | enum(`eol`,`license`,`config`) | |
| `ref_itemtype` | varchar(100) | e.g. `OperatingSystem`, `Manufacturer` |
| `ref_id` | int unsigned | |
| `reason` | enum(`no_mapping`,`bad_date`,`api_disagreement`,`ambiguous`) | |
| `detail` | text | human-readable context |
| `date_firstseen` | timestamp | |
| `date_lastseen` | timestamp | bumped every run the item is still unresolved |
| `is_resolved` | tinyint(1) default 0 | admin closes it, or a later run auto-resolves |
| UNIQUE | (`signal_type`,`ref_itemtype`,`ref_id`,`reason`) | |

**`glpi_plugin_sac_run`** — per-module run audit / watermark.

| col | type | notes |
|---|---|---|
| `id` | int unsigned AI PK | |
| `module` | enum(`eol`,`license`,`eolcache`) | |
| `date_start` | timestamp | |
| `date_end` | timestamp | nullable |
| `status` | enum(`running`,`ok`,`error`,`stale`) | |
| `counts_json` | text | `{scanned, findings_upserted, findings_cleared, reconcile_open, errors}` |
| `message` | text | first error / summary |

### 3.5 Shared components (`src/`)

- **`AssetResolver`** — free-text / id → GLPI `Computer`. For Modules 1–2 the
  input is already a GLPI id (OS id, software id) with joined item rows, so this
  is thin: given `glpi_items_operatingsystems` / `glpi_items_softwareversions`
  rows, return `[computers_id => hostname]` for non-deleted Computers, and a list
  of unresolved item strings. Approach borrowed from
  `integrasipaman/inc/correlation.class.php` (not the code). Module 3 will extend it.
- **`FindingWriter`** — the only place SAC talks to riskregister. See §7.
- **`RunLog`** — opens a `_run` row (`running`), collects counts, closes it
  (`ok`/`error`); on startup marks any `running` row older than 6 h `stale`.
- **`Reconcile`** (`CommonDBTM` on `glpi_plugin_sac_reconcile`) — list + a
  per-row "resolve" action; for `signal_type=eol, reason=no_mapping` the resolve
  action opens a small form to set `eol_product` + `eol_cycle` into `_eolmap`
  (`confidence=confirmed`).
- **`Cron`** (`CommonDBTM`) — `cronEolScan`, `cronEolCacheRefresh`,
  `cronLicenseScan`; `cronInfo()` per task; registered in install via
  `CronTask::register(self::class, '<Action>', WEEK_TIMESTAMP, [...])`.

## 4. Module 1 — EOL/EOS

### 4.1 `cronEolScan($task)` — weekly

1. Guard `Config::get('eol_scan_enabled')`; open `RunLog('eol')`.
2. **OS inventory** — one query: `glpi_operatingsystems` ⨝
   `glpi_items_operatingsystems` (itemtype `Computer`, item not deleted) →
   `{operatingsystems_id, os_name, [computers_id...]}`.
3. **Resolve EOL date** per OS, first hit wins:
   1. **`fields` "LifeCycle" → `EndOfLife`** — read-only from the fields-generated
      table for the container on `OperatingSystem` (Block 16). Table name is
      derived by the `fields` plugin; **confirm on VM** (expected
      `glpi_plugin_fields_operatingsystemlifecycles`, join column
      `items_id`/`itemtype` or `operatingsystems_id`). `$DB->tableExists()`
      guarded. A non-empty, parseable date → `{date, source: 'lifecycle'}`.
   2. **`_eolmap` → `_eolcache`** — if the OS has a confirmed (or auto, see §4.4)
      map row, read `eol_date` from cache → `{date, source: 'endoflife.date'}`.
   3. **neither** → upsert a `_reconcile` row (`eol`, `OperatingSystem`, id,
      `no_mapping`); no Finding.
   - Malformed LifeCycle date (`0000-00-00`, non-date) → `_reconcile`
     (`bad_date`); do not fall through to endoflife.date (the operator entered
     *something*; surface it).
4. **Severity** — `days = resolvedDate - today`:

   | `days` | severity |
   |---|---|
   | `< 0` | `Critical` |
   | `0 … eol_severity_high_days` (90) | `High` |
   | `… eol_severity_medium_days` (180) | `Medium` |
   | `… eol_horizon_days` (365) | `Low` |
   | `> eol_horizon_days` | no Finding (but see §4.5 clear) |

5. **Emit Finding** (via `FindingWriter`, §7) per OS where severity ≠ none:

   | field | value |
   |---|---|
   | `entities_id` | `Config::get('finding_entity')` (0 = Root) |
   | `is_recursive` | `Config::get('finding_recursive')` → `1` |
   | `code` | `SAC-EOL-OS-<operatingsystems_id>` |
   | `name` | `"EOL: <os_name>"` (≤255) |
   | `scope_label` | `"<os_name> ×<host count>"` |
   | `severity` | computed |
   | `content` | os name; resolved EOL date + source; bullet list of affected hostnames (+ their home entity) |
   | `assessment_ref` | `"SAC/eol"` |
   | category | `ImportSupport::categoryIdByName('Obsolescence', $entity)` (tolerate `0`) |
   | `status` | `Open` — **only on create** |
   | `Finding_Item` | one per resolved `computers_id` |
   | `raw_asset_text` | comma-joined unresolved host strings, if any |

6. **Hardware EOL** (if `eol_hardware_enabled`) — `glpi_computers` with
   `warranty_date` set: `endDate = warranty_date + warranty_duration months`.
   Same severity ladder against `eol_hardware_horizon_days`. One Finding **per
   Computer**: `code = SAC-EOL-HW-<computers_id>`, `name = "Hardware EOL: <name>"`,
   `Finding_Item` to that Computer.
7. Close `RunLog`.

### 4.2 `cronEolCacheRefresh($task)` — cron cadence weekly, work throttled

Runs weekly (cheap), but only **re-fetches** an `endoflife.date` product whose
`_eolcache.date_fetched` is older than `Config::get('eol_cache_max_age_days')`
(default 180 — endoflife.date data changes slowly; admin can set 6-monthly or
yearly). For each stale distinct `(eol_product, eol_cycle)` in `_eolmap`: GET
`https://endoflife.date/api/<product>.json`, find the cycle, upsert the `_eolcache`
row (`eol_date`, `eos_date`, `is_lts`, `raw_json`, `date_fetched`). A brand-new
`_eolmap` entry with no cache row is always fetched regardless of the age gate.
Also GET `api/all.json` when its own cache is stale, for the matcher product list
(§4.4). Network failure → log, keep existing cache, `errors++`, do not fail.

### 4.3 Read path: `fields` "LifeCycle"

The `fields` plugin (v1.23.2 on VM) stores container data in a generated table.
SAC reads it **directly, read-only**, guarded by `Plugin::isPluginActive('fields')`
+ `$DB->tableExists()`. SAC never writes it. If absent → endoflife.date-only mode,
logged once per run. **VM verification item:** confirm the exact table + column
names.

### 4.4 Name → slug matching (`_eolmap` population)

- On demand during `cronEolScan` step 3.2: if an OS has no `_eolmap` row, run a
  fuzzy match of `os_name` against the cached `endoflife.date` product list →
  if a confident single hit, insert `_eolmap` with `confidence=auto` and use it;
  otherwise `_reconcile` (`no_mapping`).
- `confidence=auto` rows are used but flagged in the Finding `content`
  ("EOL source: endoflife.date, unconfirmed mapping"). An admin confirms/edits on
  the Reconcile screen → `confidence=confirmed`.
- Cycle extraction (`8.6` → `8`, `2019` → `2019`, `20.04.1` → `20.04`) is a small
  per-product ruleset; unknown → `_reconcile`.

### 4.5 Signal cleared

If an OS that previously had a Finding now resolves to `days > eol_horizon_days`,
or no longer appears in inventory: append a line to the existing Finding's
`content` — `"Signal cleared on <date> (EOL now <date>/OS not in inventory)."` —
and stop re-asserting (no severity/status change). Track via `findings_cleared`
in `counts_json`. The risk owner closes the Finding.

## 5. Module 2 — License Governance

### 5.1 `cronLicenseScan($task)` — weekly

1. Guard `Config::get('license_scan_enabled')`; open `RunLog('license')`.
2. **Candidate software** — `glpi_softwares` ⨝ `glpi_manufacturers`, excluding:
   - `glpi_softwarecategories.name` (system-name) ∈ `license_ignore_categories`
   - software with 0 installations
3. Per software gather:
   - **installs** — count distinct `items_id` in `glpi_items_softwareversions` ⨝
     `glpi_softwareversions` (`softwares_id = X`, itemtype `Computer`, not
     deleted). = GLPI "Number of installations".
   - **licenses** — `glpi_softwarelicenses` (`softwares_id = X`,
     `is_deleted = 0`): `seats = Σ number` (any `number ≤ 0` → treat product as
     "unlimited", excludes it from 2b); min `expire` among non-null.
   - **publisher** — `manufacturers_id`; on `_commercialpublishers` (active)?
4. **Finding 2a — missing license record** (always): group candidate software by
   `manufacturers_id` where publisher is commercial-listed AND the software has
   **0** license rows. One Finding per publisher:

   | field | value |
   |---|---|
   | `code` | `SAC-LIC-MISSING-<manufacturers_id>` |
   | `name` | `"Unlicensed commercial software: <publisher>"` |
   | `scope_label` | `"<publisher> — <N> products"` |
   | `severity` | `Low`; `Medium` if `N > license_2a_products_threshold` OR `Σ installs > license_2a_installs_threshold` |
   | `content` | table of product · installs · sample hosts |
   | `assessment_ref` | `"SAC/license"` |
   | category | `License Compliance` |
   | `Finding_Item` | affected Computers (union across the publisher's products) |

5. **Finding 2b — over-deployment** (only if `license_check_overdeployment`): per
   software where `seats` is finite AND `installs > seats`:

   | field | value |
   |---|---|
   | `code` | `SAC-LIC-OVER-<softwares_id>` |
   | `name` | `"License over-deployment: <software>"` |
   | `scope_label` | `"<software> — <installs>/<seats>"` |
   | `severity` | `installs ≤ 1.1×seats` → `Low`; `≤ 1.5×` → `Medium`; `> 1.5×` → `High`; **`expire < today` with installs > 0 → `High`** |
   | `content` | installed vs licensed, overage, expiry, affected hosts |
   | category | `License Compliance` |

6. **Cleared** (same rule as §4.5): a publisher now fully licensed / a software no
   longer over-deployed → "signal cleared" content line, no auto-close.
7. Close `RunLog`.

### 5.2 Reconcile for licence

`signal_type=license`: a `_commercialpublishers` CSV name that resolved to no /
multiple `glpi_manufacturers` rows on install → `reason=no_mapping` / `ambiguous`;
admin fixes manufacturer naming and re-seeds from Setup.

## 6. Severity → riskregister

SAC sets `severity` on the Finding. It does **not** set likelihood/impact — the
risk officer scores `Finding → Risk` inside riskregister. SAC's severity is a
triage hint, mapped to riskregister's literal enum
`Critical|High|Medium|Low|Info`.

## 7. Integration contract — `FindingWriter`

Single class, the only riskregister touch-point. Per the pinned contract
(hub §5 / §7):

```php
public function upsert(array $input, array $computerIds, array $unresolved): ?int
{
    if (!\Plugin::isPluginActive('riskregister')) {
        $this->log->skip('riskregister inactive');
        return null;
    }
    $f   = new \GlpiPlugin\Riskregister\Finding();
    $hit = $f->find(['entities_id' => $input['entities_id'], 'code' => $input['code']]);
    if ($hit) {
        $id = array_key_first($hit);
        $f->update(['id' => $id] + $this->mergeableOnUpdate($input)); // never touches status
    } else {
        $id = $f->add($input);                    // status=Open set here only
        if ($id === false) { $this->log->error($input); return null; }
    }
    foreach ($computerIds as $cid) {
        (new \GlpiPlugin\Riskregister\Finding_Item())->add([
            'plugin_riskregister_findings_id' => $id,
            'itemtype' => 'Computer', 'items_id' => $cid,
        ]); // add is idempotent-safe? -> guard with a find() first
    }
    return (int) $id;
}
```

- `code` deterministic, ≤50, unique `(entities_id, code)`.
- `is_recursive => 1` (from `finding_recursive`) so a Root-entity Finding is
  visible to every sub-entity. `riskregister`'s `Finding` table has the column
  (`hook.php`, `is_recursive tinyint DEFAULT 0`).
- `scope_label` always a real non-empty string.
- category via `ImportSupport::categoryIdByName($name, $eid)` — **returns 0 on
  miss, no auto-create**; SAC passes `0` through (uncategorised) until
  riskregister v1.1.0 is deployed on the VM (seeds the 4 names).
- `datainjection` / `FindingInjection` — **not used** (dormant).
- **CSV export** — `tools/export_findings.php` writes the same rows to CSV for
  audit / the server-refresh KAK evidence use case. Not the pipeline.

## 8. GLPI 11 implementation constraints (from riskregister VM verification, hub §4)

- `canView(): bool` and the full `can*` set typed `: bool` on `CommonDBRelation`
  subclasses. Other core overrides left untyped.
- If a `\Profile` rights tab is added: `getTabNameForItem()` non-static instance,
  `displayTabContentForItem()` static — match the immediate parent; `grep` first.
- Menu = own sector (§3.2); `Management → … + options` does **not** render.
- No `Session::checkCSRF()` in front files; set `csrf_compliant`.
- No `Html::clean()` — store trimmed raw, `htmlescape()` on output.
- Dashboard card `provider`/`function` = `"Class::method"` strings, not closures
  (if SAC adds a card later).
- Forms: `Dropdown::showFromArray()` / `Class::dropdown()` / Twig `fields.*`
  macros only — never hand-rolled `<select>`. One render site per itemtype.
- `link_existing_or_new.html.twig` hidden-field names drift across 11.0.x — if
  SAC's Reconcile "link asset" UI needs it, render a plain explicit `<form>` with
  self-named `itemtype`/`items_id` instead (riskregister v1.2.1).
- **Form `action` — never `$_SERVER['PHP_SELF']`** (riskregister v1.2.3). In GLPI
  11 `PHP_SELF`/`REQUEST_URI`/`SCRIPT_NAME`/`PATH_INFO` all point at
  `public/index.php`, so a `<form action="{PHP_SELF}">` in a front file POSTs to
  `/` where the inventory endpoint tries to parse the body as agent XML. SAC's
  Setup + Reconcile forms: `action = Html::getPrefixedUrl('/plugins/sac/front/<x>.php')`
  or `<Itemtype>::getFormURL()`. `Html::header()`'s 2nd arg (`$url`) is ignored —
  pass `''`.
- Migration: canonical `timestamp NULL DEFAULT NULL`; run/expect
  `migration:timestamps` clean.
- `getDropdown` hook: `function plugin_sac_getDropdown(): array` (old
  `$PLUGIN_HOOKS['dropdowns']` is dead).

## 9. Testing

### 9.1 Offline (`php -l` + stubs, `C:\php` PHP 8.4, no DB)

- `Severity::forEolDays(int $days): ?string` — all 5 bands + exact boundary days.
- `Severity::forLicenseOverage(int $installs, int $seats, ?string $expire): string`.
- `EolMatch::cycleFromVersion(string $product, string $osName): ?string` — the
  per-product cycle rules.
- `Code::eolOs()`, `Code::licMissing()`, `Code::licOver()` — format + ≤50 length.
- `FindingWriter::mergeableOnUpdate()` — asserts `status` is never in the output.

### 9.2 VM (`tools/selfcheck.php` → `GlpiPlugin\Sac\*::selfChecks(): array<string,callable>`)

Follows hub §4; `sac_assert()` / `sac_assert_eq()` above the bootstrap; non-zero
exit on any FAIL. Each check seeds throwaway rows and tears them down.

- **EOL-1** seed OS + Computer, LifeCycle `EndOfLife` = yesterday → run
  `cronEolScan` → assert one Finding `code=SAC-EOL-OS-<id>`, `severity=Critical`,
  one `Finding_Item`, `assessment_ref='SAC/eol'`.
- **EOL-2** set date = today+120 → re-run → assert same Finding **updated**
  (`severity=Medium`), no second row.
- **EOL-3** blank the LifeCycle date, no `_eolmap` → assert no Finding, one
  `_reconcile` (`no_mapping`).
- **EOL-4** date = today+400 (was Critical) → assert `content` gains "signal
  cleared", `status` unchanged, `findings_cleared=1`.
- **EOL-5** endoflife.date path — seed `_eolmap` (`confirmed`) + `_eolcache` row,
  no LifeCycle → assert Finding with source `endoflife.date` in `content`.
- **LIC-1** seed Manufacturer on `_commercialpublishers`, a Software under it with
  1 install and no license → run `cronLicenseScan` → assert one 2a Finding
  `code=SAC-LIC-MISSING-<mid>`, `severity=Low`, category resolves (or 0).
- **LIC-2** add a `SoftwareLicense` (`number=1`) + a 2nd install, set
  `license_check_overdeployment=true` → assert one 2b Finding, `severity` per
  ratio; publisher now has a license row → 2a Finding for it gains "signal
  cleared".
- **LIC-3** software in an ignored category → assert it is not scanned.
- **WRITER-1** `riskregister` inactive → `cronEolScan` exits `ok` with a
  `skipped` log line, no fatal.

## 10. Deployment & VM verification checklist

Prereqs on VM-SERVER-22 (hub §3): deploy to `/var/www/glpi/plugins/sac`, restart
php-fpm, `bin/console glpi:plugin:install sac --username=<admin>` +
`glpi:plugin:activate sac`, check `files/_log/php-errors.log`.

VM checks specific to SAC:

1. `fields` "LifeCycle" generated table + column names (§4.3).
2. GLPI Inventory Plugin present; Collect task usable; **Deploy task disabled**
   (needed for Module 3, verify now).
3. riskregister **v1.1.0+ deployed** so `categoryIdByName` resolves the 4 names.
4. Decide `finding_entity` (Root vs `ICT`).
5. `migration:timestamps` → "schema OK" after install.
6. Run each cron once via Setup → Automatic actions → Execute (**not**
   `front/cron.php <name>` — numeric only, hub §3); confirm `_run` rows close `ok`.
7. `endoflife.date` reachable from the GLPI host (egress) — if not, Module 1 runs
   LifeCycle-only; note it.
8. `tools/selfcheck.php` → all PASS.

## 11. Deferred (tracked in brief §12)

OpenSCAP Tier 2, Windows patch baseline, Windows CIS, hardware-vendor lifecycle
source, CVE correlation, `integrasipaman → Finding`, EOL evidence CSV for the
server-refresh KAK (the `tools/export_findings.php` output covers this).

## 12. Open items — RESOLVED 2026-09-07

- **`finding_entity`** → `0` (Root Entity), Findings created **recursive**
  (`finding_recursive = true` → `is_recursive = 1`) so all sub-entities see them.
- **Hardware-warranty EOL** → **in Module 1 v1** (§4 step 6).
- **Rights** → `plugin_sac_finding` + `plugin_sac_config` is enough; Reconcile
  needs no dedicated right (gated on `plugin_sac_finding` UPDATE).
- **`EolCacheRefresh`** → cron weekly, actual API re-fetch throttled by
  `eol_cache_max_age_days` (default 180; admin can set to 365).

No open items remain for this spec. Remaining unknowns are VM-verification facts
(§10), not design decisions.
