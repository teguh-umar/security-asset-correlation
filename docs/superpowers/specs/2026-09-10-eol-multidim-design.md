# SAC v1.1.0 — Module 1 EOL re-keyed to (OS, OS-version)

**Plugin:** Security-Asset Correlation (`sac`) — GLPI 11 plugin
**Repo:** `teguh-umar/security-asset-correlation`
**Scope of this spec:** rework Module 1 (EOL/EOS) so end-of-life is resolved per
`(operatingsystems_id, operatingsystemversions_id)` pair instead of per
`operatingsystems_id` alone; introduce a shipped seed CSV that drives
`endoflife.date` mapping; replace the install-time `tableExists`-or-skip with a
real `\Migration` object.
**Date:** 2026-09-10
**Status:** for review
**Supersedes:** the Module 1 keying in `2026-09-07-sac-modules-1-2-design.md` §4
(that spec's Modules 2, scaffold, Finding contract and testing model stand
unchanged).

Companion: `docs/project-brief.md`. Suite context:
`…/Documents/_pgn-glpi-suite.md` (§2 namespace, §4 GLPI 11 patterns, §5 Finding
contract, §7 log 2026-09-10 nvd + riskregister entries).

---

## 1. Why

GLPI 11 does not model an operating system as one string. An inventoried OS is a
row in `glpi_items_operatingsystems` carrying up to six independent dropdown FKs:

| column | dimension |
|---|---|
| `operatingsystems_id` | family / product name |
| `operatingsystemversions_id` | version / release |
| `operatingsystemeditions_id` | edition (Datacenter, Workstation, …) |
| `operatingsystemservicepacks_id` | service pack |
| `operatingsystemarchitectures_id` | arch (x86_64, …) |
| `operatingsystemkernelversions_id` | kernel build |

On VM-HQ-GLPI the `operatingsystems_id` dimension alone is **not** an EOL key: a
single `operatingsystems_id` (`53`, name "Oracle") spans Oracle Linux 7.x through
9.7 across the fleet. SAC v1.0.x keyed `_eolmap`, the Finding `code`
(`SAC-EOL-OS-<os_id>`) and the Reconcile row all on `operatingsystems_id`, so one
mapping / one Finding covered five mutually-incompatible lifecycles.

The correct grain is **`(operatingsystems_id, operatingsystemversions_id)`** — the
product name plus its release string. The other four dimensions do not change an
OS lifecycle date at the granularity `endoflife.date` publishes and are out of
scope (§14).

## 2. Goal / non-goals

**Goal.** After v1.1.0, SAC emits one EOL Finding per distinct `(OS, version)`
pair in live Computer inventory, dated from — in priority order — an
admin-entered `fields` "LifeCycle" date, then an `endoflife.date` cycle resolved
through a shipped seed CSV + admin confirmations, then the Reconcile queue.

**Non-goals.**

- Editions / service packs / architecture / kernel as EOL inputs (§14).
- Hardware-warranty EOL — unchanged from v1.0.1, not touched here (§14).
- A rules-editor UI for the seed CSV (§3, Q4).
- Synchronous per-OS `endoflife.date` fetches on the request path — the cache
  refresh stays a batched step (§10).
- Any change to Module 2, the plugin scaffold, `FindingWriter`, or the
  riskregister Finding contract beyond the `code` format (§13).

## 3. Decisions locked before this spec

From the v1.1.0 brainstorm (user answered **A** to each):

