# SAC v1.1.0 — EOL re-keyed to (OS, OS-version) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-key Module 1 (EOL/EOS) so one Finding is emitted per
`(operatingsystems_id, operatingsystemversions_id)` pair in live inventory
instead of per `operatingsystems_id` alone, driven by a shipped seed CSV +
`endoflife.date`, with `fields` "LifeCycle" as an override and a real
`\Migration` object carrying v1.0.x installs forward.

**Architecture:** `EolScan::run()` becomes two-pass: Pass 1 walks every
`(os,ver)` pair in inventory and resolves each to a lifecycle date or a
`{product,cycle}` via `_eolmap` / a new seed-CSV matcher (`EolMatch` +
`EolProducts`), auto-writing `_eolmap` on a fresh seed match; a cache-fill
step (`EolCache::ensureFresh()`) then fetches anything newly mapped; Pass 2
grades and emits. `EolMap`, `Reconcile`, `LifeCycleReader` and
`AssetResolver` all grow a version dimension. `Migration` gains a real
`\Migration` object that adds the new columns/keys (idempotent — existence
checks are the gate, not version comparisons), closes old-format Findings
with a superseded note, and resets the two tables SAC owns outright
(`_reconcile` eol rows, `_eolmap` auto rows).

**Tech Stack:** PHP 8.4, GLPI 11 plugin APIs (`\Migration`, `\DBmysql`
query builder), `riskregister`'s `Finding`/`Finding_Item` itemtype API,
`endoflife.date` REST API (JSON over HTTPS).

**Spec:** `docs/superpowers/specs/2026-09-10-eol-multidim-design.md` — this
plan implements §4–§14 of that spec; §15 lists the smaller defaults the spec
already resolved (silent `_skip`, wipe-and-regenerate reconcile, purge `auto`
`_eolmap` rows, `ver_id=0` is a valid grain, version-level LifeCycle wins,
`cache_pending` is counter-only).

## Global Constraints

- **Local test env:** `C:\php\php.exe` = PHP 8.4, extensions: `json` only —
  no `mb_*`, network, or GLPI classes in any offline-tested code path. Every
  touched `.php` file must pass `"/c/php/php.exe" -l`.
- **Grain:** every Module 1 EOL Finding is per `(operatingsystems_id,
  operatingsystemversions_id)` pair — never per OS name/id alone.
- **Finding `code` format:** `SAC-EOL-OS-<os_id>-<ver_id>` (≤50 chars,
  deterministic, UNIQUE with `entities_id`). Hardware codes
  (`SAC-EOL-HW-<computers_id>`) are unchanged and out of scope.
- **Never hard-delete a Finding.** Old-format Findings are closed
  (`status = 'Closed'`) with a superseded content note — a promoted
  riskregister Risk holds a FK to the Finding.
- **`\Migration` idempotency is the version gate.** `addField`/`addKey` are
  no-ops when the field/key already exists — guard with `fieldExists()` /
  `indexExists()`, never compare `PLUGIN_SAC_VERSION` strings.
- **New timestamp columns:** `'timestamp'` type only (GLPI canonical
  `timestamp NULL DEFAULT NULL`), never `'datetime'`.
- **The seed CSV (`install/data/eol_products.csv`) is read-only at scan
  time** — never copied into a DB table, never admin-editable through the UI
  (spec Q4). Admins extend coverage only via Reconcile → confirmed `_eolmap`
  rows, which the seed never overwrites.
- **No new `plugin:sac` config keys** in this plan.
- Every class exposing `selfChecks()` follows the existing convention:
  `db/`-prefixed keys need `$DB`/GLPI (VM-only, `tools/selfcheck.php`);
  everything else must be pure and pass `tools/offline-selfcheck.php`.

---

## Task 1: Seed CSV + `EolProducts` loader

**Files:**
- Create: `install/data/eol_products.csv`
- Create: `src/Eol/EolProducts.php`

**Interfaces:**
- Produces: `EolProducts::rules(): array<int,array{pattern:string,product:string,cycle_source:string,cycle_rule:string}>`
  — parsed, cached, in file order (most-specific `name_pattern` first).
  `EolProducts::resetCache(): void` — test hook.

- [ ] **Step 1: Write the seed CSV**

`install/data/eol_products.csv`:

```csv
name_pattern,product,cycle_source,cycle_rule,comment
red hat enterprise linux,rhel,version,major,
rocky linux,rocky-linux,version,major,
almalinux,almalinux,version,major,
oracle linux,oracle-linux,version,major,
oracle,oracle-linux,version,major,VM inventory labels Oracle Linux as just Oracle
centos stream,centos-stream,version,major,
centos,centos,version,major,
ubuntu,ubuntu,version,yy.mm,
debian,debian,version,major,
suse linux enterprise server,sles,version,major,
suse linux enterprise,sles,version,major,
opensuse leap,opensuse,version,yy.mm,
amazon linux,amazon-linux,version,major,
windows server,windows-server,name,winsrv,version dropdown is usually blank
windows,windows,name,winclient,
vmware esxi,vmware-esxi,version,xy,
esxi,vmware-esxi,version,xy,
vmware vcenter,vmware-vcenter,version,xy,
kubernetes,_skip,,,appliance node not an OS lifecycle
k3s,_skip,,,
```

Rows are consulted top-to-bottom; a more specific pattern (`red hat
enterprise linux`) must precede a less specific one that could also match
(there is none in this seed, but `oracle linux` correctly precedes the bare
`oracle` fallback).

- [ ] **Step 2: Write `EolProducts`**

```php
<?php

namespace GlpiPlugin\Sac\Eol;

/**
 * Loads the shipped `endoflife.date` product-matching seed:
 * `install/data/eol_products.csv`. Rows are consulted in file order — author
 * the most specific `name_pattern` first (e.g. "red hat enterprise linux"
 * before the bare "oracle" fallback). Never written to; re-shipped verbatim
 * on every `plugin:install --force` (spec §4.4/§15 Q4 — no rules-editor UI,
 * no database copy; admins extend coverage per-exception through Reconcile
 * instead).
 */
final class EolProducts
{
    private const KNOWN_SOURCES = ['version', 'name'];
    private const KNOWN_RULES   = ['major', 'yy.mm', 'xy', 'winsrv', 'winclient', 'exact'];

    /** @var array<int,array{pattern:string,product:string,cycle_source:string,cycle_rule:string}>|null */
    private static ?array $rules = null;

    private static function csvPath(): string
    {
        return dirname(__DIR__, 2) . '/install/data/eol_products.csv';
    }

    /**
     * Parsed, cached seed rows in file order. A `product` of `_skip` marks a
     * non-OS name (e.g. an appliance) that must never produce a Finding or a
     * Reconcile row; such rows carry empty `cycle_source`/`cycle_rule`.
     *
     * @return array<int,array{pattern:string,product:string,cycle_source:string,cycle_rule:string}>
     */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        self::$rules = [];

        $path = self::csvPath();
        if (!is_file($path)) {
            return self::$rules;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            return self::$rules;
        }

        fgetcsv($fh); // discard the header row
        while (($row = fgetcsv($fh)) !== false) {
            if (!isset($row[0]) || trim((string) $row[0]) === '') {
                continue; // blank line
            }
            self::$rules[] = [
                'pattern'      => strtolower(trim((string) $row[0])),
                'product'      => trim((string) ($row[1] ?? '')),
                'cycle_source' => trim((string) ($row[2] ?? '')),
                'cycle_rule'   => trim((string) ($row[3] ?? '')),
            ];
        }
        fclose($fh);

        return self::$rules;
    }

    /** Test hook — drop the cache so a check re-parses the file. */
    public static function resetCache(): void
    {
        self::$rules = null;
    }

    /** @return array<string,callable():void> pure — reads the shipped file, no DB. */
    public static function selfChecks(): array
    {
        return [
            'eol/products-csv-valid' => static function (): void {
                self::resetCache();
                $rules = self::rules();
                sac_assert(count($rules) > 0, 'the shipped CSV parses to at least one rule');

                foreach ($rules as $r) {
                    sac_assert($r['pattern'] !== '', 'every row has a non-empty name_pattern');
                    sac_assert($r['product'] !== '', 'every row has a product (or _skip)');
                    if ($r['product'] === '_skip') {
                        sac_assert(
                            $r['cycle_source'] === '' && $r['cycle_rule'] === '',
                            '_skip rows carry no cycle_source/cycle_rule'
                        );
                        continue;
                    }
                    sac_assert(
                        in_array($r['cycle_source'], self::KNOWN_SOURCES, true),
                        "cycle_source '{$r['cycle_source']}' for '{$r['pattern']}' is version|name"
                    );
                    sac_assert(
                        in_array($r['cycle_rule'], self::KNOWN_RULES, true),
                        "cycle_rule '{$r['cycle_rule']}' for '{$r['pattern']}' is a known rule"
                    );
                }
            },
        ];
    }
}
```

- [ ] **Step 3: Run and verify**

Run: `"/c/php/php.exe" -l src/Eol/EolProducts.php && "/c/php/php.exe" tools/offline-selfcheck.php products-csv`
Expected: `PASS  eol/products-csv-valid` — `1 checks, 0 failed`.

Run: `"/c/php/php.exe" tools/offline-selfcheck.php`
Expected: `17 checks, 0 failed` (baseline 16 + this new check).

- [ ] **Step 4: Commit**

```bash
git add install/data/eol_products.csv src/Eol/EolProducts.php
git commit -m "feat: eol_products.csv seed + EolProducts loader"
```

---

## Task 2: `Code::eolOs` — two-argument grain

**Files:**
- Modify: `src/Code.php`

**Interfaces:**
- Produces: `Code::eolOs(int $osId, int $verId): string` → `SAC-EOL-OS-<osId>-<verId>`
  (breaking signature change — `EolScan` is updated to the new call shape in Task 10).

- [ ] **Step 1: Change the method**

In `src/Code.php`, replace:

```php
    public static function eolOs(int $id): string       { return 'SAC-EOL-OS-' . $id; }
```

with:

```php
    public static function eolOs(int $osId, int $verId): string { return 'SAC-EOL-OS-' . $osId . '-' . $verId; }
```

- [ ] **Step 2: Update `selfChecks()`**

Replace the body of `selfChecks()` with:

```php
    public static function selfChecks(): array
    {
        return [
            'code/format' => static function (): void {
                sac_assert_eq(self::eolOs(42, 7), 'SAC-EOL-OS-42-7', 'eolOs');
                sac_assert_eq(self::licMissing(7), 'SAC-LIC-MISSING-7', 'licMissing');
            },
            'code/length' => static function (): void {
                // longest prefix + two max 10-digit ids must stay <= 50
                sac_assert(strlen(self::eolOs(2147483647, 2147483647)) <= 50, 'eolOs <=50');
                sac_assert(strlen(self::licMissing(2147483647)) <= 50, 'licMissing <=50');
                sac_assert(strlen(self::eolHw(2147483647)) <= 50, 'eolHw <=50');
            },
        ];
    }
```

- [ ] **Step 3: Run and verify**

Run: `"/c/php/php.exe" -l src/Code.php && "/c/php/php.exe" tools/offline-selfcheck.php code/`
Expected: `PASS  code/format`, `PASS  code/length` — `2 checks, 0 failed`.