| # | Decision |
|---|---|
| **Q1** | Primary `endoflife.date` mapping is keyed on `(os_id, version_id)` and resolved by a **shipped seed CSV** `install/data/eol_products.csv` (pattern → product slug + cycle rule). The `fields` "LifeCycle" date, when an admin has entered one, is an **override** that wins over the mapping. |
| **Q2** | On a seed-CSV pattern match, SAC **auto-writes** the `_eolmap` row (`confidence = 'auto'`) and emits the Finding **in the same run** (the cache is filled between passes, §6 / §10). A wrong auto-mapping is corrected through Reconcile → "Show resolved" → "Re-open". |
| **Q3** | Install/upgrade builds a real `\Migration` object (GLPI core `\Migration`, riskregister's pattern), replacing the current `tableExists ? skip : CREATE`. |
| **Q4** | The seed CSV **ships in `install/data/`** and is the source of truth, re-copied on every `plugin:install --force`. Admins do **not** edit it (edits are lost on upgrade); they extend coverage per-exception through the Reconcile queue, which writes `confidence = 'confirmed'` `_eolmap` rows that the seed never overwrites. No Setup screen, no rules editor. |
| **Stale Findings** | Old-format Findings (`code` matching `SAC-EOL-OS-<n>` with no version segment) are **closed with a content note**, never hard-deleted — a promoted riskregister Risk holds a FK to the Finding and riskregister does not cascade-delete. Done inline in the migration (§5.3). |

## 4. Data model changes

All new timestamp columns use `'timestamp'` (GLPI canonical, `migration:timestamps`
gotcha — hub §7). Uninstall still drops every `glpi_plugin_sac_%` by prefix.

### 4.1 `glpi_plugin_sac_eolmap` — add the version dimension

| change | detail |
|---|---|
| **add column** | `operatingsystemversions_id` `int unsigned NOT NULL DEFAULT 0`, after `operatingsystems_id` |
| **drop key** | `UNIQUE KEY \`os\` (operatingsystems_id)` |
| **add key** | `UNIQUE KEY \`os_ver\` (operatingsystems_id, operatingsystemversions_id)` |

`operatingsystemversions_id = 0` is a legal key — it is the "version unknown /
version not inventoried" catch-all mapping for that OS (used by Windows Server,
where the release year is parsed from the OS name and the version dropdown is
routinely blank; and as an admin fallback).

Fresh-install DDL is updated to this shape directly; existing installs are
migrated (§5.2).

### 4.2 `glpi_plugin_sac_reconcile` — add a sub-id

The Reconcile UNIQUE key is `(signal_type, ref_itemtype, ref_id, reason)`. Two
unmapped versions of the same OS both produce
`(eol, OperatingSystem, <os_id>, no_mapping)` and collide. Fix:

| change | detail |
|---|---|
| **add column** | `ref_subid` `int unsigned NOT NULL DEFAULT 0`, after `ref_id` |
| **drop key** | `UNIQUE KEY \`signal\` (signal_type, ref_itemtype, ref_id, reason)` |
| **add key** | `UNIQUE KEY \`signal\` (signal_type, ref_itemtype, ref_id, ref_subid, reason)` |

For an `eol` row: `ref_id = operatingsystems_id`, `ref_subid =
operatingsystemversions_id`. For `license` rows `ref_subid` stays `0` — no
behaviour change.

### 4.3 `glpi_plugin_sac_eolcache` — unchanged

Still keyed `(eol_product, eol_cycle)`. A cycle is shared across every OS/version
that maps to it, so the mirror needs no OS dimension.

### 4.4 New shipped file — `install/data/eol_products.csv`

Read at scan time (like `commercial_publishers.csv`), never copied into a table.
Columns:

| column | meaning |
|---|---|
| `name_pattern` | lower-cased substring tested against the GLPI OS name; rows are consulted **most-specific first** |
| `product` | `endoflife.date` product slug, or the sentinel `_skip` for non-OS rows that land in the OS dropdown |
| `cycle_source` | `version` = derive the cycle from `glpi_operatingsystemversions.name`; `name` = derive it from `glpi_operatingsystems.name` |
| `cycle_rule` | `major` (`9.7` → `9`) · `yy.mm` (`22.04.1` → `22.04`) · `xy` (`7.0.3` → `7.0`) · `winsrv` (`… Server 2019 …` → `2019`, `2012 R2` → `2012-R2`) · `winclient` (`Windows 10 …` → `10`) · `exact` (verbatim) |
| `comment` | free text, ignored by the parser |

Starter contents (implementer confirms slugs against endoflife.date at build
time):