(Full-suite count is still `17` — this task only changes existing checks, not the count. `EolScan.php` still calls the old one-argument `Code::eolOs($osId)` at this point, which is now a signature mismatch — that is expected and fixed in Task 10; PHP does not type-check call arity at parse time, so `-l` still passes, and `EolScan`'s own tests are `db/`-prefixed so `tools/offline-selfcheck.php` does not exercise them yet.)

- [ ] **Step 4: Commit**

```bash
git add src/Code.php
git commit -m "feat: Code::eolOs takes (osId, verId) — v1.1.0 grain"
```

---

## Task 3: `EolMatch` rework — seed-CSV driven `resolve()`

**Files:**
- Modify (full rewrite): `src/EolMatch.php`

**Interfaces:**
- Consumes: `EolProducts::rules()` (Task 1).
- Produces:
  - `EolMatch::resolve(string $osName, string $verName): ?array` — `['skip' => true]`
    for a non-OS pattern hit; `['product' => string, 'cycle' => string]` on a
    resolved match; `null` when nothing resolves. **Public and pure** by
    design — kept stable for a future cross-plugin normaliser (spec §13).
  - `EolMatch::cycleFrom(string $rule, string $source): ?string` — the six
    named cycle rules (`major`, `yy.mm`, `xy`, `winsrv`, `winclient`, `exact`).
  - Removed: `cycleFromVersion(string,string)`, `fuzzyProduct(string,array)` —
    both are superseded by the seed CSV.

- [ ] **Step 1: Replace the whole file**

```php
<?php

namespace GlpiPlugin\Sac;

use GlpiPlugin\Sac\Eol\EolProducts;

/**
 * Matches a GLPI OS name + version string against the shipped seed
 * (`install/data/eol_products.csv`, {@see EolProducts}) to a candidate
 * `endoflife.date` product + cycle. Pure — no `$DB`, safe offline.
 */
final class EolMatch
{
    /**
     * @return array{skip:true}|array{product:string,cycle:string}|null
     *         `['skip' => true]` for a non-OS `name_pattern` hit (e.g.
     *         "Kubernetes"); `['product' => ..., 'cycle' => ...]` on a
     *         resolved match; `null` when no pattern matches, or every
     *         matching pattern's cycle rule fails to extract a cycle from its
     *         source string.
     */
    public static function resolve(string $osName, string $verName): ?array
    {
        $name = strtolower($osName);

        foreach (EolProducts::rules() as $rule) {
            if (!str_contains($name, $rule['pattern'])) {
                continue;
            }
            if ($rule['product'] === '_skip') {
                return ['skip' => true];
            }
            $source = $rule['cycle_source'] === 'name' ? $osName : $verName;
            $cycle  = self::cycleFrom($rule['cycle_rule'], $source);
            if ($cycle !== null) {
                return ['product' => $rule['product'], 'cycle' => $cycle];
            }
            // This pattern matched but its source string yielded no cycle
            // (e.g. a `cycle_source=version` rule with no version dropdown
            // set) — keep scanning in case a later, less-specific pattern
            // also matches and does resolve.
        }

        return null;
    }

    public static function cycleFrom(string $rule, string $source): ?string
    {
        $s = strtolower(trim($source));
        if ($s === '') {
            return null;
        }

        return match ($rule) {
            'major'     => self::cycleMajor($s),
            'yy.mm'     => self::cycleYyMm($s),
            'xy'        => self::cycleXy($s),
            'winsrv'    => self::cycleWindowsServer($s),
            'winclient' => self::cycleWindowsClient($s),
            'exact'     => trim($source),
            default     => null,
        };
    }

    /** First integer run = major version: "9.7" -> "9", "release 7.9" -> "7". */
    private static function cycleMajor(string $s): ?string
    {
        return preg_match('/(?:release\s+)?(\d+)(?:\.\d+)*/', $s, $m) ? $m[1] : null;
    }

    /** Two dotted groups, YY.MM width: "22.04.3 lts" -> "22.04". */
    private static function cycleYyMm(string $s): ?string
    {
        return preg_match('/(\d{2}\.\d{2})/', $s, $m) ? $m[1] : null;
    }

    /** Two dotted groups, any width: "7.0.3" -> "7.0", "8.0 update 1" -> "8.0". */
    private static function cycleXy(string $s): ?string
    {
        return preg_match('/(\d+\.\d+)/', $s, $m) ? $m[1] : null;
    }

    private static function cycleWindowsServer(string $s): ?string
    {
        if (!preg_match('/server\s+(\d{4})(\s*r2)?/', $s, $m)) {
            return null;
        }
        return $m[1] . (isset($m[2]) && trim($m[2]) !== '' ? '-R2' : '');
    }

    private static function cycleWindowsClient(string $s): ?string
    {
        return preg_match('/windows\s+(\d{1,2})/', $s, $m) ? $m[1] : null;
    }

    public static function selfChecks(): array
    {
        return [
            'eolmatch/cycle-rules' => static function (): void {
                sac_assert_eq(self::cycleFrom('major', 'Red Hat Enterprise Linux 8.6'), '8', 'major: rhel 8.6 -> 8');
                sac_assert_eq(self::cycleFrom('major', 'Red Hat Enterprise Linux release 7.9'), '7', 'major: release 7.9 -> 7');
                sac_assert_eq(self::cycleFrom('yy.mm', 'Ubuntu 20.04.6 LTS'), '20.04', 'yy.mm: 20.04.x -> 20.04');
                sac_assert_eq(self::cycleFrom('yy.mm', 'Ubuntu 22.04 LTS'), '22.04', 'yy.mm: 22.04');
                sac_assert_eq(self::cycleFrom('xy', '7.0.3'), '7.0', 'xy: 7.0.3 -> 7.0');
                sac_assert_eq(self::cycleFrom('xy', '8.0 Update 1'), '8.0', 'xy: 8.0 update 1 -> 8.0');
                sac_assert_eq(self::cycleFrom('winsrv', 'Microsoft Windows Server 2019 Standard'), '2019', 'winsrv: 2019');
                sac_assert_eq(self::cycleFrom('winsrv', 'Windows Server 2012 R2'), '2012-R2', 'winsrv: 2012 R2');
                sac_assert_eq(self::cycleFrom('winclient', 'Windows 10 Enterprise'), '10', 'winclient: 10');
                sac_assert_eq(self::cycleFrom('exact', ' 2024.1 '), '2024.1', 'exact: verbatim, trimmed');
                sac_assert_eq(self::cycleFrom('major', 'Some Linux'), null, 'major: no digits -> null');
                sac_assert_eq(self::cycleFrom('major', ''), null, 'blank source -> null');
            },
            'eolmatch/resolve-precedence' => static function (): void {
                sac_assert_eq(self::resolve('Red Hat Enterprise Linux', '8.6')['product'], 'rhel', 'RHEL resolves via version source');
                sac_assert_eq(self::resolve('Oracle', '9.7')['product'], 'oracle-linux', '"Oracle" alone matches the Oracle Linux fallback pattern');
                sac_assert_eq(self::resolve('Ubuntu', '22.04.3 LTS')['cycle'], '22.04', 'Ubuntu cycle from version source');
                sac_assert_eq(
                    self::resolve('Microsoft Windows Server 2019 Datacenter', ''),
                    ['product' => 'windows-server', 'cycle' => '2019'],
                    'Windows Server resolves from the OS name even with no version dimension'
                );
            },
            'eolmatch/resolve-skip-and-miss' => static function (): void {
                sac_assert_eq(self::resolve('Kubernetes', '1.29'), ['skip' => true], 'Kubernetes is a _skip pattern');
                sac_assert_eq(self::resolve('TotallyUnknown OS', '1.0'), null, 'no pattern matches -> null');
            },
        ];
    }
}
```

- [ ] **Step 2: Run and verify**

Run: `"/c/php/php.exe" -l src/EolMatch.php && "/c/php/php.exe" tools/offline-selfcheck.php eolmatch`
Expected: `PASS  eolmatch/cycle-rules`, `PASS  eolmatch/resolve-precedence`, `PASS  eolmatch/resolve-skip-and-miss` — `3 checks, 0 failed`.

Run: `"/c/php/php.exe" tools/offline-selfcheck.php`
Expected: `15 checks, 0 failed` (17 from Task 1, minus the 5 old `eolmatch/*` checks, plus these 3 new ones: 17 − 5 + 3 = 15).

- [ ] **Step 3: Commit**

```bash
git add src/EolMatch.php
git commit -m "feat: EolMatch.resolve() — seed-CSV driven product/cycle matching"
```

---

## Task 4: `AssetResolver::computersForOsVersion`

**Files:**
- Modify: `src/AssetResolver.php:1-54` (add a method after `computersForOs()`), `src/AssetResolver.php:107-144` (`selfChecks()`)

**Interfaces:**
- Produces: `AssetResolver::computersForOsVersion(int $osId, int $verId): array{resolved:array<int,string>,unresolved:array<int,string>}`
  — same shape as `computersForOs()`, filtered to the exact `(os,ver)` pair.

- [ ] **Step 1: Add the method**

In `src/AssetResolver.php`, immediately after the closing `}` of
`computersForOs()` (currently ending at line 54), add:

```php
    /**
     * Computers currently running the exact (OperatingSystem,
     * OperatingSystemVersion) pair — the v1.1.0 EOL grain (spec
     * 2026-09-10-eol-multidim-design.md §7.1). `verId = 0` is a legal,
     * distinct bucket ("version dimension not set/known").
     *
     * @return array{resolved:array<int,string>,unresolved:array<int,string>}
     */
    public static function computersForOsVersion(int $osId, int $verId): array
    {
        global $DB;
        $resolved = [];
        foreach ($DB->request([
            'SELECT'     => [
                'glpi_computers.id AS id',
                'glpi_computers.name AS name',
            ],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_items_operatingsystems',
            'INNER JOIN' => [
                'glpi_computers' => [
                    'ON' => [
                        'glpi_items_operatingsystems' => 'items_id',
                        'glpi_computers'              => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                'glpi_items_operatingsystems.operatingsystems_id'        => $osId,
                'glpi_items_operatingsystems.operatingsystemversions_id' => $verId,
                'glpi_items_operatingsystems.itemtype'                   => 'Computer',
                'glpi_computers.is_deleted'                              => 0,
                'glpi_computers.is_template'                             => 0,
            ],
        ]) as $row) {
            $resolved[(int) $row['id']] = (string) $row['name'];
        }

        return ['resolved' => $resolved, 'unresolved' => []];
    }
```

- [ ] **Step 2: Add a `db/` selfCheck**

In `selfChecks()`, add this entry alongside the existing `db/os-computers`:

```php
            'db/os-version-computers' => static function (): void {
                global $DB;
                $osId = 0; $verId = 0; $cId = 0; $cId2 = 0;

                try {
                    $osId  = (int) (new \OperatingSystem())->add(['name' => '__sac_os2__']);
                    $verId = (int) (new \OperatingSystemVersion())->add([
                        'name' => '__sac_ver__', 'operatingsystems_id' => $osId,
                    ]);
                    $cId   = (int) (new \Computer())->add(['name' => '__sac_pc2a__', 'entities_id' => 0]);
                    $cId2  = (int) (new \Computer())->add(['name' => '__sac_pc2b__', 'entities_id' => 0]);
                    $DB->insert('glpi_items_operatingsystems', [
                        'items_id' => $cId, 'itemtype' => 'Computer',
                        'operatingsystems_id' => $osId, 'operatingsystemversions_id' => $verId,
                    ]);
                    // Same OS, version 0 (unknown) — must NOT show up for the specific version.
                    $DB->insert('glpi_items_operatingsystems', [
                        'items_id' => $cId2, 'itemtype' => 'Computer',
                        'operatingsystems_id' => $osId, 'operatingsystemversions_id' => 0,
                    ]);

                    $res = self::computersForOsVersion($osId, $verId);
                    sac_assert_eq($res['resolved'], [$cId => '__sac_pc2a__'], 'only the exact (os,ver) pair is returned');

                    $res0 = self::computersForOsVersion($osId, 0);
                    sac_assert_eq($res0['resolved'], [$cId2 => '__sac_pc2b__'], 'ver_id=0 is its own distinct bucket');
                } finally {
                    $DB->delete('glpi_items_operatingsystems', ['operatingsystems_id' => $osId]);
                    $DB->delete('glpi_computers', ['id' => $cId]);
                    $DB->delete('glpi_computers', ['id' => $cId2]);
                    $DB->delete('glpi_operatingsystemversions', ['id' => $verId]);
                    $DB->delete('glpi_operatingsystems', ['id' => $osId]);
                }
            },
```

- [ ] **Step 3: Run and verify**

Run: `"/c/php/php.exe" -l src/AssetResolver.php && "/c/php/php.exe" tools/offline-selfcheck.php`
Expected: `15 checks, 0 failed` (unchanged — the new check is `db/`-prefixed, skipped offline; it runs on the VM in the deployment pass).

- [ ] **Step 4: Commit**

```bash
git add src/AssetResolver.php
git commit -m "feat: AssetResolver::computersForOsVersion() — pair-exact resolution"
```

---

## Task 5: `EolMap` — pair-keyed

**Files:**
- Modify (full rewrite): `src/Eol/EolMap.php`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `EolMap::forOsVersion(int $osId, int $verId): ?array{eol_product:string,eol_cycle:string,confidence:string}`
    — tries the exact pair, then falls back to `(osId, 0)`.
  - `EolMap::putAuto(int $osId, int $verId, string $product, string $cycle): void`
  - `EolMap::putConfirmed(int $osId, int $verId, string $product, string $cycle, int $userId): void`
  - Removed: `forOs(int)`, `putAuto(int,string,string)`, `putConfirmed(int,string,string,int)` (single-int signatures).

- [ ] **Step 1: Replace the whole file**

```php
<?php

namespace GlpiPlugin\Sac\Eol;

/**
 * Maps a GLPI (OperatingSystem, OperatingSystemVersion) pair to an
 * endoflife.date product/cycle. One row per pair in `glpi_plugin_sac_eolmap`
 * (UNIQUE on operatingsystems_id + operatingsystemversions_id).
 *
 * `operatingsystemversions_id = 0` is a legal key: the "version unknown /
 * not inventoried" fallback for that OS (spec
 * 2026-09-10-eol-multidim-design.md §4.1).
 */
final class EolMap
{
    private const TABLE = 'glpi_plugin_sac_eolmap';

    private static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    /**
     * @return array{eol_product:string,eol_cycle:string,confidence:string}|null
     *         Tries the exact (osId, verId) pair first, then falls back to the
     *         (osId, 0) "version unknown" row.
     */
    public static function forOsVersion(int $osId, int $verId): ?array
    {
        global $DB;
        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['operatingsystems_id' => $osId, 'operatingsystemversions_id' => $verId],
        ])->current();

        if (!$row && $verId !== 0) {
            $row = $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['operatingsystems_id' => $osId, 'operatingsystemversions_id' => 0],
            ])->current();
        }

        if (!$row) {
            return null;
        }
        return [
            'eol_product' => (string) $row['eol_product'],
            'eol_cycle'   => (string) $row['eol_cycle'],
            'confidence'  => (string) $row['confidence'],
        ];
    }

    /** Store/refresh an auto-detected mapping (confidence = 'auto'). */
    public static function putAuto(int $osId, int $verId, string $product, string $cycle): void
    {
        self::put($osId, $verId, $product, $cycle, 'auto', null);
    }

    /** Store a human-confirmed mapping, stamping the confirming user + time. */
    public static function putConfirmed(int $osId, int $verId, string $product, string $cycle, int $userId): void
    {
        self::put($osId, $verId, $product, $cycle, 'confirmed', $userId);
    }

    private static function put(int $osId, int $verId, string $product, string $cycle, string $confidence, ?int $userId): void
    {
        global $DB;
        $now    = self::now();
        $key    = ['operatingsystems_id' => $osId, 'operatingsystemversions_id' => $verId];
        $fields = [
            'eol_product' => $product,
            'eol_cycle'   => $cycle,
            'confidence'  => $confidence,
            'date_mod'    => $now,
        ];
        if ($confidence === 'confirmed') {
            $fields['users_id_confirmed'] = $userId;
            $fields['date_confirmed']     = $now;
        }

        $exists = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::TABLE,
            'WHERE' => $key,
        ])->current()['cpt'] ?? 0;

        if ($exists) {
            $DB->update(self::TABLE, $fields, $key);
        } else {
            $DB->insert(self::TABLE, $fields + $key + ['date_creation' => $now]);
        }
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/eolmap-upsert' => static function (): void {
                global $DB;
                $osId  = 2147480000; // improbable OS id, safe to seed/delete
                $verId = 2147480010;

                self::putAuto($osId, $verId, 'ubuntu', '22.04');
                $m = self::forOsVersion($osId, $verId);
                sac_assert_eq($m['eol_product'], 'ubuntu', 'auto product stored');
                sac_assert_eq($m['confidence'], 'auto', 'confidence auto');

                self::putConfirmed($osId, $verId, 'ubuntu', '20.04', 7);
                $m = self::forOsVersion($osId, $verId);
                sac_assert_eq($m['eol_cycle'], '20.04', 'cycle updated in place');
                sac_assert_eq($m['confidence'], 'confirmed', 'confidence confirmed');

                $cpt = $DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => ['operatingsystems_id' => $osId]])
                    ->current()['c'];
                sac_assert_eq((int) $cpt, 1, 'still exactly one row for this (os,ver) pair');

                $DB->delete(self::TABLE, ['operatingsystems_id' => $osId]);
            },

            'db/eolmap-version-distinct' => static function (): void {
                global $DB;
                $osId = 2147480001; // one OS, two versions
                try {
                    self::putAuto($osId, 100, 'rhel', '7');
                    self::putAuto($osId, 200, 'rhel', '9');

                    sac_assert_eq(self::forOsVersion($osId, 100)['eol_cycle'], '7', 'version 100 keeps its own cycle');
                    sac_assert_eq(self::forOsVersion($osId, 200)['eol_cycle'], '9', 'version 200 keeps its own cycle');

                    $cpt = $DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => ['operatingsystems_id' => $osId]])
                        ->current()['c'];
                    sac_assert_eq((int) $cpt, 2, 'two distinct rows for the same OS');
                } finally {
                    $DB->delete(self::TABLE, ['operatingsystems_id' => $osId]);
                }
            },

            'db/eolmap-zero-version-fallback' => static function (): void {
                global $DB;
                $osId = 2147480002;
                try {
                    self::putConfirmed($osId, 0, 'debian', '11', 1); // "version unknown" fallback
                    $m = self::forOsVersion($osId, 999); // a specific, unmapped version
                    sac_assert($m !== null, 'an unmapped version falls back to the (os,0) row');
                    sac_assert_eq($m['eol_product'], 'debian', 'fallback product returned');
                } finally {
                    $DB->delete(self::TABLE, ['operatingsystems_id' => $osId]);
                }
            },
        ];
    }
}
```

- [ ] **Step 2: Run and verify**

Run: `"/c/php/php.exe" -l src/Eol/EolMap.php && "/c/php/php.exe" tools/offline-selfcheck.php`
Expected: `15 checks, 0 failed` (unchanged — all new checks are `db/`-prefixed).

- [ ] **Step 3: Commit**

```bash
git add src/Eol/EolMap.php
git commit -m "feat: EolMap keyed on (operatingsystems_id, operatingsystemversions_id)"
```

---

## Task 6: `LifeCycleReader` — OS-level and version-level containers

**Files:**
- Modify (full rewrite): `src/Eol/LifeCycleReader.php`

**Interfaces:**
- Produces:
  - `LifeCycleReader::available(): bool` — true if either container resolves.
  - `LifeCycleReader::endOfLifeFor(int $operatingsystemsId, int $operatingsystemversionsId = 0): string|false|null`
    — version-level container wins when `$operatingsystemversionsId > 0` and it
    resolves (string or `false`); else falls back to the OS-level container.
  - `LifeCycleReader::tableName(string $level = 'os'): string`,
    `LifeCycleReader::dateColumnName(string $level = 'os'): string`,
    `LifeCycleReader::resetProbeCache(): void` — `$level` is `'os'` or `'version'`.

- [ ] **Step 1: Replace the whole file**

```php
<?php

namespace GlpiPlugin\Sac\Eol;

/**
 * Read-only view of the `fields` plugin's "LifeCycle" data on Operating
 * Systems AND Operating System Versions.
 *
 * The `fields` plugin lets an admin bolt a custom container onto an itemtype.
 * For a dropdown itemtype it creates a table named
 * `glpi_plugin_fields_<itemtype-lower><container-lower>s`, keyed by
 * `items_id` (the dropdown row id). SAC v1.1.0 (spec
 * 2026-09-10-eol-multidim-design.md §7.4) reads TWO such containers:
 *
 *   - `os`      — on `OperatingSystem`        (v1.0.x behaviour, unchanged)
 *   - `version` — on `OperatingSystemVersion` (new)
 *
 * When both carry a date for a pair, the `version` container wins (more
 * specific) — see {@see endOfLifeFor()}.
 *
 * This class NEVER writes the `fields` tables — every access is a guarded
 * SELECT. Real table/column names are VM-verified; the probe lists are
 * deliberately defensive and editable, and degrade to `''` / `null` rather
 * than fatal when the layout is not what we assume.
 */
final class LifeCycleReader
{
    /** Resolved container table per level ('os'|'version'). null = not yet probed, '' = none found. */
    private static array $table = ['os' => null, 'version' => null];

    /** Resolved EOL date column per level. null = not yet probed, '' = none found. */
    private static array $dateCol = ['os' => null, 'version' => null];

    private const EXACT_TABLE = [
        'os'      => 'glpi_plugin_fields_operatingsystemlifecycles',
        'version' => 'glpi_plugin_fields_operatingsystemversionlifecycles',
    ];

    /**
     * LIKE fallback per level. Tightened to require "operatingsystemlifecycle"
     * / "operatingsystemversionlifecycle" as a CONTIGUOUS substring (no
     * wildcard between the itemtype and "lifecycle") so the 'os' probe can
     * never also match the 'version' table (which contains "operatingsystem"
     * too) or vice versa.
     */
    private const TABLE_LIKE = [
        'os'      => 'glpi_plugin_fields_operatingsystemlifecycle%',
        'version' => 'glpi_plugin_fields_operatingsystemversionlifecycle%',
    ];

    private const ITEMTYPE = [
        'os'      => 'OperatingSystem',
        'version' => 'OperatingSystemVersion',
    ];

    public static function available(): bool
    {
        if (!\Plugin::isPluginActive('fields')) {
            return false;
        }
        return self::tableName('os') !== '' || self::tableName('version') !== '';
    }

    /**
     * Resolve one level's container table once and cache it. Probe order:
     *   1. the exact expected table name for this level
     *   2. the first table matching this level's LIKE pattern
     * Returns '' when nothing matches.
     */
    public static function tableName(string $level = 'os'): string
    {
        global $DB;
        if (self::$table[$level] !== null) {
            return self::$table[$level];
        }
        self::$table[$level] = '';

        if ($DB->tableExists(self::EXACT_TABLE[$level])) {
            self::$table[$level] = self::EXACT_TABLE[$level];
            return self::$table[$level];
        }

        $other = $level === 'os' ? 'version' : 'os';
        foreach ($DB->listTables(self::TABLE_LIKE[$level]) as $row) {
            $name = is_array($row) ? (string) reset($row) : (string) $row;
            if ($name === '' || $name === self::EXACT_TABLE[$other]) {
                continue; // defense-in-depth: never let a level claim the other's table
            }
            self::$table[$level] = $name;
            break;
        }
        return self::$table[$level];
    }

    /**
     * End-of-life date for one (OperatingSystem, OperatingSystemVersion) pair.
     * Checks the version-level container first when `$operatingsystemversionsId
     * > 0` (more specific — pass 0 to skip it), then the OS-level container. A
     * bad date at the more-specific level short-circuits — it does NOT fall
     * through to the OS-level container (the operator entered *something*;
     * surface it via `bad_date`, don't silently ignore it).
     *
     * @return string|false|null  `Y-m-d` when a row with a parseable date
     *                            exists; `false` when a row exists but the date
     *                            is blank / `0000-00-00` / unparseable (caller
     *                            writes a `bad_date` reconcile signal); `null`
     *                            when neither container has a usable row.
     */
    public static function endOfLifeFor(int $operatingsystemsId, int $operatingsystemversionsId = 0): string|false|null
    {
        if (!self::available()) {
            return null;
        }

        if ($operatingsystemversionsId > 0) {
            $fromVersion = self::readLevel('version', $operatingsystemversionsId);
            if ($fromVersion !== null) {
                return $fromVersion;
            }
        }

        return self::readLevel('os', $operatingsystemsId);
    }

    /** @return string|false|null one level's read; null = no usable row at this level. */
    private static function readLevel(string $level, int $id): string|false|null
    {
        global $DB;
        $table = self::tableName($level);
        if ($table === '') {
            return null;
        }
        $col = self::dateColumn($level);
        if ($col === '') {
            return null; // table present but the date column could not be resolved
        }
        if (!$DB->fieldExists($table, 'items_id')) {
            return null;
        }

        $where = ['items_id' => $id];
        if ($DB->fieldExists($table, 'itemtype')) {
            $where['itemtype'] = self::ITEMTYPE[$level];
        }

        $row = $DB->request(['SELECT' => [$col], 'FROM' => $table, 'WHERE' => $where])->current();
        if (!$row || !array_key_exists($col, (array) $row)) {
            return null;
        }

        $raw = $row[$col];
        if ($raw === null || $raw === '' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
            return false;
        }

        $ts = strtotime((string) $raw);
        return $ts === false ? false : date('Y-m-d', $ts);
    }

    /**
     * Resolve one level's EOL date column once and cache it. Probe order:
     *   1. `endoflifefield` (the `fields` plugin's `Field::prepareName()`
     *      appends `field` to the label-derived system name)
     *   2. `endoflife` / `endoflife_date` / `endoflifedatefield`
     *   3. the sole non-audit `date`/`datetime`/`timestamp` column
     * Returns '' when the table is unknown or no single date column stands out.
     */
    private static function dateColumn(string $level): string
    {
        global $DB;
        if (self::$dateCol[$level] !== null) {
            return self::$dateCol[$level];
        }
        self::$dateCol[$level] = '';

        $table = self::tableName($level);
        if ($table === '') {
            return '';
        }

        foreach (['endoflifefield', 'endoflife', 'endoflife_date', 'endoflifedatefield'] as $col) {
            if ($DB->fieldExists($table, $col)) {
                self::$dateCol[$level] = $col;
                return self::$dateCol[$level];
            }
        }

        $audit      = ['date_creation', 'date_mod'];
        $candidates = [];
        foreach ($DB->listFields($table) as $name => $meta) {
            $name = (string) $name;
            if (in_array($name, $audit, true)) {
                continue;
            }
            $meta = (array) $meta;
            $type = strtolower((string) ($meta['Type'] ?? $meta['type'] ?? ''));
            if (str_starts_with($type, 'date') || str_starts_with($type, 'timestamp') || str_starts_with($type, 'datetime')) {
                $candidates[] = $name;
            }
        }
        if (count($candidates) === 1) {
            self::$dateCol[$level] = $candidates[0];
        }
        return self::$dateCol[$level];
    }

    /**
     * Resolved EOL date column name for one level, or '' when none could be
     * resolved. Public so the VM selfChecks can seed a real `fields` LifeCycle
     * row into the correct column. Not used by production code.
     */
    public static function dateColumnName(string $level = 'os'): string
    {
        return self::dateColumn($level);
    }

    /** Test hook — drop the resolved-name caches so a selfCheck re-probes. */
    public static function resetProbeCache(): void
    {
        self::$table   = ['os' => null, 'version' => null];
        self::$dateCol = ['os' => null, 'version' => null];
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/lifecycle-reads-seeded-date' => static function (): void {
                self::resetProbeCache();

                if (!self::available()) {
                    sac_assert_eq(self::endOfLifeFor(999999, 999998), null, 'endOfLifeFor() degrades to null when fields absent');
                    return;
                }

                sac_assert(
                    self::tableName('os') !== '' || self::tableName('version') !== '',
                    'available() implies at least one resolved table name'
                );
                sac_assert_eq(
                    self::endOfLifeFor(2147480931, 2147480932),
                    null,
                    'endOfLifeFor() returns null (no row at either level), never false, for unknown ids'
                );
            },
        ];
    }
}
```

- [ ] **Step 2: Run and verify**

Run: `"/c/php/php.exe" -l src/Eol/LifeCycleReader.php && "/c/php/php.exe" tools/offline-selfcheck.php`
Expected: `15 checks, 0 failed` (unchanged — its only check is `db/`-prefixed).

- [ ] **Step 3: Commit**

```bash
git add src/Eol/LifeCycleReader.php
git commit -m "feat: LifeCycleReader reads OS-level and version-level fields containers"
```

---

## Task 7: `Reconcile` — sub-id + version-aware display

**Files:**
- Modify: `src/Reconcile.php` (whole file — `note()`, `autoResolveMissing()`,
  `listForDisplay()`, `resolveEolMapping()`, `selfChecks()`)
- Modify: `src/License/CommercialPublisher.php:85,94` (two `Reconcile::note()` call sites)
- Modify: `templates/reconcile.html.twig:18-41` (help-text wording)

**Interfaces:**
- Consumes: `EolMap::putConfirmed(int,int,string,string,int)` (Task 5).
- Produces:
  - `Reconcile::note(string $signal, string $itemtype, int $id, int $subId, string $reason, string $detail): void`
    — **`$subId` is a new 4th positional parameter.**
  - `Reconcile::listForDisplay(bool $includeResolved = false): array` — each
    row gains `ref_subid`; for an `eol`/`OperatingSystem` row, `label`
    combines the OS name and the version name (when `ref_subid > 0`), and the
    `_eolmap` lookup used for the `eol_product`/`eol_cycle` columns is keyed
    on `(ref_id, ref_subid)` instead of `ref_id` alone.
  - `Reconcile::resolveEolMapping(int $id, string $product, string $cycle): void`
    — unchanged public signature; internally now reads both `ref_id` and
    `ref_subid` from the stored row and calls `EolMap::putConfirmed(osId,
    verId, ...)`. **No client-supplied version id is ever trusted** — the
    version comes only from the DB row the Reconcile `id` points at, so
    `front/reconcile.php` needs no new form field for it (a deliberate
    simplification over spec §7.7, which proposed posting a `subid` — reading
    it server-side from the row is simpler and cannot be tampered with).

- [ ] **Step 1: `note()` — add `$subId`**

Replace:

```php
    public static function note(string $signal, string $itemtype, int $id, string $reason, string $detail): void
    {
        global $DB;
        $now = self::now();
        $key = [
            'signal_type'  => $signal,
            'ref_itemtype' => $itemtype,
            'ref_id'       => $id,
            'reason'       => $reason,
        ];
```

with:

```php
    public static function note(string $signal, string $itemtype, int $id, int $subId, string $reason, string $detail): void
    {
        global $DB;
        $now = self::now();
        $key = [
            'signal_type'  => $signal,
            'ref_itemtype' => $itemtype,
            'ref_id'       => $id,
            'ref_subid'    => $subId,
            'reason'       => $reason,
        ];
```

(the rest of the method body is unchanged — `$key` now carries `ref_subid`
into both the existence check and the `updateOrInsert()` call).

- [ ] **Step 2: `autoResolveMissing()` — widen the composite key**

Replace:

```php
        foreach ($DB->request([
            'SELECT' => ['id', 'ref_itemtype', 'ref_id', 'reason'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['signal_type' => $signal, 'is_resolved' => 0],
        ]) as $row) {
            $composite = $row['ref_itemtype'] . ':' . $row['ref_id'] . ':' . $row['reason'];
```

with:

```php
        foreach ($DB->request([
            'SELECT' => ['id', 'ref_itemtype', 'ref_id', 'ref_subid', 'reason'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['signal_type' => $signal, 'is_resolved' => 0],
        ]) as $row) {
            $composite = $row['ref_itemtype'] . ':' . $row['ref_id'] . ':' . $row['ref_subid'] . ':' . $row['reason'];
```

(callers now build `$seenKeys` shaped `"<ref_itemtype>:<ref_id>:<ref_subid>:<reason>"` — Task 10 updates `EolScan`).

- [ ] **Step 3: `listForDisplay()` — pair-aware map lookup + version label**

Replace the whole method with:

```php
    /**
     * Open (or, with $includeResolved, all) reconcile rows, newest activity
     * first, each enriched with a human `label`: the referenced item's display
     * name (plus its version name, for an OS row with `ref_subid > 0`) when
     * `ref_id > 0`, otherwise the free-text `detail`.
     *
     * @return array<int,array{
     *     id:int, signal_type:string, ref_itemtype:string, ref_id:int,
     *     ref_subid:int, reason:string, detail:string, label:string,
     *     is_resolved:bool, eol_product:string, eol_cycle:string,
     *     date_firstseen:?string, date_lastseen:?string
     * }>
     */
    public static function listForDisplay(bool $includeResolved = false): array
    {
        global $DB;

        $where = $includeResolved ? [] : ['is_resolved' => 0];

        // Current (OS, version) -> endoflife.date mappings, keyed "osId:verId",
        // so an `eol` row shows what it was (or would be) mapped to.
        $maps = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_sac_eolmap']) as $m) {
            $key = ((int) $m['operatingsystems_id']) . ':' . ((int) $m['operatingsystemversions_id']);
            $maps[$key] = [
                (string) ($m['eol_product'] ?? ''),
                (string) ($m['eol_cycle'] ?? ''),
            ];
        }

        $out = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => $where,
            'ORDER' => ['is_resolved ASC', 'date_lastseen DESC', 'id DESC'],
        ]) as $row) {
            $refId    = (int) $row['ref_id'];
            $subId    = (int) ($row['ref_subid'] ?? 0);
            $itemtype = (string) $row['ref_itemtype'];
            $detail   = (string) ($row['detail'] ?? '');
            $label    = $detail;

            if ($refId > 0 && $itemtype !== '') {
                $named = self::refLabel($itemtype, $refId);
                if ($subId > 0 && $itemtype === 'OperatingSystem') {
                    $verName = self::refLabel('OperatingSystemVersion', $subId);
                    if ($verName !== '') {
                        $named = trim($named . ' ' . $verName);
                    }
                }
                if ($named !== '') {
                    $label = $named;
                }
            }

            $mapKey = $refId . ':' . $subId;
            [$prod, $cyc] = ($itemtype === 'OperatingSystem' && isset($maps[$mapKey]))
                ? $maps[$mapKey]
                : ['', ''];

            $out[] = [
                'id'             => (int) $row['id'],
                'signal_type'    => (string) $row['signal_type'],
                'ref_itemtype'   => $itemtype,
                'ref_id'         => $refId,
                'ref_subid'      => $subId,
                'reason'         => (string) $row['reason'],
                'detail'         => $detail,
                'label'          => $label,
                'is_resolved'    => (bool) ($row['is_resolved'] ?? 0),
                'eol_product'    => $prod,
                'eol_cycle'      => $cyc,
                'date_firstseen' => $row['date_firstseen'],
                'date_lastseen'  => $row['date_lastseen'],
            ];
        }

        return $out;
    }
```

(`refLabel()` is unchanged — it already works for any itemtype/id pair, and
`OperatingSystemVersion` resolves through the same `Dropdown::getDropdownName('glpi_operatingsystemversions', $id)` path.)

- [ ] **Step 4: `resolveEolMapping()` — read `ref_subid` from the row**

Replace the whole method with:

```php
    /**
     * Resolve an EOL "no_mapping" signal: write a human-confirmed
     * (OperatingSystem, OperatingSystemVersion) -> endoflife.date mapping for
     * the row's (ref_id, ref_subid), then resolve. The version id is read
     * from the stored row only — never from client input — so a tampered
     * request cannot mis-map a different pair.
     */
    public static function resolveEolMapping(int $id, string $product, string $cycle): void
    {
        global $DB;

        $row   = $DB->request([
            'SELECT' => ['ref_id', 'ref_subid'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['id' => (int) $id],
        ])->current();
        $osId  = (int) (is_array($row) ? ($row['ref_id'] ?? 0) : 0);
        $verId = (int) (is_array($row) ? ($row['ref_subid'] ?? 0) : 0);

        // Stale / already-deleted reconcile row -> don't write a junk
        // operatingsystems_id=0 mapping.
        if ($osId === 0) {
            return;
        }

        \GlpiPlugin\Sac\Eol\EolMap::putConfirmed(
            $osId,
            $verId,
            $product,
            $cycle,
            (int) ($_SESSION['glpiID'] ?? 0)
        );
        self::resolve((int) $id);
    }
```

- [ ] **Step 5: Update `selfChecks()`**

Replace the whole `selfChecks()` method with:

```php
    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/reconcile-upsert' => static function (): void {
                global $DB;
                $sig = '__sac_selftest__';
                $key = [
                    'signal_type'  => $sig,
                    'ref_itemtype' => 'Computer',
                    'ref_id'       => 2147480999,
                    'ref_subid'    => 0,
                    'reason'       => 'selftest',
                ];

                self::note($sig, 'Computer', 2147480999, 0, 'selftest', 'first');
                $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => $key])->current();
                sac_assert($row !== null, 'row inserted by note()');
                sac_assert_eq((int) $row['is_resolved'], 0, 'is_resolved 0 on first insert');
                sac_assert($row['date_firstseen'] !== null, 'date_firstseen stamped');

                // Backdate so the advance is observable regardless of clock granularity.
                $DB->update(self::TABLE, ['date_lastseen' => '2000-01-01 00:00:00'], $key);
                self::note($sig, 'Computer', 2147480999, 0, 'selftest', 'second');

                $cpt = (int) $DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => $key])->current()['c'];
                sac_assert_eq($cpt, 1, 'still exactly one row after re-note');

                $row2 = $DB->request(['FROM' => self::TABLE, 'WHERE' => $key])->current();
                sac_assert($row2['date_lastseen'] > '2000-01-01 00:00:00', 'date_lastseen advanced');
                sac_assert_eq($row2['detail'], 'second', 'detail refreshed on re-note');

                $DB->delete(self::TABLE, $key);
            },

            'db/reconcile-resolve' => static function (): void {
                global $DB;
                $sig = '__sac_selftest_resolve__';
                $key = [
                    'signal_type'  => $sig,
                    'ref_itemtype' => 'Computer',
                    'ref_id'       => 2147480998,
                    'ref_subid'    => 0,
                    'reason'       => 'selftest',
                ];

                try {
                    self::note($sig, 'Computer', 2147480998, 0, 'selftest', 'resolve-me');

                    $rid = (int) $DB->request(['FROM' => self::TABLE, 'WHERE' => $key])->current()['id'];
                    sac_assert($rid > 0, 'note() row present');

                    $openIds = array_column(self::listForDisplay(), 'id');
                    sac_assert(in_array($rid, $openIds, true), 'unresolved row listed by listForDisplay()');

                    self::resolve($rid);

                    $openIds = array_column(self::listForDisplay(), 'id');
                    sac_assert(!in_array($rid, $openIds, true), 'resolved row dropped from listForDisplay()');

                    $allIds = array_column(self::listForDisplay(true), 'id');
                    sac_assert(in_array($rid, $allIds, true), 'resolved row still in listForDisplay(true)');

                    $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => $key])->current();
                    sac_assert_eq((int) $row['is_resolved'], 1, 'is_resolved flipped to 1');
                } finally {
                    $DB->delete(self::TABLE, $key);
                }
            },

            'db/reconcile-subid-distinct' => static function (): void {
                global $DB;
                $osId = 2147480997;
                try {
                    self::note('eol', 'OperatingSystem', $osId, 100, 'no_mapping', 'v100');
                    self::note('eol', 'OperatingSystem', $osId, 200, 'no_mapping', 'v200');

                    $cpt = $DB->request([
                        'COUNT' => 'c', 'FROM' => self::TABLE,
                        'WHERE' => ['ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId],
                    ])->current()['c'];
                    sac_assert_eq((int) $cpt, 2, 'two distinct rows for the same OS, different ref_subid');

                    self::autoResolveMissing('eol', ['OperatingSystem:' . $osId . ':100:no_mapping']);
                    $resolved = [];
                    foreach ($DB->request([
                        'FROM'  => self::TABLE,
                        'WHERE' => ['ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId],
                    ]) as $r) {
                        $resolved[(int) $r['ref_subid']] = (bool) $r['is_resolved'];
                    }
                    sac_assert_eq($resolved[100], false, 'the still-seen subid stays open');
                    sac_assert_eq($resolved[200], true, 'the no-longer-seen subid auto-resolves');
                } finally {
                    $DB->delete(self::TABLE, ['ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId]);
                }
            },
        ];
    }
```

- [ ] **Step 6: Fix the two `CommercialPublisher` call sites**

In `src/License/CommercialPublisher.php`, replace:

```php
                Reconcile::note('license', 'Manufacturer', $refId, 'ambiguous', $name);
```

with:

```php
                Reconcile::note('license', 'Manufacturer', $refId, 0, 'ambiguous', $name);
```

and replace:

```php
                    Reconcile::note('license', 'Manufacturer', $refId, 'no_mapping', $name);
```

with:

```php
                    Reconcile::note('license', 'Manufacturer', $refId, 0, 'no_mapping', $name);
```

- [ ] **Step 7: Update the Reconcile page's help text**

In `templates/reconcile.html.twig`, replace the `<div class="alert alert-info">…</div>` block (lines 18-41) with:

```twig
        <div class="alert alert-info">
            <p class="mb-1">
                {{ __('An EOL / no_mapping row means SAC found an operating system + version pair but has no end-of-life date for it.', 'sac') }}
            </p>
            <ul class="mb-0">
                <li>
                    {{ __('Enter the endoflife.date product slug + release cycle (see', 'sac') }}
                    <a href="https://endoflife.date" target="_blank" rel="noopener">endoflife.date</a>{{ __('), e.g.', 'sac') }}
                    <code>rhel</code> / <code>9</code> {{ __('for RHEL 9.x,', 'sac') }}
                    <code>ubuntu</code> / <code>22.04</code> {{ __('for Ubuntu 22.04,', 'sac') }}
                    <code>windows-server</code> / <code>2019</code>, <code>vmware-esxi</code> / <code>7.0</code> —
                    {{ __('then', 'sac') }} <strong>{{ __('Map &amp; resolve', 'sac') }}</strong>.
                    {{ __('SAC then keeps this exact OS + version pair up to date automatically.', 'sac') }}
                </li>
                <li>
                    {{ __('Or set the date directly on', 'sac') }}
                    <strong>{{ __('Assets → Operating Systems → (OS) → LifeCycle → EndOfLife', 'sac') }}</strong>
                    {{ __('(or on the specific OS Version, for finer control — a version-level date wins)', 'sac') }}
                    — {{ __('that takes priority over the mapping and needs no slug.', 'sac') }}
                </li>
                <li>
                    <strong>{{ __('Resolve', 'sac') }}</strong>
                    {{ __('just dismisses the row without a mapping (no Finding for that OS + version).', 'sac') }}
                </li>
            </ul>
        </div>
```

(No other template or `front/reconcile.php` change is needed — `row.label`
already carries the version name from Step 3, and the "Map & resolve" form
still only posts `map`, `id`, `product`, `cycle`; the version comes from the
DB row per Step 4.)

- [ ] **Step 8: Run and verify**

Run:
```bash
for f in src/Reconcile.php src/License/CommercialPublisher.php; do "/c/php/php.exe" -l "$f" || break; done
"/c/php/php.exe" tools/offline-selfcheck.php
```
Expected: all `-l` lines `No syntax errors detected`; `15 checks, 0 failed`
(unchanged — every new/changed check here is `db/`-prefixed).

- [ ] **Step 9: Commit**

```bash
git add src/Reconcile.php src/License/CommercialPublisher.php templates/reconcile.html.twig
git commit -m "feat: Reconcile is (OS, version)-aware via ref_subid"
```

---

## Task 8: `Migration` — real `\Migration` object + version bump

**Files:**
- Modify (full rewrite): `src/Migration.php`
- Modify: `setup.php:6` (version bump)

**Interfaces:**
- Consumes: nothing new (uses core `\Migration`, `\DBmysql`).
- Produces: `Migration::install()` — same public entry point, now also
  migrates a pre-v1.1.0 schema in place and retires old-format Findings.

- [ ] **Step 1: Bump the version**

In `setup.php`, replace:

```php
define('PLUGIN_SAC_VERSION', '1.0.4');
```

with:

```php
define('PLUGIN_SAC_VERSION', '1.1.0');
```

- [ ] **Step 2: Replace `src/Migration.php`**

```php
<?php

namespace GlpiPlugin\Sac;

final class Migration
{
    /** @var array<string,string> table => CREATE TABLE DDL */
    private static array $tables = [];

    public static function install(): void
    {
        global $DB;
        self::define();
        foreach (self::$tables as $name => $ddl) {
            if (!$DB->tableExists($name)) {
                $DB->doQuery($ddl);
            }
        }

        $migration = new \Migration(PLUGIN_SAC_VERSION);
        self::migrateEolmapVersionKey($migration, $DB);
        self::migrateReconcileSubId($migration, $DB);
        $migration->executeMigration();

        self::supersedeOldEolFindings($DB);
        self::wipeStaleEolReconcile($DB);
        self::purgeAutoEolmap($DB);

        Config::setDefaults();
        Cron::register();
        License\CommercialPublisher::seedFromCsv();
    }

    public static function uninstall(): void
    {
        global $DB;
        foreach ($DB->listTables('glpi\\_plugin\\_sac\\_%') as $row) {
            $DB->doQuery('DROP TABLE IF EXISTS `' . $row['TABLE_NAME'] . '`');
        }
        \Config::deleteConfigurationValues(Config::CONTEXT, array_keys(Config::DEFAULTS));
        \CronTask::unregister('sac');
    }

    private static function define(): void
    {
        $table_options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // v1.1.0: operatingsystemversions_id + the (os,ver) UNIQUE ship
        // directly in a fresh install's DDL. An upgrade from v1.0.x reaches
        // this shape via migrateEolmapVersionKey() below instead.
        self::$tables['glpi_plugin_sac_eolmap'] = "
            CREATE TABLE `glpi_plugin_sac_eolmap` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `operatingsystems_id` int unsigned NOT NULL DEFAULT 0,
              `operatingsystemversions_id` int unsigned NOT NULL DEFAULT 0,
              `eol_product` varchar(100) NOT NULL DEFAULT '',
              `eol_cycle` varchar(50) NOT NULL DEFAULT '',
              `confidence` varchar(20) NOT NULL DEFAULT 'auto',
              `users_id_confirmed` int unsigned DEFAULT NULL,
              `date_confirmed` timestamp NULL DEFAULT NULL,
              `date_creation` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `os_ver` (`operatingsystems_id`,`operatingsystemversions_id`)
            ) $table_options";

        self::$tables['glpi_plugin_sac_eolcache'] = "
            CREATE TABLE `glpi_plugin_sac_eolcache` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `eol_product` varchar(100) NOT NULL DEFAULT '',
              `eol_cycle` varchar(50) NOT NULL DEFAULT '',
              `eol_date` date DEFAULT NULL,
              `eos_date` date DEFAULT NULL,
              `is_lts` tinyint(1) NOT NULL DEFAULT 0,
              `raw_json` text,
              `date_fetched` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `product_cycle` (`eol_product`,`eol_cycle`)
            ) $table_options";

        self::$tables['glpi_plugin_sac_commercialpublishers'] = "
            CREATE TABLE `glpi_plugin_sac_commercialpublishers` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `manufacturers_id` int unsigned NOT NULL DEFAULT 0,
              `is_active` tinyint(1) NOT NULL DEFAULT 1,
              `date_creation` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `manufacturer` (`manufacturers_id`)
            ) $table_options";

        // v1.1.0: ref_subid + the widened UNIQUE ship directly in a fresh
        // install's DDL; an upgrade reaches this shape via
        // migrateReconcileSubId() below.
        self::$tables['glpi_plugin_sac_reconcile'] = "
            CREATE TABLE `glpi_plugin_sac_reconcile` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `signal_type` varchar(20) NOT NULL DEFAULT '',
              `ref_itemtype` varchar(100) NOT NULL DEFAULT '',
              `ref_id` int unsigned NOT NULL DEFAULT 0,
              `ref_subid` int unsigned NOT NULL DEFAULT 0,
              `reason` varchar(30) NOT NULL DEFAULT '',
              `detail` text,
              `date_firstseen` timestamp NULL DEFAULT NULL,
              `date_lastseen` timestamp NULL DEFAULT NULL,
              `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `signal` (`signal_type`,`ref_itemtype`,`ref_id`,`ref_subid`,`reason`)
            ) $table_options";

        self::$tables['glpi_plugin_sac_run'] = "
            CREATE TABLE `glpi_plugin_sac_run` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `module` varchar(20) NOT NULL DEFAULT '',
              `date_start` timestamp NULL DEFAULT NULL,
              `date_end` timestamp NULL DEFAULT NULL,
              `status` varchar(20) NOT NULL DEFAULT 'running',
              `counts_json` text,
              `message` text,
              PRIMARY KEY (`id`),
              KEY `module_start` (`module`,`date_start`)
            ) $table_options";
    }

    /**
     * v1.0.x -> v1.1.0: add the version dimension to `_eolmap` and re-key its
     * UNIQUE. Idempotent — `fieldExists`/`indexExists` checks ARE the version
     * gate (spec §5.2), never a version-number comparison. No-op on a fresh
     * install (the fresh DDL above already has the target shape). Every
     * pre-migration row gets `operatingsystemversions_id = 0` (the column's
     * DDL default), so `(os_id, 0)` is still unique — the key swap cannot
     * collide.
     */
    private static function migrateEolmapVersionKey(\Migration $m, \DBmysql $DB): void
    {
        $t = 'glpi_plugin_sac_eolmap';
        if (!$DB->tableExists($t)) {
            return;
        }

        if (!$DB->fieldExists($t, 'operatingsystemversions_id')) {
            $m->addField($t, 'operatingsystemversions_id', 'integer', [
                'after' => 'operatingsystems_id',
                'value' => 0,
            ]);
        }
        $m->migrationOneTable($t); // flush the column before the key below depends on it

        if ($DB->indexExists($t, 'os')) {
            $m->dropKey($t, 'os');
        }
        if (!$DB->indexExists($t, 'os_ver')) {
            $m->addKey($t, ['operatingsystems_id', 'operatingsystemversions_id'], 'os_ver', 'UNIQUE');
        }
    }

    /**
     * v1.0.x -> v1.1.0: add `ref_subid` to `_reconcile` and widen its UNIQUE
     * so two versions of the same OS no longer collide. Same idempotent
     * pattern as above.
     */
    private static function migrateReconcileSubId(\Migration $m, \DBmysql $DB): void
    {
        $t = 'glpi_plugin_sac_reconcile';
        if (!$DB->tableExists($t)) {
            return;
        }

        if (!$DB->fieldExists($t, 'ref_subid')) {
            $m->addField($t, 'ref_subid', 'integer', [
                'after' => 'ref_id',
                'value' => 0,
            ]);
        }
        $m->migrationOneTable($t);

        if ($DB->indexExists($t, 'signal')) {
            $m->dropKey($t, 'signal');
        }
        if (!$DB->indexExists($t, 'signal')) {
            $m->addKey($t, ['signal_type', 'ref_itemtype', 'ref_id', 'ref_subid', 'reason'], 'signal', 'UNIQUE');
        }
    }

    /**
     * Close every pre-v1.1.0 EOL Finding (`code` without the version segment)
     * with a superseded note. Never deleted — a promoted riskregister Risk
     * holds a FK to the Finding and riskregister does not cascade-delete
     * (spec §5.3, confirmed with risk-register-dashboard-98).
     */
    private static function supersedeOldEolFindings(\DBmysql $DB): void
    {
        if (!\Plugin::isPluginActive('riskregister')
            || !class_exists(\GlpiPlugin\Riskregister\Finding::class)) {
            return;
        }

        $new = '/^SAC-EOL-OS-\d+-\d+$/'; // two ints = the new (os_id, ver_id) grain
        $f   = new \GlpiPlugin\Riskregister\Finding();
        foreach ($f->find([
            'assessment_ref' => 'SAC/eol',
            'code'           => ['LIKE', 'SAC-EOL-OS-%'],
        ]) as $row) {
            $code = (string) $row['code'];
            if (preg_match($new, $code) || $row['status'] === 'Closed') {
                continue;
            }
            $f->update([
                'id'      => (int) $row['id'],
                'status'  => 'Closed',
                'content' => trim((string) $row['content'])
                    . "\n\n[superseded " . date('Y-m-d')
                    . " — SAC EOL scan re-keyed to (operatingsystems_id, operatingsystemversions_id)]",
            ]);
        }
    }

    /**
     * `_reconcile` `eol` rows are keyed to the pre-v1.1.0 grain (OS only) —
     * SAC owns this table outright, so wipe and let the next EolScan
     * regenerate them at the (os,ver) grain (spec §5.4).
     */
    private static function wipeStaleEolReconcile(\DBmysql $DB): void
    {
        if (!$DB->tableExists('glpi_plugin_sac_reconcile')) {
            return;
        }
        $DB->delete('glpi_plugin_sac_reconcile', ['signal_type' => 'eol']);
    }

    /**
     * `_eolmap` `auto` rows were machine guesses at the pre-v1.1.0 grain
     * (whole OS) — purge them; the next EolScan reseeds `auto` rows at the
     * (os,ver) grain. `confirmed` rows are kept as `(os_id, 0)` fallbacks
     * (spec §5.4).
     */
    private static function purgeAutoEolmap(\DBmysql $DB): void
    {
        if (!$DB->tableExists('glpi_plugin_sac_eolmap')) {
            return;
        }
        $DB->delete('glpi_plugin_sac_eolmap', ['confidence' => 'auto']);
    }
}
```

- [ ] **Step 3: Run and verify**

Run: `"/c/php/php.exe" -l setup.php && "/c/php/php.exe" -l src/Migration.php`
Expected: both `No syntax errors detected`.

Run: `"/c/php/php.exe" tools/offline-selfcheck.php`
Expected: `15 checks, 0 failed` (`Migration` has no `selfChecks()` of its own — its
correctness is verified on the VM per §12 of the spec, by installing over a
pre-migration fixture; there is no local GLPI/`\Migration` runtime to test
this offline).

- [ ] **Step 4: Commit**

```bash
git add setup.php src/Migration.php
git commit -m "feat: v1.1.0 — real \Migration object for the (os,ver) schema change"
```

---

## Task 9: `EolCache::ensureFresh` + new run counters

**Files:**
- Modify: `src/Eol/EolCache.php` (add `ensureFresh()` + `isStale()` + a `db/` selfCheck)
- Modify: `src/RunLog.php:19-26` (`$counts` gains `skipped`, `cache_pending`)
- Modify: `templates/overview.html.twig:7-13` (`count_labels` gains the two new keys)

**Interfaces:**
- Produces: `EolCache::ensureFresh(array<int,array{product:string,cycle:string}> $pairs, RunLog $run): void`
  — one GET per distinct product with a missing/stale cache row; upserts
  every cycle the API returns (not just the one asked for); network/shape
  failures bump `errors` and leave the cache untouched.

- [ ] **Step 1: Add the new counters to `RunLog`**

In `src/RunLog.php`, replace:

```php
    private array $counts = [
        'scanned'           => 0,
        'findings_upserted' => 0,
        'findings_cleared'  => 0,
        'reconcile_open'    => 0,
        'errors'            => 0,
    ];
```

with:

```php
    private array $counts = [
        'scanned'           => 0,
        'findings_upserted' => 0,
        'findings_cleared'  => 0,
        'reconcile_open'    => 0,
        'errors'            => 0,
        'skipped'           => 0,       // v1.1.0: a seed-CSV `_skip` pattern (e.g. Kubernetes)
        'cache_pending'     => 0,       // v1.1.0: mapped, but endoflife.date not cached yet — self-healing
    ];
```

- [ ] **Step 2: Add `ensureFresh()` to `EolCache`**

In `src/Eol/EolCache.php`, add these two methods after `upsert()`:

```php
    /**
     * Fetch and cache every distinct product in $pairs whose `_eolcache` row
     * is missing or older than `Config::get('eol_cache_max_age_days')`.
     * Called between EolScan's two passes (spec §6/§10) so a fresh seed-CSV
     * match still has a dated Finding to emit the same run (spec §15 Q2).
     * One GET per product upserts every cycle it returns (not just the one
     * asked for — cheap, and pre-warms sibling versions). Network/shape
     * failures bump `errors` and leave the existing cache untouched.
     *
     * @param array<int,array{product:string,cycle:string}> $pairs
     */
    public static function ensureFresh(array $pairs, \GlpiPlugin\Sac\RunLog $run): void
    {
        $maxAge = (int) \GlpiPlugin\Sac\Config::get('eol_cache_max_age_days');

        $products = [];
        foreach ($pairs as $p) {
            $products[$p['product']] = true;
        }

        foreach (array_keys($products) as $product) {
            if ($product === '' || !self::isStale($product, $maxAge)) {
                continue;
            }

            $body = \GlpiPlugin\Sac\Http::get('https://endoflife.date/api/' . rawurlencode($product) . '.json');
            if ($body === null) {
                $run->bump('errors');
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                $run->bump('errors');
                continue;
            }
            foreach ($data as $entry) {
                if (!is_array($entry) || !isset($entry['cycle'])) {
                    continue;
                }
                $eol     = $entry['eol'] ?? null;
                $support = $entry['support'] ?? null;
                self::upsert($product, (string) $entry['cycle'], [
                    'eol_date' => is_string($eol) ? $eol : null,
                    'eos_date' => is_string($support) ? $support : null,
                    'is_lts'   => (bool) ($entry['lts'] ?? false),
                    'raw_json' => json_encode($entry),
                ]);
            }
        }
    }

    /** Whether $product has no cache row at all, or none fresher than $maxAgeDays. */
    private static function isStale(string $product, int $maxAgeDays): bool
    {
        global $DB;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$maxAgeDays} days"));
        $row    = $DB->request([
            'SELECT' => ['MAX(date_fetched) AS newest'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['eol_product' => $product],
        ])->current();
        $newest = is_array($row) ? ($row['newest'] ?? null) : null;
        return $newest === null || (string) $newest < $cutoff;
    }