```csv
name_pattern,product,cycle_source,cycle_rule,comment
red hat enterprise linux,rhel,version,major,
rocky linux,rocky-linux,version,major,
almalinux,almalinux,version,major,
oracle linux,oracle-linux,version,major,
oracle,oracle-linux,version,major,VM inventory labels Oracle Linux as "Oracle"
centos stream,centos-stream,version,major,
centos,centos,version,major,
ubuntu,ubuntu,version,yy.mm,
debian,debian,version,major,
suse linux enterprise server,sles,version,major,
suse linux enterprise,sles,version,major,
opensuse leap,opensuse,version,yy.mm,
amazon linux,amazon-linux,version,major,
windows server,windows-server,name,winsrv,version dropdown usually blank
windows,windows,name,winclient,
vmware esxi,vmware-esxi,version,xy,
esxi,vmware-esxi,version,xy,
vmware vcenter,vmware-vcenter,version,xy,
kubernetes,_skip,,,appliance node — not an OS lifecycle
k3s,_skip,,,
```

## 5. Install / upgrade — the `\Migration` object

`SacMigration::install()` is called by `plugin_sac_install()` on both fresh
install and upgrade (GLPI re-runs the install hook on upgrade). New structure:

```php
public static function install(): void
{
    global $DB;
    self::define();                                   // §5.1
    foreach (self::$tables as $name => $ddl) {
        if (!$DB->tableExists($name)) { $DB->doQuery($ddl); }
    }

    $migration = new \Migration(PLUGIN_SAC_VERSION);  // §5.2
    self::migrateEolmapVersionKey($migration, $DB);
    self::migrateReconcileSubId($migration, $DB);
    $migration->executeMigration();                   // exactly once

    self::supersedeOldEolFindings($DB);               // §5.3
    self::wipeStaleEolReconcile($DB);                 // §5.4
    self::purgeAutoEolmap($DB);                       // §5.4

    Config::setDefaults();
    Cron::register();
    License\CommercialPublisher::seedFromCsv();
}
```

### 5.1 Fresh-install DDL

`glpi_plugin_sac_eolmap` and `glpi_plugin_sac_reconcile` CREATE statements are
rewritten to the §4.1 / §4.2 target shape. A brand-new install therefore never
runs the `addField`/`addKey` path (they are idempotent no-ops).

### 5.2 Column + key migration (idempotent — that is the version gate)

Per riskregister's pattern, do **not** compare plugin versions. `addField` /
`addKey` self-skip when the field / key already exists, so the existence check
*is* the gate. `migrationOneTable()` flushes the queued `addField` before the
`addKey` that depends on it.

```php
private static function migrateEolmapVersionKey(\Migration $m, \DBmysql $DB): void
{
    $t = 'glpi_plugin_sac_eolmap';
    if (!$DB->tableExists($t)) { return; }

    if (!$DB->fieldExists($t, 'operatingsystemversions_id')) {
        $m->addField($t, 'operatingsystemversions_id', 'integer',
            ['after' => 'operatingsystems_id', 'value' => 0]);
    }
    $m->migrationOneTable($t);                        // flush the column

    if ($DB->indexExists($t, 'os')) {
        $m->dropKey($t, 'os');
    }
    if (!$DB->indexExists($t, 'os_ver')) {
        $m->addKey($t, ['operatingsystems_id', 'operatingsystemversions_id'],
            'os_ver', 'UNIQUE');
    }
}
```

`migrateReconcileSubId()` is the same shape: add `ref_subid` after `ref_id`,
`migrationOneTable`, drop `signal`, re-add `signal` over the 5-column tuple.

**Safety of the key swap.** Every pre-migration `_eolmap` row gets
`operatingsystemversions_id = 0`, so `(os_id, 0)` is still unique — no duplicate
blocks the new UNIQUE key. Same for `_reconcile` with `ref_subid = 0`.

Web install echoes `\Migration` progress HTML — harmless (hub §7).

### 5.3 Retire old-format Findings (inline, not a shared helper)