```

- [ ] **Step 3: Add a `db/` selfCheck for `ensureFresh()`**

In `EolCache::selfChecks()`, add this entry alongside `db/eolcache-roundtrip`:

```php
            'db/eolcache-ensurefresh' => static function (): void {
                global $DB;
                $product = '__sac_ensurefresh__';
                \GlpiPlugin\Sac\Http::$transport = static function (string $url, int $timeout): ?string {
                    return json_encode([
                        ['cycle' => '1', 'eol' => '2020-01-01', 'support' => '2019-01-01', 'lts' => false],
                        ['cycle' => '2', 'eol' => '2030-01-01', 'support' => '2029-01-01', 'lts' => true],
                    ]);
                };
                try {
                    $run = \GlpiPlugin\Sac\RunLog::begin('eol');
                    self::ensureFresh([['product' => $product, 'cycle' => '2']], $run);
                    $run->finish('ok');

                    $hit = self::get($product, '2');
                    sac_assert($hit !== null, 'ensureFresh cached the requested cycle');
                    sac_assert_eq($hit['eol_date'], '2030-01-01', 'requested cycle date cached');

                    $hit1 = self::get($product, '1');
                    sac_assert($hit1 !== null, 'ensureFresh also cached a sibling cycle from the same API response');
                } finally {
                    \GlpiPlugin\Sac\Http::$transport = null;
                    $DB->delete(self::TABLE, ['eol_product' => $product]);
                }
            },