Old Findings carry `code` like `SAC-EOL-OS-53`; new ones
`SAC-EOL-OS-53-41`. On upgrade, for each entity, close every `SAC/eol` Finding
whose code does **not** match the new two-int pattern and is not already
`Closed`:

```php
private static function supersedeOldEolFindings(\DBmysql $DB): void
{
    if (!\Plugin::isPluginActive('riskregister')
        || !class_exists(\GlpiPlugin\Riskregister\Finding::class)) {
        return;
    }
    $new = '/^SAC-EOL-OS-\d+-\d+$/';                  // two ints = new grain
    $f   = new \GlpiPlugin\Riskregister\Finding();
    foreach ($f->find([
        'assessment_ref' => 'SAC/eol',
        'code'           => ['LIKE', 'SAC-EOL-OS-%'],
    ]) as $row) {
        $code = (string) $row['code'];
        if (preg_match($new, $code) || $row['status'] === 'Closed') { continue; }
        $f->update([
            'id'      => (int) $row['id'],
            'status'  => 'Closed',
            'content' => trim((string) $row['content'])
                       . "\n\n[superseded " . date('Y-m-d')
                       . " — SAC EOL scan re-keyed to (operatingsystems_id, operatingsystemversions_id)]",
        ]);
    }
}
```

`SAC-EOL-HW-<computers_id>` (hardware pass) is left completely alone — it is not
re-keyed and its single-int code is expected.

### 5.4 Reset the derived rows SAC owns

- **`wipeStaleEolReconcile()`** — `DELETE FROM glpi_plugin_sac_reconcile WHERE
  signal_type = 'eol'`. Every existing `eol` row is keyed to the old grain; the
  next scan regenerates them at `(os_id, ver_id)`. SAC owns this table outright.
- **`purgeAutoEolmap()`** — `DELETE FROM glpi_plugin_sac_eolmap WHERE confidence
  = 'auto'`. Auto rows were machine guesses at the wrong grain. `confidence =
  'confirmed'` rows are **kept** as `(os_id, 0)` fallback mappings; the next scan
  still prefers a precise `(os_id, ver_id)` seed match when the host has a real
  version dropdown, and Reconcile "Re-open" lets an admin retarget a stale
  confirmed row.

## 6. Scan flow — `EolScan::run(RunLog $run)`

Two passes over the pair set, with a cache-fill between them so a fresh auto-match
still produces a dated Finding this run (Q2).

**Pass 0 — enumerate pairs.**

```sql
SELECT DISTINCT
  io.operatingsystems_id        AS os_id,
  io.operatingsystemversions_id AS ver_id,
  os.name                       AS os_name,
  ov.name                       AS ver_name
FROM glpi_items_operatingsystems io
JOIN glpi_operatingsystems os        ON os.id = io.operatingsystems_id
LEFT JOIN glpi_operatingsystemversions ov ON ov.id = io.operatingsystemversions_id
WHERE io.itemtype = 'Computer'
```

(`LEFT JOIN` so `ver_id = 0` rows survive with `ver_name = NULL → ''`.)

**Pass 1 — resolve the mapping for each pair.** `resolveMapping(os_id, ver_id,
os_name, ver_name)`:

1. **`fields` "LifeCycle" override.** `LifeCycleReader::endOfLifeFor(os_id,
   ver_id)` (§7.4). A parseable date → record `{date, source:'lifecycle'}`, done.
   A present-but-unparseable date (`0000-00-00`, junk) → `Reconcile::note('eol',
   'OperatingSystem', os_id, ver_id, 'bad_date', "<os> <ver>")`, skip pair.
2. **Existing `_eolmap`.** `EolMap::forOsVersion(os_id, ver_id)` — hit → keep
   `{product, cycle}` for Pass 2. Falls through to the `(os_id, 0)` fallback row
   if the precise pair has none.
3. **Seed match.** `EolMatch::resolve(os_name, ver_name)` (§7.3):
   - `_skip` sentinel → `$run->bump('skipped')`, no Finding, **no** Reconcile
     row, skip pair.
   - `{product, cycle}` → `EolMap::putAuto(os_id, ver_id, product, cycle)`; keep
     for Pass 2; add `(product, cycle)` to the "need cache" set.
   - `null` (no pattern, or the cycle rule could not parse a cycle) →
     `Reconcile::note('eol', 'OperatingSystem', os_id, ver_id, 'no_mapping',
     "<os> <ver>")`, skip pair.

**Cache fill.** `EolCache::ensureFresh($needSet, $run)` (§10) — one
`endoflife.date` GET per distinct product still missing/stale, upserting
`_eolcache`. Network failure bumps `errors` and leaves the cache as-is.

**Pass 2 — grade + emit.** For each pair that resolved to a `lifecycle` date, or
to a `{product, cycle}` with a non-null `_eolcache.eol_date`:

- `days = eol_date − today`; `sev = Severity::forEolDays($days, $horizon,
  $high, $medium)` (unchanged ladder).
- `assets = AssetResolver::computersForOsVersion(os_id, ver_id)` (§7.1).
- `hostCount === 0` → `FindingWriter::appendClearedNote(code, entity, 'pair not
  in live inventory', $run)`, continue.
- `sev === null` (EOL beyond horizon) → `appendClearedNote(...)`, continue.
- else `FindingWriter::upsert($input, array_keys($assets['resolved']), '',
  $run)` with the §8 shape.

A pair that resolved to a `{product, cycle}` whose `_eolcache.eol_date` is still
null after the cache fill (transient — endoflife.date unreachable, or the cycle
genuinely absent upstream) is **skipped this run** with `$run->bump('cache_pending')`
and **no** Reconcile row — it is self-healing on the next run / next
`EolCacheRefresh`. (Distinct from `no_mapping`, which means SAC has nothing to
look up.)

**After Pass 2.** `Reconcile::autoResolveMissing('eol', $seen)` where `$seen`
composite keys are now `"OperatingSystem:<os_id>:<ver_id>:<reason>"` (§7.5).
`$run->bump('reconcile_open', count($seen))`. Hardware pass runs unchanged.

## 7. Component changes

### 7.1 `AssetResolver`

Add:

```php
/** @return array{resolved:array<int,string>,unresolved:array<int,string>} */
public static function computersForOsVersion(int $osId, int $verId): array
```

Same query as `computersForOs()` plus
`AND glpi_items_operatingsystems.operatingsystemversions_id = :verId`. Keep
`computersForOs()` — hardware/other callers and back-compat.

### 7.2 `EolMap`

Every method gains the version dimension:

| before | after |
|---|---|
| `forOs(int $osId): ?array` | `forOsVersion(int $osId, int $verId): ?array` — tries `(osId, verId)`, then `(osId, 0)` fallback; return value adds nothing |
| `putAuto(int $osId, string $p, string $c)` | `putAuto(int $osId, int $verId, string $p, string $c)` |
| `putConfirmed(int $osId, string $p, string $c, int $u)` | `putConfirmed(int $osId, int $verId, string $p, string $c, int $u)` |
| `put()` keyed `['operatingsystems_id' => $osId]` | keyed `['operatingsystems_id' => $osId, 'operatingsystemversions_id' => $verId]` |

### 7.3 `EolMatch` + new `EolProducts`

- **`EolProducts`** (new, `src/Eol/EolProducts.php`) — loads and caches
  `install/data/eol_products.csv` into an ordered `array<int,array{pattern,
  product, cycle_source, cycle_rule}>`. Mirrors `CommercialPublisher::csvPath()`
  / `file()` handling. One public method `rules(): array`.
- **`EolMatch::resolve(string $osName, string $verName): ?array`** (new, the
  entry point Pass 1 calls) — walk `EolProducts::rules()` in order; first
  `name_pattern` that is a case-insensitive substring of `$osName` wins:
  - `product === '_skip'` → return `['skip' => true]`.
  - pick the source string (`$verName` or `$osName` per `cycle_source`), run
    `cycleFrom($rule, $source)`; non-null → `['product' => …, 'cycle' => …]`;
    null → keep scanning later patterns, and if none yield a cycle, return
    `null`.