```

- [ ] **Step 4: Add human labels for the two new counters**

In `templates/overview.html.twig`, replace:

```twig
{% set count_labels = {
    'scanned':          __('Scanned', 'sac'),
    'findings_upserted':__('Findings written', 'sac'),
    'findings_cleared': __('Findings cleared', 'sac'),
    'reconcile_open':   __('Reconcile added', 'sac'),
    'errors':           __('Errors', 'sac')
} %}
```

with:

```twig
{% set count_labels = {
    'scanned':          __('Scanned', 'sac'),
    'findings_upserted':__('Findings written', 'sac'),
    'findings_cleared': __('Findings cleared', 'sac'),
    'reconcile_open':   __('Reconcile added', 'sac'),
    'errors':           __('Errors', 'sac'),
    'skipped':          __('Skipped (not an OS)', 'sac'),
    'cache_pending':    __('Awaiting endoflife.date', 'sac')
} %}
```

- [ ] **Step 5: Run and verify**

Run: `"/c/php/php.exe" -l src/RunLog.php && "/c/php/php.exe" -l src/Eol/EolCache.php`
Expected: both `No syntax errors detected`.

Run: `"/c/php/php.exe" tools/offline-selfcheck.php`
Expected: `15 checks, 0 failed` (unchanged — the new check is `db/`-prefixed).

- [ ] **Step 6: Commit**

```bash
git add src/RunLog.php src/Eol/EolCache.php templates/overview.html.twig
git commit -m "feat: EolCache::ensureFresh() + skipped/cache_pending run counters"
```

---

## Task 10: `EolScan` — the two-pass rewrite

**Files:**
- Modify (full rewrite): `src/Eol/EolScan.php`

**Interfaces:**
- Consumes: `EolMatch::resolve()` (Task 3), `AssetResolver::computersForOsVersion()`
  (Task 4), `EolMap::forOsVersion/putAuto` (Task 5), `LifeCycleReader::endOfLifeFor(int,int)`
  (Task 6), `Reconcile::note(...,$subId,...)` / `autoResolveMissing()` (Task 7),
  `EolCache::ensureFresh()` (Task 9), `Code::eolOs(int,int)` (Task 2).
- Produces: `EolScan::run(RunLog $run): void` — same public entry point;
  `runHardware()` and `refreshCache()` are unchanged (hardware EOL is out of
  scope, spec §14).

- [ ] **Step 1: Replace the whole file**

```php
<?php

namespace GlpiPlugin\Sac\Eol;

use GlpiPlugin\Sac\AssetResolver;
use GlpiPlugin\Sac\Code;
use GlpiPlugin\Sac\Config;
use GlpiPlugin\Sac\EolMatch;
use GlpiPlugin\Sac\FindingWriter;
use GlpiPlugin\Sac\Http;
use GlpiPlugin\Sac\Reconcile;
use GlpiPlugin\Sac\RunLog;
use GlpiPlugin\Sac\Severity;

/**
 * Module 1 orchestration — EOL/EOS scan, v1.1.0 grain: one Finding per
 * (operatingsystems_id, operatingsystemversions_id) pair (spec
 * 2026-09-10-eol-multidim-design.md §6), because GLPI 11 splits an OS across
 * dropdown dimensions and the OS name/id alone is not a valid EOL key.
 *
 * `run()` is two-pass:
 *   Pass 1 ({@see resolveMapping()}) walks every pair in live inventory and
 *   resolves each to either a `fields` "LifeCycle" date or a
 *   `{product,cycle}` to look up — via an existing `_eolmap` row, or a fresh
 *   seed-CSV match ({@see EolMatch}), which auto-writes `_eolmap` on the
 *   spot. A cache-fill step ({@see EolCache::ensureFresh()}) then fetches
 *   anything newly mapped, so a brand-new match still has a dated Finding to
 *   emit the SAME run (spec §15 Q2).
 *   Pass 2 grades each resolved pair and emits/clears its Finding.
 *
 * Hardware-warranty EOL is a separate, unchanged pass gated on
 * `eol_hardware_enabled` (spec §14 — out of scope for v1.1.0).
 *
 * Every mutation of riskregister state goes through {@see FindingWriter};
 * every reconcile signal through {@see Reconcile}. This class never writes
 * GLPI core tables or the `fields` tables.
 */
final class EolScan
{
    /** Product slug used by the VM selfChecks' synthetic endoflife.date rows. */
    private const SC_PRODUCT = '__sac_sc_eol__';

    public static function run(RunLog $run): void
    {
        $entity    = (int) Config::get('finding_entity');
        $recursive = (int) Config::get('finding_recursive');

        // Hoist per-run-stable lookups out of the pair loop (I7): the severity
        // thresholds and the Obsolescence category id do not change within a run.
        $horizonDays = (int) Config::get('eol_horizon_days');
        $highDays    = (int) Config::get('eol_severity_high_days');
        $mediumDays  = (int) Config::get('eol_severity_medium_days');
        $obsolescenceCatId = FindingWriter::categoryId('Obsolescence', $entity);

        /** @var array<int,string> composite reconcile keys seen this run */
        $seen = [];

        $pairs     = self::pairsInInventory();
        $needCache = [];
        /** @var array<string,array{kind:string,date?:string,source?:string,product?:string,cycle?:string}> */
        $resolved  = [];

        // Pass 1 — resolve every pair to a lifecycle date or a {product,cycle}.
        foreach ($pairs as $pair) {
            $key     = $pair['os_id'] . ':' . $pair['ver_id'];
            $mapping = self::resolveMapping($pair['os_id'], $pair['ver_id'], $pair['os_name'], $pair['ver_name'], $seen, $run);
            if ($mapping === null) {
                continue; // bad_date / no_mapping / skip already handled inside resolveMapping()
            }
            $resolved[$key] = $mapping;
            if ($mapping['kind'] === 'mapped') {
                $needCache[] = ['product' => $mapping['product'], 'cycle' => $mapping['cycle']];
            }
        }

        // Cache fill BETWEEN the passes — a seed-CSV match made this run still
        // gets a dated Finding this run (spec §15 Q2).
        if ($needCache !== []) {
            EolCache::ensureFresh($needCache, $run);
        }

        // Pass 2 — grade + emit using whatever the cache fill just made available.
        foreach ($pairs as $pair) {
            $osId    = $pair['os_id'];
            $verId   = $pair['ver_id'];
            $osName  = $pair['os_name'];
            $verName = $pair['ver_name'];
            $key     = "$osId:$verId";

            if (!isset($resolved[$key])) {
                continue;
            }
            $mapping = $resolved[$key];

            if ($mapping['kind'] === 'lifecycle') {
                $date   = $mapping['date'];
                $source = $mapping['source'];
            } else {
                $cached = EolCache::get($mapping['product'], $mapping['cycle']);
                if ($cached === null || $cached['eol_date'] === null) {
                    // Mapped but not dated yet (endoflife.date unreachable, or
                    // the cycle genuinely absent upstream) — self-healing, not
                    // a problem to surface. Next run / EolCacheRefresh retries.
                    $run->bump('cache_pending');
                    $run->bump('scanned');
                    continue;
                }
                $date   = $cached['eol_date'];
                $source = 'endoflife.date';
            }

            $code = Code::eolOs($osId, $verId);
            $days = (int) floor((strtotime($date) - strtotime('today')) / 86400);
            $sev  = Severity::forEolDays($days, $horizonDays, $highDays, $mediumDays);

            $assets    = AssetResolver::computersForOsVersion($osId, $verId);
            $hostCount = count($assets['resolved']);

            // The pair still has an inventory row, but every Computer carrying
            // it is trashed / a template. Treat as "no longer in live
            // inventory" (spec §4.5) — clear any prior Finding and stop
            // re-asserting.
            if ($hostCount === 0) {
                FindingWriter::appendClearedNote($code, $entity, 'pair not in live inventory', $run);
                $run->bump('scanned');
                continue;
            }

            if ($sev === null) {
                FindingWriter::appendClearedNote($code, $entity, 'EOL beyond horizon', $run);
                $run->bump('scanned');
                continue;
            }

            $label = trim("$osName $verName");
            $input = [
                'entities_id'  => $entity,
                'is_recursive' => $recursive,
                'code'         => $code,
                'name'         => substr("EOL: $label", 0, 255),
                'scope_label'  => substr("$label ×$hostCount", 0, 255),
                'severity'     => $sev,
                'content'      => "OS: $label\nEnd of life: $date (source: $source)\nAffected hosts:\n"
                    . implode("\n", array_map(fn ($n) => "- $n", $assets['resolved'])),
                'assessment_ref' => 'SAC/eol',
                'plugin_riskregister_riskcategories_id' => $obsolescenceCatId,
                'status'       => 'Open',
            ];

            FindingWriter::upsert($input, array_keys($assets['resolved']), '', $run);
            $run->bump('scanned');
        }

        Reconcile::autoResolveMissing('eol', $seen);
        $run->bump('reconcile_open', count($seen));

        if (Config::get('eol_hardware_enabled')) {
            // A hardware-pass failure (schema drift, strict-mode date) must not
            // fail the whole EOL run — the OS pass above already stands.
            try {
                self::runHardware($run, $entity, $recursive, (int) Config::get('eol_hardware_horizon_days'), $obsolescenceCatId);
            } catch (\Throwable $e) {
                $run->bump('errors');
                \Toolbox::logInFile('sac', 'EolScan hardware pass: ' . $e->getMessage() . "\n");
            }
        }
    }