- **`EolMatch::cycleFrom(string $rule, string $s): ?string`** — the `major` /
  `yy.mm` / `xy` / `winsrv` / `winclient` / `exact` rules. The existing
  `cycleWindowsServer()` / `cycleWindowsClient()` / ubuntu / generic-integer
  logic is refactored into these named rules; the current
  `cycleFromVersion(string $product, string $osName)` signature is dropped
  (only `EolScan` and the offline selfChecks call it).
- **`fuzzyProduct()`** is removed — the seed CSV's `name_pattern` column
  replaces the alias table.
- `EolMatch::resolve()` is `public` and side-effect-free by design so nvd Tier 2
  can consume it later (§13); this spec does not build the wider
  `OsNormalizer` contract.

### 7.4 `LifeCycleReader` — OS-level and version-level containers

Today it reads one container: `glpi_plugin_fields_operatingsystemlifecycles`
(keyed `items_id` = `operatingsystems_id`). GLPI 11 lets an admin attach a
`fields` container to `OperatingSystemVersion` too.

- `endOfLifeFor(int $operatingsystemsId)` becomes `endOfLifeFor(int
  $operatingsystemsId, int $operatingsystemversionsId = 0)`.
- Probe a **second** container table
  `glpi_plugin_fields_operatingsystemversionlifecycles` (LIKE fallback
  `glpi_plugin_fields_operatingsystemversion%lifecycle%`), same date-column
  probe list.
- Resolution: if `verId > 0` and the version container yields a parseable date →
  use it (most specific). Else fall back to the OS container as today. `false`
  (bad date) from the more-specific container still short-circuits to
  `bad_date`.
- `available()` stays true if **either** container resolves. All existing
  guard/`itemtype`-pin logic is kept per table.
- `tableName()` / `dateColumnName()` / `resetProbeCache()` gain a `$level`
  (`'os'` | `'version'`) argument for the selfChecks' seeding helper.

### 7.5 `Reconcile`

- `note(string $signal, string $itemtype, int $id, int $subId, string $reason,
  string $detail)` — new `$subId` positional (4th). All call sites updated;
  `CommercialPublisher` passes `0`.
- `autoResolveMissing()` composite key becomes
  `"<ref_itemtype>:<ref_id>:<ref_subid>:<reason>"`.
- `listForDisplay()` — the `_eolmap` join is on `(operatingsystems_id,
  operatingsystemversions_id)`; each row also surfaces `ref_subid` and, for an
  `eol` row, the version name (via `refLabel('OperatingSystemVersion',
  ref_subid)`), so the queue can show `Oracle 9.7` rather than `Oracle`.
- `resolveEolMapping(int $id, string $product, string $cycle)` — read both
  `ref_id` and `ref_subid` from the row; call `EolMap::putConfirmed(os_id,
  ver_id, product, cycle, users_id)`. The `os_id === 0` guard stays.
- `resolve()` / `reopen()` unchanged.

### 7.6 `EolScan` selfChecks

`scSeed()` / `scSeedLifecycle()` / `scTeardown()` gain a version id: seed a real
`glpi_operatingsystemversions` row, put it on the
`glpi_items_operatingsystems` link, key `_eolmap` / lifecycle / teardown on the
pair. New/updated checks:

- `db/eol-1-lifecycle-source` — unchanged intent, pair-keyed; assert `code =
  SAC-EOL-OS-<os>-<ver>`.
- `db/eol-version-lifecycle-wins` (new) — OS-container date = +200d,
  version-container date = yesterday → Finding graded Critical from the version
  container.
- `db/eol-seed-automap` (new) — OS name `Ubuntu`, version `22.04.3 LTS`, no
  `_eolmap`, a stubbed `_eolcache` row for `ubuntu/22.04` → one run → assert an
  `_eolmap` row `(os,ver)` `confidence='auto'` `ubuntu/22.04` **and** the
  Finding exists the same run.