    /**
     * Every distinct (operatingsystems_id, operatingsystemversions_id) pair
     * currently on a live Computer, with both dropdown names joined in.
     * LEFT JOIN on the version dropdown so ver_id = 0 (version dimension
     * empty/unset) rows survive with ver_name = ''.
     *
     * @return array<int,array{os_id:int,ver_id:int,os_name:string,ver_name:string}>
     */
    private static function pairsInInventory(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'     => [
                'glpi_items_operatingsystems.operatingsystems_id AS os_id',
                'glpi_items_operatingsystems.operatingsystemversions_id AS ver_id',
                'glpi_operatingsystems.name AS os_name',
                'glpi_operatingsystemversions.name AS ver_name',
            ],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_items_operatingsystems',
            'INNER JOIN' => [
                'glpi_operatingsystems' => [
                    'ON' => [
                        'glpi_items_operatingsystems' => 'operatingsystems_id',
                        'glpi_operatingsystems'       => 'id',
                    ],
                ],
            ],
            'LEFT JOIN'  => [
                'glpi_operatingsystemversions' => [
                    'ON' => [
                        'glpi_items_operatingsystems'  => 'operatingsystemversions_id',
                        'glpi_operatingsystemversions' => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                'glpi_items_operatingsystems.itemtype' => 'Computer',
            ],
        ]) as $row) {
            $out[] = [
                'os_id'    => (int) $row['os_id'],
                'ver_id'   => (int) $row['ver_id'],
                'os_name'  => (string) ($row['os_name'] ?? ''),
                'ver_name' => (string) ($row['ver_name'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Resolve one (os,ver) pair to either a lifecycle date or a
     * {product,cycle} to look up in the cache. Writes Reconcile signals and
     * bumps run counters for every terminal outcome (bad_date / no_mapping /
     * skip) so `run()` only has to act on a non-null return — every pair
     * bumps `scanned` exactly once, either here (terminal cases) or in
     * Pass 2 (every other case).
     *
     * @param array<int,string> $seen composite reconcile keys, updated by reference
     * @return array{kind:'lifecycle',date:string,source:string}
     *       | array{kind:'mapped',product:string,cycle:string}
     *       | null
     */
    private static function resolveMapping(
        int $osId,
        int $verId,
        string $osName,
        string $verName,
        array &$seen,
        RunLog $run
    ): ?array {
        // (a) fields "LifeCycle" — version-level wins over OS-level
        // (LifeCycleReader handles that precedence internally).
        $life = LifeCycleReader::endOfLifeFor($osId, $verId);
        if (is_string($life)) {
            return ['kind' => 'lifecycle', 'date' => $life, 'source' => 'lifecycle'];
        }
        if ($life === false) {
            Reconcile::note('eol', 'OperatingSystem', $osId, $verId, 'bad_date', trim("$osName $verName"));
            $seen[] = "OperatingSystem:$osId:$verId:bad_date";
            $run->bump('scanned');
            return null;
        }
        // $life === null -> no LifeCycle row at either level, continue.

        // (b) existing _eolmap (exact pair, or the (os,0) "version unknown" fallback).
        $map = EolMap::forOsVersion($osId, $verId);
        if ($map !== null) {
            return ['kind' => 'mapped', 'product' => $map['eol_product'], 'cycle' => $map['eol_cycle']];
        }

        // (c) seed-CSV match (spec §15 Q2 — auto-write _eolmap, resolve this run).
        $match = EolMatch::resolve($osName, $verName);
        if ($match !== null && isset($match['skip'])) {
            $run->bump('skipped');
            $run->bump('scanned');
            return null;
        }
        if ($match !== null) {
            EolMap::putAuto($osId, $verId, $match['product'], $match['cycle']);
            return ['kind' => 'mapped', 'product' => $match['product'], 'cycle' => $match['cycle']];
        }

        // (d) nothing resolved.
        Reconcile::note('eol', 'OperatingSystem', $osId, $verId, 'no_mapping', trim("$osName $verName"));
        $seen[] = "OperatingSystem:$osId:$verId:no_mapping";
        $run->bump('scanned');
        return null;
    }

    /**
     * Hardware-warranty EOL pass. One Finding per Computer whose
     * `warranty_date + warranty_duration months` falls inside the hardware
     * horizon. UNCHANGED from v1.0.1 (spec §14 — out of scope for v1.1.0).
     *
     * Warranty data lives in `glpi_infocoms` (Infocom / "Financial and
     * administrative information"), keyed by `itemtype` + `items_id` — NOT on
     * `glpi_computers` in GLPI 11. Guarded so an unexpected schema degrades to
     * a no-op instead of a fatal.
     *
     * @param int $hwHorizonDays  hoisted `eol_hardware_horizon_days` (I7)
     * @param int $catId          hoisted Obsolescence category id (I7)
     */
    private static function runHardware(RunLog $run, int $entity, int $recursive, int $hwHorizonDays, int $catId): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_infocoms') || !$DB->fieldExists('glpi_infocoms', 'warranty_date')) {
            \Toolbox::logInFile('sac', "EolScan: hardware pass skipped — glpi_infocoms.warranty_date not found\n");
            return;
        }

        foreach ($DB->request([
            'SELECT'     => [
                'glpi_computers.id AS id',
                'glpi_computers.name AS name',
                'glpi_infocoms.warranty_date AS warranty_date',
                'glpi_infocoms.warranty_duration AS warranty_duration',
            ],
            'FROM'       => 'glpi_infocoms',
            'INNER JOIN' => [
                'glpi_computers' => [
                    'ON' => [
                        'glpi_infocoms'  => 'items_id',
                        'glpi_computers' => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                'glpi_infocoms.itemtype'          => 'Computer',
                'glpi_computers.is_deleted'       => 0,
                'glpi_computers.is_template'      => 0,
                'NOT'                             => ['glpi_infocoms.warranty_date' => null],
                // -1 = permanent (never EOL), 0 = no warranty — skip both.
                'glpi_infocoms.warranty_duration' => ['>', 0],
            ],
        ]) as $c) {
            $cid          = (int) $c['id'];
            $cname        = (string) ($c['name'] ?? '');
            $warrantyDate = (string) $c['warranty_date'];

            // Zero / unparseable warranty date — skip (strtotime() would fall to
            // the 1970 epoch and manufacture a spurious Critical).
            $endTs = strtotime($warrantyDate . ' +' . (int) $c['warranty_duration'] . ' months');
            if ($endTs === false || str_starts_with($warrantyDate, '0000')) {
                continue;
            }

            $endDate = date('Y-m-d', $endTs);
            $days    = (int) floor((strtotime($endDate) - strtotime('today')) / 86400);
            $sev     = Severity::forEolDays($days, $hwHorizonDays, 90, 180);

            if ($sev === null) {
                FindingWriter::appendClearedNote(Code::eolHw($cid), $entity, 'warranty end beyond horizon', $run);
                $run->bump('scanned');
                continue;
            }

            $input = [
                'entities_id'  => $entity,
                'is_recursive' => $recursive,
                'code'         => Code::eolHw($cid),
                'name'         => substr("Hardware EOL: $cname", 0, 255),
                'scope_label'  => substr($cname, 0, 255),
                'severity'     => $sev,
                'content'      => "Hardware EOL: $cname\nWarranty end: $endDate (warranty_date $warrantyDate + "
                    . (int) $c['warranty_duration'] . " months)",
                'assessment_ref' => 'SAC/eol',
                'plugin_riskregister_riskcategories_id' => $catId,
                'status'       => 'Open',
            ];

            FindingWriter::upsert($input, [$cid], '', $run);
            $run->bump('scanned');
        }
    }

    /**
     * Re-fetch endoflife.date rows whose local mirror is missing or stale.
     * UNCHANGED from v1.0.x — kept for the weekly `EolCacheRefresh` cron, which
     * sweeps everything already in `_eolmap` regardless of this run's needs.
     * Network / shape failures bump `errors` and are skipped — never fatal.
     */
    public static function refreshCache(RunLog $run): void
    {
        foreach (EolCache::staleProducts((int) Config::get('eol_cache_max_age_days')) as $p) {
            $body = Http::get('https://endoflife.date/api/' . rawurlencode($p['product']) . '.json');
            if ($body === null) {
                $run->bump('errors');
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                $run->bump('errors');
                continue;
            }
            foreach ($data as $entry) {
                if (!is_array($entry) || (string) ($entry['cycle'] ?? '') !== $p['cycle']) {
                    continue;
                }
                $eol     = $entry['eol'] ?? null;
                $support = $entry['support'] ?? null;
                EolCache::upsert($p['product'], $p['cycle'], [
                    'eol_date' => is_string($eol) ? $eol : null,
                    'eos_date' => is_string($support) ? $support : null,
                    'is_lts'   => (bool) ($entry['lts'] ?? false),
                    'raw_json' => json_encode($entry),
                ]);
                break;
            }
        }
    }

    // ---------------------------------------------------------------------
    // VM-only selfChecks (db/ prefix) — spec §9.2 / §11.2 EOL-1..5 plus the
    // new v1.1.0 pair-grain checks.
    // ---------------------------------------------------------------------

    /**
     * Seed a throwaway OperatingSystem (+ optional OperatingSystemVersion, if
     * $verName is non-empty) and a Computer linking them, and (optionally) an
     * endoflife.date mapping+cache row with the given EOL date at that exact
     * (os,ver) pair.
     *
     * Core rows are created with `->add()` and the generated ids
     * captured/returned (no hardcoded ~2.1e9 ids — those permanently bump
     * InnoDB AUTO_INCREMENT).
     *
     * @return array{osId:int,verId:int,cid:int}
     */
    private static function scSeed(?string $eolDate, string $osName = '__sac_sc_os__', string $verName = ''): array
    {
        global $DB;

        $osId  = (int) (new \OperatingSystem())->add(['name' => $osName]);
        $verId = $verName === ''
            ? 0
            : (int) (new \OperatingSystemVersion())->add(['name' => $verName, 'operatingsystems_id' => $osId]);
        $cid   = (int) (new \Computer())->add([
            'name'        => '__sac_sc_pc__',
            'entities_id' => 0,
        ]);
        $DB->insert('glpi_items_operatingsystems', [
            'items_id'                   => $cid,
            'itemtype'                   => 'Computer',
            'operatingsystems_id'        => $osId,
            'operatingsystemversions_id' => $verId,
        ]);
        if ($eolDate !== null) {
            EolMap::putConfirmed($osId, $verId, self::SC_PRODUCT, '1', 1);
            EolCache::upsert(self::SC_PRODUCT, '1', ['eol_date' => $eolDate, 'raw_json' => '{}']);
        }

        return ['osId' => $osId, 'verId' => $verId, 'cid' => $cid];
    }

    /**
     * Seed a real `fields` LifeCycle row at one level ('os' keyed by $osId, or
     * 'version' keyed by $targetId). Requires LifeCycleReader::available()
     * (checked by the caller).
     */
    private static function scSeedLifecycle(int $osId, string $rawDate, string $level = 'os', int $targetId = 0): void
    {
        global $DB;
        $id    = $level === 'version' ? $targetId : $osId;
        $table = LifeCycleReader::tableName($level);
        $col   = LifeCycleReader::dateColumnName($level);
        $row   = ['items_id' => $id, $col => $rawDate];
        if ($DB->fieldExists($table, 'itemtype')) {
            $row['itemtype'] = $level === 'version' ? 'OperatingSystemVersion' : 'OperatingSystem';
        }
        if ($DB->fieldExists($table, 'entities_id')) {
            $row['entities_id'] = 0;
        }
        $DB->insert($table, $row);
    }

    /** Highest `glpi_plugin_sac_run.id` right now — the floor for selfCheck cleanup. */
    private static function scRunFloor(): int
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['MAX(id) AS m'], 'FROM' => 'glpi_plugin_sac_run'])->current();
        return (int) (is_array($row) ? ($row['m'] ?? 0) : 0);
    }

    /**
     * Tear every seeded row for a selfCheck back down. Safe to call twice.
     * Also deletes any `glpi_plugin_sac_run` rows this check's RunLog::begin('eol')
     * calls created (id > $sinceRunId) so selfCheck runs never pollute audit history.
     *
     * @param array<int,int> $verIds OperatingSystemVersion ids to remove, if any
     */
    private static function scTeardown(array $osIds, array $cids, array $codes, int $sinceRunId = 0, array $verIds = []): void
    {
        global $DB;

        $DB->delete('glpi_plugin_sac_run', ['module' => 'eol', 'id' => ['>', $sinceRunId]]);

        if (FindingWriter::isAvailable()) {
            $f  = new \GlpiPlugin\Riskregister\Finding();
            $fi = new \GlpiPlugin\Riskregister\Finding_Item();
            foreach ($codes as $code) {
                foreach (array_keys($f->find(['code' => $code])) as $fid) {
                    foreach (array_keys($fi->find(['plugin_riskregister_findings_id' => (int) $fid])) as $liid) {
                        $fi->delete(['id' => (int) $liid], true);
                    }
                    $f->delete(['id' => (int) $fid], true);
                }
            }
        }

        $osLcTable  = \Plugin::isPluginActive('fields') ? LifeCycleReader::tableName('os') : '';
        $verLcTable = \Plugin::isPluginActive('fields') ? LifeCycleReader::tableName('version') : '';

        foreach ($osIds as $osId) {
            $osId = (int) $osId;
            if ($osId <= 0) {
                continue;
            }
            $DB->delete('glpi_plugin_sac_eolmap', ['operatingsystems_id' => $osId]);
            $DB->delete('glpi_items_operatingsystems', ['operatingsystems_id' => $osId]);
            $DB->delete('glpi_operatingsystems', ['id' => $osId]);
            $DB->delete('glpi_plugin_sac_reconcile', ['ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId]);
            if ($osLcTable !== '' && $DB->fieldExists($osLcTable, 'items_id')) {
                $DB->delete($osLcTable, ['items_id' => $osId]);
            }
        }
        foreach ($verIds as $verId) {
            $verId = (int) $verId;
            if ($verId <= 0) {
                continue;
            }
            $DB->delete('glpi_operatingsystemversions', ['id' => $verId]);
            if ($verLcTable !== '' && $DB->fieldExists($verLcTable, 'items_id')) {
                $DB->delete($verLcTable, ['items_id' => $verId]);
            }
        }
        $DB->delete('glpi_plugin_sac_eolcache', ['eol_product' => self::SC_PRODUCT]);
        foreach ($cids as $cid) {
            if ((int) $cid > 0) {
                $DB->delete('glpi_computers', ['id' => (int) $cid]);
            }
        }
    }

    /** @return array<string,callable():void> */
    public static function selfChecks(): array
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        return [
            // EOL-1 — LifeCycle path ONLY, ver_id=0. Seeds a real `fields`
            // OS-level LifeCycle row (no _eolmap/_eolcache) and asserts the
            // Finding names the lifecycle source.
            'db/eol-1-lifecycle-source' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                LifeCycleReader::resetProbeCache();
                if (!LifeCycleReader::available() || LifeCycleReader::dateColumnName('os') === '') {
                    echo "SKIP  db/eol-1-lifecycle-source — fields LifeCycle (os level) table/column not present on this VM\n";
                    return;
                }
                $seed = self::scSeed(null);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
                $startId = self::scRunFloor();
                try {
                    self::scSeedLifecycle($osId, $yesterday);
                    self::run(RunLog::begin('eol'));

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'EOL-1: exactly one Finding via the LifeCycle date');
                    $row = $rows[array_key_first($rows)];
                    sac_assert_eq($row['severity'], 'Critical', 'EOL-1: severity Critical');
                    sac_assert(
                        str_contains((string) $row['content'], 'source: lifecycle'),
                        'EOL-1: content names the lifecycle source'
                    );

                    $fi    = new \GlpiPlugin\Riskregister\Finding_Item();
                    $links = $fi->find([
                        'plugin_riskregister_findings_id' => (int) array_key_first($rows),
                        'itemtype'                        => 'Computer',
                        'items_id'                        => $cid,
                    ]);
                    sac_assert_eq(count($links), 1, 'EOL-1: one Finding_Item to the seeded Computer');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // LifeCycle (os-level) beats a differing endoflife.date mirror.
            'db/eol-lifecycle-wins' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                LifeCycleReader::resetProbeCache();
                if (!LifeCycleReader::available() || LifeCycleReader::dateColumnName('os') === '') {
                    echo "SKIP  db/eol-lifecycle-wins — fields LifeCycle (os level) table/column not present on this VM\n";
                    return;
                }
                // endoflife.date mirror says +200 days (would be Low); LifeCycle
                // says yesterday (Critical).
                $seed = self::scSeed(date('Y-m-d', strtotime('+200 days')));
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
                $startId = self::scRunFloor();
                try {
                    self::scSeedLifecycle($osId, $yesterday);
                    self::run(RunLog::begin('eol'));

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'EOL-lifecycle-wins: one Finding');
                    $row = $rows[array_key_first($rows)];
                    sac_assert(str_contains((string) $row['content'], 'source: lifecycle'), 'lifecycle source wins');
                    sac_assert(str_contains((string) $row['content'], $yesterday), 'lifecycle date used, not the mirror date');
                    sac_assert_eq($row['severity'], 'Critical', 'graded on the LifeCycle (past) date');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // NEW v1.1.0 — a version-level LifeCycle date beats a differing
            // OS-level one for the same pair.
            'db/eol-version-lifecycle-wins' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                LifeCycleReader::resetProbeCache();
                $hasOs      = LifeCycleReader::available() && LifeCycleReader::dateColumnName('os') !== '';
                $hasVersion = LifeCycleReader::dateColumnName('version') !== '';
                if (!$hasOs || !$hasVersion) {
                    echo "SKIP  db/eol-version-lifecycle-wins — fields LifeCycle not present at both levels on this VM\n";
                    return;
                }
                $seed    = self::scSeed(null, '__sac_sc_os__', '__sac_sc_ver__');
                $osId    = $seed['osId']; $verId = $seed['verId']; $cid = $seed['cid'];
                $code    = Code::eolOs($osId, $verId);
                $startId = self::scRunFloor();
                try {
                    // OS-level says +200d (would be Low); version-level says yesterday (Critical).
                    self::scSeedLifecycle($osId, date('Y-m-d', strtotime('+200 days')), 'os');
                    self::scSeedLifecycle($osId, $yesterday, 'version', $verId);
                    self::run(RunLog::begin('eol'));

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'eol-version-lifecycle-wins: one Finding');
                    $row = $rows[array_key_first($rows)];
                    sac_assert(str_contains((string) $row['content'], 'source: lifecycle'), 'lifecycle source wins');
                    sac_assert(str_contains((string) $row['content'], $yesterday), 'version-level date used, not the OS-level date');
                    sac_assert_eq($row['severity'], 'Critical', 'graded on the version-level (past) date');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId, [$verId]);
                }
            },

            // A LifeCycle row with 0000-00-00 yields no Finding and one
            // bad_date reconcile row.
            'db/eol-bad-date' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                LifeCycleReader::resetProbeCache();
                if (!LifeCycleReader::available() || LifeCycleReader::dateColumnName('os') === '') {
                    echo "SKIP  db/eol-bad-date — fields LifeCycle (os level) table/column not present on this VM\n";
                    return;
                }
                global $DB;
                $seed = self::scSeed(null);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
                $startId = self::scRunFloor();
                try {
                    self::scSeedLifecycle($osId, '0000-00-00');
                    self::run(RunLog::begin('eol'));

                    $f = new \GlpiPlugin\Riskregister\Finding();
                    sac_assert_eq(count($f->find(['code' => $code])), 0, 'EOL-bad-date: no Finding for an unparseable LifeCycle date');

                    $rc = $DB->request([
                        'FROM'  => 'glpi_plugin_sac_reconcile',
                        'WHERE' => [
                            'signal_type'  => 'eol',
                            'ref_itemtype' => 'OperatingSystem',
                            'ref_id'       => $osId,
                            'ref_subid'    => 0,
                            'reason'       => 'bad_date',
                        ],
                    ])->current();
                    sac_assert($rc !== null, 'EOL-bad-date: a bad_date reconcile row was written');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // Re-run after the endoflife.date date moves in-horizon -> same
            // row, re-graded in place.
            'db/eol-2-update-in-place' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                $seed = self::scSeed($yesterday);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
                $startId = self::scRunFloor();
                try {
                    self::run(RunLog::begin('eol'));

                    EolCache::upsert(self::SC_PRODUCT, '1', [
                        'eol_date' => date('Y-m-d', strtotime('+120 days')),
                        'raw_json' => '{}',
                    ]);
                    self::run(RunLog::begin('eol'));

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'EOL-2: still exactly one Finding row');
                    sac_assert_eq($rows[array_key_first($rows)]['severity'], 'Medium', 'EOL-2: severity re-graded to Medium');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // No LifeCycle, no _eolmap, name doesn't match the seed CSV ->
            // no Finding, one no_mapping reconcile.
            'db/eol-3-no-mapping-reconcile' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $seed = self::scSeed(null);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
                $startId = self::scRunFloor();
                try {
                    self::run(RunLog::begin('eol'));

                    $f = new \GlpiPlugin\Riskregister\Finding();
                    sac_assert_eq(count($f->find(['code' => $code])), 0, 'EOL-3: no Finding without a resolvable date');

                    $rc = $DB->request([
                        'FROM'  => 'glpi_plugin_sac_reconcile',
                        'WHERE' => [
                            'signal_type'  => 'eol',
                            'ref_itemtype' => 'OperatingSystem',
                            'ref_id'       => $osId,
                            'ref_subid'    => 0,
                            'reason'       => 'no_mapping',
                        ],
                    ])->current();
                    sac_assert($rc !== null, 'EOL-3: a no_mapping reconcile row was written');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // A Finding that drops beyond the horizon gains a cleared note.
            'db/eol-4-signal-cleared' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $seed = self::scSeed($yesterday);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
                $startId = self::scRunFloor();
                try {
                    self::run(RunLog::begin('eol'));

                    $f       = new \GlpiPlugin\Riskregister\Finding();
                    $rows0   = $f->find(['code' => $code]);
                    $before  = $rows0[array_key_first($rows0)];
                    sac_assert_eq($before['severity'], 'Critical', 'EOL-4: starts Critical');
                    $status0 = $before['status'];

                    EolCache::upsert(self::SC_PRODUCT, '1', [
                        'eol_date' => date('Y-m-d', strtotime('+400 days')),
                        'raw_json' => '{}',
                    ]);
                    $run = RunLog::begin('eol');
                    self::run($run);
                    $run->finish('ok');

                    $rows1 = $f->find(['code' => $code]);
                    $after = $rows1[array_key_first($rows1)];
                    sac_assert(str_contains((string) $after['content'], 'Signal cleared'), 'EOL-4: content gains a cleared note');
                    sac_assert_eq($after['status'], $status0, 'EOL-4: status unchanged by the clear');

                    $runRow  = $DB->request(['FROM' => 'glpi_plugin_sac_run', 'WHERE' => ['id' => $run->id()]])->current();
                    $counts  = json_decode((string) $runRow['counts_json'], true);
                    sac_assert((int) ($counts['findings_cleared'] ?? 0) >= 1, 'EOL-4: findings_cleared counted');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // endoflife.date-only path (no LifeCycle row) is reported as the
            // source in `content`.
            'db/eol-5-endoflife-source' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                $seed = self::scSeed($yesterday);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
                $startId = self::scRunFloor();
                try {
                    self::run(RunLog::begin('eol'));

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'EOL-5: Finding created via the endoflife.date map');
                    $row = $rows[array_key_first($rows)];
                    sac_assert(str_contains((string) $row['content'], 'source: endoflife.date'), 'EOL-5: content names the endoflife.date source');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // NEW v1.1.0 — a fresh seed-CSV match auto-writes _eolmap AND
            // emits the Finding in the SAME run (spec §15 Q2).
            'db/eol-seed-automap' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $seed    = self::scSeed(null, 'Ubuntu', '22.04.3 LTS'); // no _eolmap — must come from the seed CSV
                $osId    = $seed['osId']; $verId = $seed['verId']; $cid = $seed['cid'];
                $code    = Code::eolOs($osId, $verId);
                $startId = self::scRunFloor();
                try {
                    // Pre-warm the cache so this check needs no network stub —
                    // it is testing the auto-map + same-run-emit behaviour, not
                    // EolCache::ensureFresh() (that has its own check, Task 9).
                    EolCache::upsert('ubuntu', '22.04', [
                        'eol_date' => date('Y-m-d', strtotime('-1 day')),
                        'raw_json' => '{}',
                    ]);

                    self::run(RunLog::begin('eol'));

                    $map = EolMap::forOsVersion($osId, $verId);
                    sac_assert($map !== null, 'a seed-CSV match auto-writes _eolmap');
                    sac_assert_eq($map['eol_product'], 'ubuntu', 'auto-mapped to the ubuntu product');
                    sac_assert_eq($map['eol_cycle'], '22.04', 'auto-mapped to the 22.04 cycle');
                    sac_assert_eq($map['confidence'], 'auto', 'auto-mapped rows are confidence=auto');

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'the Finding is emitted the SAME run as the auto-mapping');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId, [$verId]);
                    $DB->delete('glpi_plugin_sac_eolcache', ['eol_product' => 'ubuntu', 'eol_cycle' => '22.04']);
                }
            },

            // NEW v1.1.0 — a _skip seed pattern never produces a Finding or a
            // Reconcile row, and bumps the `skipped` counter.
            'db/eol-skip-non-os' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $seed    = self::scSeed(null, 'Kubernetes', '1.29');
                $osId    = $seed['osId']; $verId = $seed['verId']; $cid = $seed['cid'];
                $code    = Code::eolOs($osId, $verId);
                $startId = self::scRunFloor();
                try {
                    $run = RunLog::begin('eol');
                    self::run($run);
                    $run->finish('ok');

                    $f = new \GlpiPlugin\Riskregister\Finding();
                    sac_assert_eq(count($f->find(['code' => $code])), 0, 'a _skip pattern never produces a Finding');

                    $rc = $DB->request([
                        'FROM'  => 'glpi_plugin_sac_reconcile',
                        'WHERE' => ['ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId, 'ref_subid' => $verId],
                    ])->current();
                    sac_assert($rc === null, 'a _skip pattern never produces a Reconcile row either');

                    $runRow = $DB->request(['FROM' => 'glpi_plugin_sac_run', 'WHERE' => ['id' => $run->id()]])->current();
                    $counts = json_decode((string) $runRow['counts_json'], true);
                    sac_assert((int) ($counts['skipped'] ?? 0) >= 1, 'the skipped counter is bumped');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId, [$verId]);
                }
            },

            // NEW v1.1.0 — two versions of the same OS get distinct Reconcile
            // rows, no UNIQUE-key collision.
            'db/eol-two-versions-distinct' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $osId = (int) (new \OperatingSystem())->add(['name' => '__sac_sc_multi__']);
                $ver1 = (int) (new \OperatingSystemVersion())->add(['name' => '7.9', 'operatingsystems_id' => $osId]);
                $ver2 = (int) (new \OperatingSystemVersion())->add(['name' => '9.7', 'operatingsystems_id' => $osId]);
                $cid1 = (int) (new \Computer())->add(['name' => '__sac_sc_multi_a__', 'entities_id' => 0]);
                $cid2 = (int) (new \Computer())->add(['name' => '__sac_sc_multi_b__', 'entities_id' => 0]);
                $DB->insert('glpi_items_operatingsystems', [
                    'items_id' => $cid1, 'itemtype' => 'Computer',
                    'operatingsystems_id' => $osId, 'operatingsystemversions_id' => $ver1,
                ]);
                $DB->insert('glpi_items_operatingsystems', [
                    'items_id' => $cid2, 'itemtype' => 'Computer',
                    'operatingsystems_id' => $osId, 'operatingsystemversions_id' => $ver2,
                ]);
                $startId = self::scRunFloor();
                try {
                    self::run(RunLog::begin('eol'));

                    $rc1 = $DB->request(['FROM' => 'glpi_plugin_sac_reconcile', 'WHERE' => [
                        'ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId, 'ref_subid' => $ver1, 'reason' => 'no_mapping',
                    ]])->current();
                    $rc2 = $DB->request(['FROM' => 'glpi_plugin_sac_reconcile', 'WHERE' => [
                        'ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId, 'ref_subid' => $ver2, 'reason' => 'no_mapping',
                    ]])->current();
                    sac_assert($rc1 !== null, 'version 7.9 gets its own no_mapping row');
                    sac_assert($rc2 !== null, 'version 9.7 gets its own no_mapping row — no UNIQUE-key collision with 7.9');
                } finally {
                    self::scTeardown([$osId], [$cid1, $cid2], [], $startId, [$ver1, $ver2]);
                }
            },
        ];
    }
}
```

- [ ] **Step 2: Run and verify**

Run: `"/c/php/php.exe" -l src/Eol/EolScan.php && "/c/php/php.exe" tools/offline-selfcheck.php`
Expected: `No syntax errors detected`; `15 checks, 0 failed` (unchanged — every
new/changed `EolScan` check is `db/`-prefixed; the whole point of this task
is exercised on the VM, Task 11).

- [ ] **Step 3: Commit**

```bash
git add src/Eol/EolScan.php
git commit -m "feat: EolScan two-pass (os,ver) grain — the v1.1.0 core change"
```

---

## Task 11: Docs + full regression

**Files:**
- Modify: `CHANGELOG.md` (new `[1.1.0]` section)
- Modify: `README.md` (feature description + VM verification checklist)

**Interfaces:** none — documentation and a final full-suite check.

- [ ] **Step 1: Add the CHANGELOG entry**

In `CHANGELOG.md`, insert a new section above `## [1.0.4] - 2026-09-10`:

```markdown
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
```

- [ ] **Step 2: Update the README**

In `README.md`, replace the "Module 1 — EOL/EOS" feature bullets (lines
30-42):

```markdown
### Module 1 — EOL/EOS

- **Weekly OS EOL scan** (`cronEolScan`) over every `OperatingSystem` carrying a live Computer.
- **Layered EOL-date resolution**, first hit wins:
  1. operator-entered `fields`-plugin "LifeCycle" `EndOfLife` date,
  2. admin-confirmed `endoflife.date` product/cycle mapping via a local cache,
  3. unresolved → Reconcile queue, no Finding.
- **`endoflife.date` local cache** (`cronEolCacheRefresh`) — EOL/EOS dates + LTS flag; re-fetch throttled by `eol_cache_max_age_days`.
- **Configurable severity ladder** — past-due → `Critical`, ≤90d → `High`, ≤180d → `Medium`, ≤365d → `Low`, beyond horizon → no Finding.
- **One Finding per OS** (`code = SAC-EOL-OS-<id>`, category `Obsolescence`), a `Finding_Item` per affected Computer, affected hostnames in `content`.
- **Hardware-warranty EOL pass** (optional) — per-Computer Finding (`SAC-EOL-HW-<id>`) from `warranty_date + warranty_duration`.
- **Signal-cleared handling** — when EOL moves beyond the horizon or the OS leaves inventory, appends a dated "Signal cleared" note (once per day) and stops re-asserting. **Never auto-closes.**
- **OS-name → product/cycle matching** — RHEL / Ubuntu / Windows Server / Debian / CentOS / SLES / ESXi, with version-cycle extraction (`8.6`→`8`, `20.04.6`→`20.04`, `2012 R2`→`2012-R2`).
```

with:

```markdown
### Module 1 — EOL/EOS

- **Weekly OS EOL scan** (`cronEolScan`) over every `(OperatingSystem,
  OperatingSystemVersion)` pair carrying a live Computer — GLPI 11 splits an
  OS across dropdown dimensions, so the OS name/id alone is not a valid EOL
  key (v1.1.0).
- **Layered EOL-date resolution**, first hit wins:
  1. operator-entered `fields`-plugin "LifeCycle" `EndOfLife` date — checked
     at the OS-Version level first, then the OS level (the more specific
     wins),
  2. an `endoflife.date` product/cycle mapping — either admin-confirmed via
     Reconcile, or auto-matched this run against the shipped seed
     (`install/data/eol_products.csv`),
  3. unresolved → Reconcile queue, no Finding.
- **`endoflife.date` local cache** (`cronEolCacheRefresh` + an inline
  cache-fill during `cronEolScan` itself) — EOL/EOS dates + LTS flag;
  re-fetch throttled by `eol_cache_max_age_days`.
- **Configurable severity ladder** — past-due → `Critical`, ≤90d → `High`, ≤180d → `Medium`, ≤365d → `Low`, beyond horizon → no Finding.
- **One Finding per (OS, version) pair** (`code = SAC-EOL-OS-<os_id>-<ver_id>`,
  category `Obsolescence`), a `Finding_Item` per affected Computer, affected
  hostnames in `content`.
- **Hardware-warranty EOL pass** (optional) — per-Computer Finding (`SAC-EOL-HW-<id>`) from `warranty_date + warranty_duration`. Unaffected by the v1.1.0 re-keying.
- **Signal-cleared handling** — when EOL moves beyond the horizon or the pair leaves inventory, appends a dated "Signal cleared" note (once per day) and stops re-asserting. **Never auto-closes.**
- **Seed-CSV product/cycle matching** — RHEL / Rocky / AlmaLinux / Oracle Linux / CentOS(-Stream) / Ubuntu / Debian / SLES / openSUSE / Amazon Linux / Windows(-Server) / VMware ESXi/vCenter, with version-cycle extraction (`8.6`→`8`, `22.04.3`→`22.04`, `2012 R2`→`2012-R2`). A non-OS name in the OS dropdown (e.g. an appliance's Kubernetes version) is silently skipped, never Reconciled.
```

Then, in the "VM verification checklist" section, insert a new item 11 (after
the existing item 10 at line 255) — bump nothing else, this is an append:

```markdown
11. **v1.1.0 upgrade only** — after `plugin:install sac --force`, confirm: old
    `SAC-EOL-OS-<n>` Findings are `Closed` with a
    "[superseded … re-keyed to (operatingsystems_id, operatingsystemversions_id)]"
    content note (never deleted); `glpi_plugin_sac_reconcile` has zero
    `signal_type='eol'` rows immediately after the upgrade (SAC wipes and
    regenerates them); `glpi_plugin_sac_eolmap` retains only `confidence =
    'confirmed'` rows (now at `operatingsystemversions_id = 0`). Then run
    `cronEolScan` once and confirm `_eolmap`/`_reconcile` repopulate at the new
    `(os,ver)` grain and new Findings carry the two-int `code`.
```

- [ ] **Step 3: Full regression — lint every touched file + the whole offline suite**

```bash
for f in src/Eol/EolProducts.php src/Code.php src/EolMatch.php \
         src/AssetResolver.php src/Eol/EolMap.php src/Eol/LifeCycleReader.php src/Reconcile.php \
         src/License/CommercialPublisher.php setup.php src/Migration.php src/RunLog.php \
         src/Eol/EolCache.php src/Eol/EolScan.php; do
  "/c/php/php.exe" -l "$f" || break
done
"/c/php/php.exe" tools/offline-selfcheck.php
```

Expected: every `-l` line `No syntax errors detected`; final line
`15 checks, 0 failed`.

Also confirm every plugin `.php` file is lint-clean, not just the ones this
plan touched:

```bash
git ls-files '*.php' | while read -r f; do "/c/php/php.exe" -l "$f" || break; done
```

Expected: `No syntax errors detected` for every file, no break.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md README.md
git commit -m "docs: v1.1.0 changelog + README EOL grain update"
```

---

## After this plan (VM deployment — not part of this plan's scope)

Once `feat/eol-multidim` is merged, the VM upgrade path is:
`sudo -u nginx php bin/console plugin:install sac --force` +
`plugin:activate sac` (the web Upgrade button is broken on GLPI 11, hub §3),
then `bin/console migration:timestamps` (expect clean), then
`tools/selfcheck.php` (expect all PASS, including the new `db/eol-*` and
`db/reconcile-subid-distinct` / `db/os-version-computers` /
`db/eolmap-version-distinct` / `db/eolcache-ensurefresh` checks), then run
`EolScan` once via Setup → Automatic actions → Execute and confirm: old-format
Findings are `Closed` with the superseded note, new Findings carry the
two-int `code`, and `_eolmap`/`_reconcile` repopulate at the `(os,ver)` grain.
This is tracked separately — outside this plan — per the spec's §12 VM
verification checklist.