- `db/eol-skip-non-os` (new) — OS name `Kubernetes` → no Finding, no Reconcile
  row, `skipped` counter ≥ 1.
- `db/eol-two-versions-distinct` (new) — same `os_id`, versions `7.9` and `9.7`,
  neither mapped → **two** `no_mapping` Reconcile rows (distinct `ref_subid`),
  no UNIQUE collision.
- `db/migration-eolmap-key` (new, `db/` prefix) — on a table pre-seeded to the
  old shape, run the migration helper, assert the column + `os_ver` key exist
  and `os` is gone.

### 7.7 `front/reconcile.php` + `templates/reconcile.html.twig`

- The template's Reference column shows `row.label` plus, when
  `row.ref_subid > 0`, the version name.
- The inline "Map & resolve" form carries a hidden `subid` alongside `id`; the
  help panel's examples are updated to name the version (`rhel` / `9` for *RHEL
  9.x*, `ubuntu` / `22.04` for *Ubuntu 22.04*).
- `front/reconcile.php` reads `$_POST['subid']` only to log context — the id →
  `(os_id, ver_id)` resolution is done server-side in `resolveEolMapping()` from
  the stored row, so a tampered `subid` cannot mis-map.

## 8. Finding shape (changes only)

| field | v1.0.x | v1.1.0 |
|---|---|---|
| `code` | `SAC-EOL-OS-<os_id>` | `SAC-EOL-OS-<os_id>-<ver_id>` (≤ 32 chars) |
| `name` | `"EOL: <os_name>"` | `"EOL: <os_name> <ver_name>"` (trim trailing space when `ver_id = 0`) |
| `scope_label` | `"<os_name> ×<n>"` | `"<os_name> <ver_name> ×<n>"` |
| `content` | os name; date + source; hosts | adds the version line; source tag is one of `lifecycle` / `lifecycle:version` / `endoflife.date` |
| `Finding_Item` | computers with the OS | computers with the **exact pair** |

`entities_id`, `is_recursive`, `severity` ladder, `assessment_ref = 'SAC/eol'`,
category `Obsolescence`, `status = Open` on create only — all unchanged.

## 9. Config

No new keys. `eol_*` keys keep their meaning. `Config::selfChecks()` unchanged.

## 10. `endoflife.date` cache freshness

- `EolCache::ensureFresh(array $productCyclePairs, RunLog $run): void` (new) —
  for each distinct `product` in the set with a missing `_eolcache` row **or**
  `date_fetched` older than `eol_cache_max_age_days`, GET
  `https://endoflife.date/api/<product>.json`, upsert every cycle it returns
  (not just the one asked for — cheap, and pre-warms sibling versions). Reuses
  `Http::get()`; `null`/bad-shape → `$run->bump('errors')`, continue.
- `cronEolCacheRefresh` / `EolScan::refreshCache()` keep working on
  `EolCache::staleProducts()` for products already in `_eolmap`; `ensureFresh()`
  is the new-this-run path. Both converge on `EolCache::upsert()`.

## 11. Testing

### 11.1 Offline (`tools/offline-selfcheck.php`, no DB)

- `EolMatch::cycleFrom()` — one case per rule incl. boundary (`2012 R2` →
  `2012-R2`, `7.0.3` → `7.0`, `22.04.1` → `22.04`).
- `EolMatch::resolve()` — pattern precedence ("Red Hat Enterprise Linux" beats a
  hypothetical "red hat" row), `_skip` sentinel, unparseable-cycle → `null`.
- `EolProducts::rules()` — a new pure `eol/products-csv-valid` self-check
  (offline-safe: reads the shipped file, no DB): the CSV parses, every row's
  `cycle_rule` is one of the known set, `_skip` rows have empty
  source/rule.
- `Code::eolOs(int,int)` — format + `≤ 50` at max ints.

### 11.2 VM (`tools/selfcheck.php`)

The §7.6 `db/` checks, plus the existing EOL-2/3/4/5 ported to pair keys.
`db/migration-*` checks seed the pre-migration shape, run the helper, assert the
post shape, and restore.

## 12. VM verification checklist (additions to the README list)

1. `glpi_operatingsystemversions` is populated for the Linux fleet (Pass 0
   `LEFT JOIN` — confirm `ver_id` is mostly non-zero; if the fleet is all
   `ver_id = 0`, seed coverage must lean on `cycle_source = name`).
2. Whether a `fields` container exists on `OperatingSystemVersion`
   (`glpi_plugin_fields_operatingsystemversionlifecycles`) — expected absent;
   `available()` must still resolve via the OS-level container.
3. After upgrade: old `SAC-EOL-OS-<n>` Findings are `Closed` with the superseded
   note; `_reconcile` `eol` rows are gone; `_eolmap` retains only `confirmed`
   rows (now at `(os_id, 0)`).
4. `bin/console migration:timestamps` clean after the `\Migration` run.
5. First post-upgrade `EolScan`: `_eolmap` repopulates with `auto` rows at
   `(os_id, ver_id)`; Findings carry the two-int code; `endoflife.date` reachable
   (else `cache_pending > 0` and Findings appear next run).
6. `tools/selfcheck.php` → all PASS.

## 13. Cross-suite coordination

- **riskregister** (`risk-register-dashboard-98`) — already informed of the
  `code` format change (`SAC-EOL-OS-<os_id>-<ver_id>`) and that SAC adopts their
  `\Migration` pattern + the inline supersede snippet. No riskregister change
  required; its `(entities_id, code)` UNIQUE and `find()`-by-code both handle the
  longer code.
- **nvd** (`nvd-ff`) — informed of the multi-dimensional OS finding. nvd 2.4.0
  ships its own OS→CPE map with **no SAC dependency**. For a future Tier-2
  single-normaliser contract, nvd asked that SAC's resolver return a **CPE
  vendor/product** (`canonical:ubuntu_linux`), not only the `endoflife.date`
  slug (`ubuntu`). **This spec keeps `EolMatch::resolve()` public and pure** so
  it can back such a method, and notes the requirement — but the
  `\GlpiPlugin\Sac\OsNormalizer::resolve()` public contract, the CPE column in
  `eol_products.csv`, and the §5 hub contract are **out of scope here** and
  tracked for when SAC's mapping has stabilised on the VM.

## 14. Deferred / out of scope

- Editions, service packs, architecture, kernel version as EOL inputs — no
  `endoflife.date` grain needs them; revisit only if a real case appears.
- Hardware-warranty EOL (`SAC-EOL-HW-<computers_id>`) — unchanged from v1.0.1.
- `OsNormalizer` public contract + CPE output for nvd Tier 2 (§13).
- A seed-CSV editor / Setup screen (Q4 — rejected).
- Auto-matching unmapped OS names against `endoflife.date/api/all.json` (Q4
  option C — rejected; the shipped seed + Reconcile per-exception is the model).

## 15. Open items for review

The user answered Q1–Q4 and the stale-Finding question. These smaller calls were
made by default while writing — flag any to change:

1. **`_skip` rows** produce no Finding **and no Reconcile row** (silent, counted
   in `skipped`). Alternative: a `skipped` Reconcile reason for visibility.
2. **Existing `_reconcile` `eol` rows** are **deleted** on upgrade and
   regenerated next scan (SAC owns the table). Alternative: migrate them
   in place with `ref_subid = 0`.
3. **Existing `_eolmap` `auto` rows** are **purged** on upgrade; `confirmed`
   rows are kept as `(os_id, 0)` fallbacks. Alternative: keep all, downgrade
   `confirmed` → needs-review.
4. **`ver_id = 0` is a valid Finding grain** (Windows Server, name-derived
   cycle). Findings for such pairs use code `SAC-EOL-OS-<os_id>-0`.
5. **Version-level `fields` LifeCycle beats OS-level.** If both containers carry
   a date for a pair, the `OperatingSystemVersion` one wins.
6. **`cache_pending`** is a run counter only, not a Reconcile row — a mapped
   pair with no cached date yet is treated as self-healing, not as a problem to
   surface.
