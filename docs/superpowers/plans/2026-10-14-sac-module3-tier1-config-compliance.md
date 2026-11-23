# SAC Module 3 Phase 1 — Config Compliance (OpenSCAP Tier 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ingest the Tier 1 OpenSCAP summary line (one score per host, collected via
GLPI Agent's Collect task) and emit a coarse per-host `Finding` into `riskregister`
when a host's config-compliance score falls below a configurable threshold, with
a SAC dashboard page listing every host's latest score.

**Architecture:** A new `GlpiPlugin\Sac\Compliance` namespace mirrors the existing
`Eol`/`License` module shape: a pure line-parser (`ScoreLine`), a thin DB reader
for the GLPI Inventory Collect value (`CollectReader`), and an orchestrator
(`ConfigScan`) wired to a weekly cron task exactly like `EolScan`/`LicenseScan`.
Every write to `riskregister` goes through the existing `FindingWriter` — this
plan adds no new integration path. A new `glpi_plugin_sac_configscan` table holds
the latest score per `Computer` (overwrite semantics, like `_eolcache`), feeding
both the Finding content and a new `front/compliance.php` list page.

**Tech Stack:** PHP 8.4, GLPI 11 plugin APIs (`CommonDBTM`, `Migration`,
`TemplateRenderer`/Twig), MySQL via GLPI's `$DB` wrapper. No new Composer deps.

**Spec:** `docs/project-brief.md` §3 (Module 3 row), §114 "OpenSCAP collection —
tiered" (Tier 1), and the orchestrator's 2026-10-14 hub §7 relay of the user's
"lanjutkan openrmf untuk module 3 SAC" decision (full scope note; this plan
implements the Tier 1 slice only — see Global Constraints).

## Global Constraints

- **Tier 1 only.** This plan does NOT implement a per-rule checklist (Open / Not
  a Finding / Not Applicable / Not Reviewed), OVAL patch-state detail, or POA&M —
  those need the full `results.xml`/ARF pull (Tier 2, project-brief §12), whose
  transport (SSH vs Kerberos/GSSAPI) is not yet decided. Deferred to a Phase 2
  plan, flagged in hub §7 once this phase ships.
- **No new `riskregister` integration path.** Every Finding write goes through
  the existing `\GlpiPlugin\Sac\FindingWriter::upsert()` / `::clearExcept()`
  contract (project-brief §5). Category name: `Configuration Compliance`
  (already seeded by riskregister v1.1.0, project-brief §170).
- **No entity-model decision needed.** Unlike a connection-based module, Tier 1
  data is already tied to a specific `Computer` by GLPI Inventory's own agent
  report — there is no "connection owner" ambiguity (hub §4 entity rule). A
  Finding is written in that Computer's own entity, never recursive — same as
  every other SAC Finding since v1.5.0.
- **GLPI Inventory Collect schema is VM-unverified** (project-brief §10.2, same
  category of fact as the `fields` LifeCycle table name in Module 1). Code in
  this plan is written against the best-documented guess
  (`glpi_plugin_glpiinventory_collects` / `glpi_plugin_glpiinventory_collects_contents`),
  guarded by `tableExists()`/`fieldExists()` so a wrong guess degrades to
  "Collect data unavailable" (a logged skip, never a fatal) rather than breaking
  the cron. Task 12 is the VM fact-finding step that confirms or corrects it.
- **Upgrade resilience (project-brief §4 design principle):** no hand-rolled
  `<select>`/form HTML; GLPI's `timestamp NULL DEFAULT NULL` form for all
  `date_*` columns (hub §7 `migration:timestamps` gotcha); every FK-shaped
  column is `int unsigned` with the `'fkey'` migration keyword, never
  `'integer'` (hub §4 rule 2b).
- **`canView(): bool`** — not applicable this phase (no new `CommonGLPI`
  subclass with tab hooks is added), but if Task 10 ends up needing one, match
  the hub §3 gotcha.

## Review Focus

1. **Malformed Collect value** (wrong field count, non-numeric score, garbage
   timestamp) must not throw out of the cron loop — one bad host's bad line
   goes to `_reconcile` (`reason=bad_summary`) and `errors++`; the run still
   finishes `ok`. Pinned in Task 3 (`ScoreLine::parse()` returns `null`, never
   throws) and Task 8 (`ConfigScan` handles the `null` branch).
2. **Stale Collect data** — a host whose latest report is older than
   `compliance_stale_days` must visibly say so (content note + dashboard flag),
   not read as silently healthy forever just because its last good score was
   above threshold. Pinned in Task 8 (`isStale()`) and Task 11 (template badge).
3. **Score reaches 100% after a prior Finding existed** — the existing
   per-computer Finding must be cleared (via `FindingWriter::clearExcept()`),
   not left open forever just because `ConfigScan` stops writing to it. Pinned
   in Task 8.
4. **`riskregister` inactive** — the cron tick must still complete and close
   its `_run` row `ok` (mirrors the WRITER-1 pattern already proven in
   `Cron::selfChecks()` for `EolScan`). Pinned in Task 9.
5. **Collect feature itself unavailable** (plugin inactive, table missing, or
   the admin's configured `compliance_collect_name` never matches a defined
   Collect task) — the scan must skip cleanly with exactly one logged warning
   per run (`skipped++`), never one warning per host and never `errors++`.
   Pinned in Task 7 (`CollectReader::isAvailable()`) and Task 8.

---

## File Structure

- Create: `src/Compliance/ScoreLine.php` — pure parser for the Tier 1 summary line.
- Create: `src/Compliance/CollectReader.php` — reads the latest Collect value/date for a Computer.
- Create: `src/Compliance/ConfigScan.php` — orchestrator (mirrors `Eol\EolScan`).
- Create: `front/compliance.php` — list page (mirrors `front/run.php`).
- Create: `templates/compliance.html.twig` — list template.
- Modify: `src/Severity.php` — add `forConfigScore()`.
- Modify: `src/Code.php` — add `configScore()`.
- Modify: `src/AssetResolver.php` — add `activeComputers()`.
- Modify: `src/Migration.php` — add `glpi_plugin_sac_configscan` table.
- Modify: `src/Config.php` — add `compliance_*` keys, `FIELD_TYPES`, a new `sections()` entry.
- Modify: `src/Cron.php` — add `cronConfigScan()` + register.
- Modify: `src/Menu.php` — add the `compliance` entry + its selfcheck expectation.

## Interfaces summary (for cross-task reference)

- `ScoreLine::parse(string $line): ?array{benchmark_id:string,score:float,pass:int,fail:int,other:int,date_scan:string}`
- `Severity::forConfigScore(float $score, int $critical = 50, int $high = 70, int $medium = 85): ?string`
- `Code::configScore(int $computerId): string`
- `AssetResolver::activeComputers(?array $scope): array<int,array{name:string,entities_id:int}>`
- `CollectReader::isAvailable(): bool`
- `CollectReader::latestFor(int $computerId, string $collectName): ?array{value:string,date:?string}`
- `ConfigScan::run(RunLog $run): void`

---

### Task 1: `Severity::forConfigScore()`

**Files:**
- Modify: `src/Severity.php`
- Test: same file's `selfChecks()` (offline, pure)

**Interfaces:**
- Produces: `Severity::forConfigScore(float $score, int $critical = 50, int $high = 70, int $medium = 85): ?string`

- [ ] **Step 1: Write the failing test** — add to `Severity::selfChecks()` in `src/Severity.php`:

```php
'severity/config-score-bands' => static function (): void {
    sac_assert_eq(Severity::forConfigScore(40.0), 'Critical', 'below critical');
    sac_assert_eq(Severity::forConfigScore(50.0), 'High', 'critical boundary is exclusive');
    sac_assert_eq(Severity::forConfigScore(70.0), 'Medium', 'high boundary is exclusive');
    sac_assert_eq(Severity::forConfigScore(85.0), 'Low', 'medium boundary is exclusive');
    sac_assert_eq(Severity::forConfigScore(99.9), 'Low', 'just under full compliance');
    sac_assert_eq(Severity::forConfigScore(100.0), null, 'fully compliant: no Finding');
},
'severity/config-score-custom-thresholds' => static function (): void {
    sac_assert_eq(Severity::forConfigScore(60.0, 30, 60, 80), 'Medium', 'custom high boundary');
},
```

- [ ] **Step 2: Run to verify it fails**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php severity/config-score`
Expected: FAIL — `Call to undefined method ... forConfigScore()`

- [ ] **Step 3: Implement**, in `src/Severity.php` (after `forLicenseOverage()`):

```php
public static function forConfigScore(
    float $score,
    int $critical = 50,
    int $high = 70,
    int $medium = 85
): ?string {
    if ($score < $critical) {
        return 'Critical';
    }
    if ($score < $high) {
        return 'High';
    }
    if ($score < $medium) {
        return 'Medium';
    }
    if ($score < 100.0) {
        return 'Low';
    }
    return null;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php severity/config-score`
Expected: PASS (both checks)

- [ ] **Step 5: Commit**

```bash
git add src/Severity.php
git commit -m "feat(sac): add config-compliance score severity bands"
```

---

### Task 2: `Code::configScore()`

**Files:**
- Modify: `src/Code.php`

**Interfaces:**
- Produces: `Code::configScore(int $computerId): string`

- [ ] **Step 1: Write the failing test** — add to `Code::selfChecks()`:

```php
'code/config-score-format' => static function (): void {
    sac_assert_eq(Code::configScore(42), 'SAC-CFG-SCORE-42', 'configScore');
},
```

and extend the existing `'code/length'` check:

```php
sac_assert(strlen(self::configScore(2147483647)) <= 50, 'configScore <=50');
```

- [ ] **Step 2: Run to verify it fails**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php code/`
Expected: FAIL — undefined method `configScore`

- [ ] **Step 3: Implement**, in `src/Code.php`:

```php
public static function configScore(int $computerId): string { return 'SAC-CFG-SCORE-' . $computerId; }
```

- [ ] **Step 4: Run to verify it passes**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php code/`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Code.php
git commit -m "feat(sac): add per-computer config-compliance Finding code"
```

---

### Task 3: `ScoreLine` — pure parser for the Tier 1 summary line

**Files:**
- Create: `src/Compliance/ScoreLine.php`

**Format decided here** (nothing upstream defines one yet — this is the contract
the host-side `oscap` cron wrapper must produce and GLPI Inventory's Collect task
(type `command`) must capture verbatim):

```
SACCFG1|<benchmark_id>|<score 0-100>|<pass>|<fail>|<other>|<ISO-8601 timestamp>
```

Example: `SACCFG1|xccdf_org.ssgproject.content_profile_cis|82.50|145|12|3|2026-10-14T03:00:00+07:00`

**Interfaces:**
- Produces: `ScoreLine::parse(string $line): ?array{benchmark_id:string,score:float,pass:int,fail:int,other:int,date_scan:string}`
  (`date_scan` is normalized to `Y-m-d H:i:s`, GLPI's storage format)

- [ ] **Step 1: Write the failing test** — new file `src/Compliance/ScoreLine.php` test block (written first, implementation stubbed to fail):

```php
<?php

namespace GlpiPlugin\Sac\Compliance;

final class ScoreLine
{
    public static function selfChecks(): array
    {
        return [
            'scoreline/parses-well-formed' => static function (): void {
                $r = self::parse('SACCFG1|xccdf_org.ssgproject.content_profile_cis|82.50|145|12|3|2026-10-14T03:00:00+07:00');
                sac_assert($r !== null, 'well-formed line parses');
                sac_assert_eq($r['benchmark_id'], 'xccdf_org.ssgproject.content_profile_cis', 'benchmark_id');
                sac_assert_eq($r['score'], 82.50, 'score');
                sac_assert_eq($r['pass'], 145, 'pass');
                sac_assert_eq($r['fail'], 12, 'fail');
                sac_assert_eq($r['other'], 3, 'other');
                sac_assert_eq($r['date_scan'], '2026-10-14 03:00:00', 'date_scan normalized, tz dropped by strtotime->date() in server-local form');
            },
            'scoreline/rejects-malformed' => static function (): void {
                sac_assert(self::parse('') === null, 'empty string');
                sac_assert(self::parse('not-a-score-line') === null, 'no pipes');
                sac_assert(self::parse('SACCFG1|bm|notanumber|1|2|3|2026-10-14T00:00:00Z') === null, 'non-numeric score');
                sac_assert(self::parse('SACCFG1|bm|101|1|2|3|2026-10-14T00:00:00Z') === null, 'score over 100');
                sac_assert(self::parse('SACCFG1|bm|-1|1|2|3|2026-10-14T00:00:00Z') === null, 'score under 0');
                sac_assert(self::parse('SACCFG1|bm|50|-1|2|3|2026-10-14T00:00:00Z') === null, 'negative pass count');
                sac_assert(self::parse('SACCFG1|bm|50|1|2|3|not-a-date') === null, 'unparseable timestamp');
                sac_assert(self::parse('SACCFG2|bm|50|1|2|3|2026-10-14T00:00:00Z') === null, 'wrong format tag');
                sac_assert(self::parse('SACCFG1|bm|50|1|2|2026-10-14T00:00:00Z') === null, 'too few fields');
            },
            'scoreline/empty-benchmark-rejected' => static function (): void {
                sac_assert(self::parse('SACCFG1||50|1|2|3|2026-10-14T00:00:00Z') === null, 'blank benchmark id');
            },
        ];
    }

    public static function parse(string $line): ?array
    {
        return null; // Step 3 replaces this.
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php scoreline/`
Expected: FAIL on `scoreline/parses-well-formed` (stub returns `null`)

- [ ] **Step 3: Implement** — replace the stub body:

```php
    private const TAG = 'SACCFG1';

    /**
     * PURE. Parses the Tier 1 oscap summary line (format documented in this
     * file's docblock above). Returns null on ANY malformation — callers
     * route a null into `_reconcile`, never a partial/best-effort result.
     *
     * @return array{benchmark_id:string,score:float,pass:int,fail:int,other:int,date_scan:string}|null
     */
    public static function parse(string $line): ?array
    {
        $parts = explode('|', trim($line));
        if (count($parts) !== 7 || $parts[0] !== self::TAG) {
            return null;
        }
        [, $benchmarkId, $scoreRaw, $passRaw, $failRaw, $otherRaw, $tsRaw] = $parts;

        $benchmarkId = trim($benchmarkId);
        if ($benchmarkId === '') {
            return null;
        }

        if (!is_numeric($scoreRaw)) {
            return null;
        }
        $score = (float) $scoreRaw;
        if ($score < 0.0 || $score > 100.0) {
            return null;
        }

        foreach (['pass' => $passRaw, 'fail' => $failRaw, 'other' => $otherRaw] as $raw) {
            if (!ctype_digit($raw)) {
                return null;
            }
        }

        $ts = strtotime($tsRaw);
        if ($ts === false) {
            return null;
        }

        return [
            'benchmark_id' => $benchmarkId,
            'score'        => $score,
            'pass'         => (int) $passRaw,
            'fail'         => (int) $failRaw,
            'other'        => (int) $otherRaw,
            'date_scan'    => date('Y-m-d H:i:s', $ts),
        ];
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php scoreline/`
Expected: PASS (all three checks)

- [ ] **Step 5: Commit**

```bash
git add src/Compliance/ScoreLine.php
git commit -m "feat(sac): add Tier 1 OpenSCAP summary-line parser"
```

---

### Task 4: `AssetResolver::activeComputers()`

**Files:**
- Modify: `src/AssetResolver.php`

**Interfaces:**
- Consumes: `Config::scanScope()` shape (`?array<int,int>`, already defined in `src/Config.php`)
- Produces: `AssetResolver::activeComputers(?array $scope): array<int,array{name:string,entities_id:int}>`

- [ ] **Step 1: Write the failing test** — add to `AssetResolver::selfChecks()`:

```php
'db/active-computers' => static function (): void {
    global $DB;
    $cId = 0; $cId2 = 0;
    try {
        $cId  = (int) (new \Computer())->add(['name' => '__sac_ac_1__', 'entities_id' => 0]);
        $cId2 = (int) (new \Computer())->add(['name' => '__sac_ac_2__', 'entities_id' => 0]);

        $all = self::activeComputers(null);
        sac_assert_eq($all[$cId]['name'], '__sac_ac_1__', 'computer present with its name');
        sac_assert_eq($all[$cId]['entities_id'], 0, 'computer present with its entity');

        $DB->update('glpi_computers', ['is_deleted' => 1], ['id' => $cId2]);
        $all2 = self::activeComputers(null);
        sac_assert(!array_key_exists($cId2, $all2), 'deleted computer excluded');

        $scoped = self::activeComputers([9999999]); // entity that has none of our fixtures
        sac_assert(!array_key_exists($cId, $scoped), 'out-of-scope entity excluded');
    } finally {
        $DB->delete('glpi_computers', ['id' => $cId]);
        $DB->delete('glpi_computers', ['id' => $cId2]);
    }
},
```

- [ ] **Step 2: Run to verify it fails**

Run (on the VM, or any GLPI-bootstrapped PHP): `php plugins/sac/tools/selfcheck.php active-computers`
Expected: FAIL — undefined method `activeComputers`

- [ ] **Step 3: Implement**, in `src/AssetResolver.php` (after `computersForSoftware()`):

```php
    /**
     * Every non-deleted, non-template Computer, optionally restricted to
     * {@see Config::scanScope()}. Used by modules whose source data is keyed
     * directly to a Computer (Module 3) rather than to an OS/Software id.
     *
     * @param array<int,int>|null $scope null = every entity
     * @return array<int,array{name:string,entities_id:int}>
     */
    public static function activeComputers(?array $scope): array
    {
        global $DB;
        $where = [
            'is_deleted'  => 0,
            'is_template' => 0,
        ];
        if ($scope !== null) {
            $where['entities_id'] = $scope;
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'entities_id'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => $where,
        ]) as $row) {
            $out[(int) $row['id']] = [
                'name'        => (string) $row['name'],
                'entities_id' => (int) $row['entities_id'],
            ];
        }
        return $out;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php plugins/sac/tools/selfcheck.php active-computers`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AssetResolver.php
git commit -m "feat(sac): add AssetResolver::activeComputers for computer-keyed modules"
```

---

### Task 5: `glpi_plugin_sac_configscan` table

**Files:**
- Modify: `src/Migration.php`

- [ ] **Step 1: Write the failing test** — add to `Migration::selfChecks()`:

```php
'db/migration-configscan-table' => static function (): void {
    global $DB;
    sac_assert($DB->tableExists('glpi_plugin_sac_configscan'), 'table created by install()');
    sac_assert($DB->fieldExists('glpi_plugin_sac_configscan', 'computers_id'), 'computers_id column');
    sac_assert($DB->fieldExists('glpi_plugin_sac_configscan', 'score'), 'score column');
    sac_assert(self::keyExists($DB, 'glpi_plugin_sac_configscan', 'computer'), 'UNIQUE key on computers_id');
},
```

(`keyExists()` is `private static` — this check runs as a closure bound to the
class via `static function` inside `Migration::selfChecks()` itself, same as
the existing `db/migration-eolmap-key` check, so the visibility is not an
issue.)

- [ ] **Step 2: Run to verify it fails**

Run: `php plugins/sac/tools/selfcheck.php migration-configscan`
Expected: FAIL — table does not exist (not yet in `define()`)

- [ ] **Step 3: Implement** — add to `Migration::define()`, after the
  `glpi_plugin_sac_run` table definition:

```php
        self::$tables['glpi_plugin_sac_configscan'] = "
            CREATE TABLE `glpi_plugin_sac_configscan` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `computers_id` int unsigned NOT NULL DEFAULT 0,
              `benchmark_id` varchar(255) NOT NULL DEFAULT '',
              `score` decimal(5,2) NOT NULL DEFAULT 0.00,
              `pass_count` int unsigned NOT NULL DEFAULT 0,
              `fail_count` int unsigned NOT NULL DEFAULT 0,
              `other_count` int unsigned NOT NULL DEFAULT 0,
              `date_scan` timestamp NULL DEFAULT NULL,
              `date_collected` timestamp NULL DEFAULT NULL,
              `raw_line` text,
              `date_creation` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `computer` (`computers_id`)
            ) $table_options";
```

No upgrade-path helper is needed (unlike `_eolmap`/`_reconcile`): this table is
new, so every environment reaches it the same way — `install()`'s existing
`!$DB->tableExists($name)` loop already creates it on both a fresh install and
an upgrade.

- [ ] **Step 4: Run to verify it passes**

Run: `php plugins/sac/tools/selfcheck.php migration-configscan`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Migration.php
git commit -m "feat(sac): add glpi_plugin_sac_configscan table"
```

---

### Task 6: `Config` — new keys, field types, Setup section

**Files:**
- Modify: `src/Config.php`

- [ ] **Step 1: Write the failing test** — extend the existing checks in
  `Config::selfChecks()` (they already iterate `DEFAULTS` / `FIELD_TYPES` /
  `sections()` generically, so adding the new keys to `DEFAULTS` is itself
  what makes `config/field-types-cover-all-keys` and
  `db/config-sections-cover-all-keys` start failing until Steps 3 below add
  the matching entries). No new check functions are needed — add this one
  assertion to `config/defaults-shape` to pin the new master switch's default:

```php
sac_assert_eq(self::DEFAULTS['compliance_scan_enabled'], 1, 'config compliance scan on by default');
```

- [ ] **Step 2: Run to verify it fails**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php config/defaults-shape`
Expected: FAIL — undefined array key `compliance_scan_enabled`

- [ ] **Step 3: Implement** — add to `Config::DEFAULTS`:

```php
        'compliance_scan_enabled'   => 1,
        'compliance_collect_name'  => 'sac_oscap_summary',
        'compliance_score_critical'=> 50,
        'compliance_score_high'    => 70,
        'compliance_score_medium'  => 85,
        'compliance_stale_days'    => 14,
```

add to `Config::FIELD_TYPES`:

```php
        'compliance_scan_enabled'   => 'bool',
        'compliance_collect_name'  => 'text',
        'compliance_score_critical'=> 'int',
        'compliance_score_high'    => 'int',
        'compliance_score_medium'  => 'int',
        'compliance_stale_days'    => 'int',
```

add a new section to `Config::sections()`, after the `License governance` block:

```php
            [
                'title'  => __('Config compliance (OpenSCAP Tier 1)', 'sac'),
                'icon'   => 'ti ti-shield-check',
                'hint'   => __('Reads the one-line oscap summary GLPI Agent\'s Collect task reports per host. Does not import per-rule results — that is a future phase.', 'sac'),
                'fields' => [
                    'compliance_scan_enabled' => [
                        'label' => __('Scan config-compliance Collect data', 'sac'),
                        'hint'  => '',
                    ],
                    'compliance_collect_name' => [
                        'label' => __('GLPI Inventory Collect task name', 'sac'),
                        'hint'  => __('Must exactly match the Collect task defined under Setup.', 'sac'),
                    ],
                    'compliance_score_critical' => [
                        'label' => __('Severity Critical below', 'sac'),
                        'hint'  => '',
                        'unit'  => '%',
                    ],
                    'compliance_score_high' => [
                        'label' => __('Severity High below', 'sac'),
                        'hint'  => '',
                        'unit'  => '%',
                    ],
                    'compliance_score_medium' => [
                        'label' => __('Severity Medium below', 'sac'),
                        'hint'  => __('Anything at or above this, below 100%, is Low. 100% exactly clears the finding.', 'sac'),
                        'unit'  => '%',
                    ],
                    'compliance_stale_days' => [
                        'label' => __('Flag a host as stale after', 'sac'),
                        'hint'  => __('No fresher Collect report in this many days.', 'sac'),
                        'unit'  => __('days', 'sac'),
                    ],
                ],
            ],
```

- [ ] **Step 4: Run to verify it passes**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php config/`
Expected: PASS for `config/defaults-shape` and `config/field-types-cover-all-keys`
(the `db/config-sections-cover-all-keys` check needs GLPI's `__()`, so it only
runs via `php plugins/sac/tools/selfcheck.php config-sections` on the VM —
confirm it there too.)

- [ ] **Step 5: Commit**

```bash
git add src/Config.php
git commit -m "feat(sac): add config-compliance settings"
```

---

### Task 7: `CollectReader`

**Files:**
- Create: `src/Compliance/CollectReader.php`

**VM-unverified schema** (Global Constraints note): table/column names below
are a documented best guess for GLPI Inventory's Collect feature, guarded so a
wrong guess degrades to "unavailable" rather than crashing. Task 12 confirms or
corrects them.

**Interfaces:**
- Produces: `CollectReader::isAvailable(): bool`
- Produces: `CollectReader::latestFor(int $computerId, string $collectName): ?array{value:string,date:?string}`

- [ ] **Step 1: Write the failing test** — new file with its test block, impl stubbed:

```php
<?php

namespace GlpiPlugin\Sac\Compliance;

final class CollectReader
{
    private const COLLECTS_TABLE = 'glpi_plugin_glpiinventory_collects';
    private const VALUES_TABLE   = 'glpi_plugin_glpiinventory_collects_contents';

    public static function isAvailable(): bool
    {
        return false; // Step 3 replaces this.
    }

    public static function latestFor(int $computerId, string $collectName): ?array
    {
        return null; // Step 3 replaces this.
    }

    /** @return array<string,callable():void> VM-only — these tables may not exist/match yet; see Task 12. */
    public static function selfChecks(): array
    {
        return [
            'db/collectreader-unavailable-is-safe' => static function (): void {
                // This check only asserts the SAFE-DEGRADATION contract, not
                // real data — it must pass whether or not Task 12 has
                // confirmed the real schema yet.
                if (self::isAvailable()) {
                    return; // the real schema matched on this VM — nothing to prove here
                }
                sac_assert(self::latestFor(1, 'anything') === null, 'unavailable reader returns null, never throws');
            },

            'db/collectreader-roundtrip' => static function (): void {
                if (!self::isAvailable()) {
                    echo "SKIP  db/collectreader-roundtrip — Collect schema not confirmed on this VM yet (Task 12)\n";
                    return;
                }
                global $DB;
                $name = '__sac_sc_collect__';
                $cid  = (int) (new \Computer())->add(['name' => '__sac_sc_collect_host__', 'entities_id' => 0]);
                $collectId = 0;
                try {
                    sac_assert($cid > 0, 'seed computer created');
                    $DB->insert(self::COLLECTS_TABLE, ['name' => $name]);
                    $collectId = (int) $DB->insertId();
                    $DB->insert(self::VALUES_TABLE, [
                        'plugin_glpiinventory_collects_id' => $collectId,
                        'computers_id'                      => $cid,
                        'value'                              => 'SACCFG1|bm|90|1|2|3|2026-10-14T00:00:00Z',
                        'date'                                => '2026-10-14 00:00:00',
                    ]);

                    $got = self::latestFor($cid, $name);
                    sac_assert($got !== null, 'value found');
                    sac_assert_eq($got['value'], 'SACCFG1|bm|90|1|2|3|2026-10-14T00:00:00Z', 'value matches');
                } finally {
                    $DB->delete(self::VALUES_TABLE, ['computers_id' => $cid]);
                    if ($collectId > 0) {
                        $DB->delete(self::COLLECTS_TABLE, ['id' => $collectId]);
                    }
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },
        ];
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php plugins/sac/tools/selfcheck.php collectreader`
Expected: the `unavailable-is-safe` check PASSES already (stub returns
`false`/`null`, which is exactly the safe-degradation contract) — this is
expected and fine; it is `roundtrip` that SKIPs until Step 3 and Task 12 both
land. Confirm the stub does not crash: `FAIL` here would mean the stub itself
throws, which it must not.

- [ ] **Step 3: Implement** — replace the two stub bodies:

```php
    public static function isAvailable(): bool
    {
        global $DB;
        return \Plugin::isPluginActive('glpiinventory')
            && $DB->tableExists(self::COLLECTS_TABLE)
            && $DB->tableExists(self::VALUES_TABLE)
            && $DB->fieldExists(self::VALUES_TABLE, 'computers_id')
            && $DB->fieldExists(self::VALUES_TABLE, 'value');
    }

    /**
     * The most recent Collect value for $computerId under the Collect task
     * named $collectName, with its reported date if the table carries one.
     * Null when the Collect feature/table isn't available, the named task
     * doesn't exist, or no value has been reported for this computer yet —
     * all three are normal, non-error states for a caller to skip over.
     *
     * @return array{value:string,date:?string}|null
     */
    public static function latestFor(int $computerId, string $collectName): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }
        global $DB;

        $collect = $DB->request([
            'FROM'  => self::COLLECTS_TABLE,
            'WHERE' => ['name' => $collectName],
            'LIMIT' => 1,
        ])->current();
        if (!$collect) {
            return null;
        }

        $hasDate = $DB->fieldExists(self::VALUES_TABLE, 'date');
        $row = $DB->request([
            'FROM'  => self::VALUES_TABLE,
            'WHERE' => [
                'computers_id'                      => $computerId,
                'plugin_glpiinventory_collects_id'   => (int) $collect['id'],
            ],
            'ORDER' => $hasDate ? ['date DESC', 'id DESC'] : ['id DESC'],
            'LIMIT' => 1,
        ])->current();
        if (!$row) {
            return null;
        }

        return [
            'value' => (string) $row['value'],
            'date'  => $hasDate ? (string) $row['date'] : null,
        ];
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php plugins/sac/tools/selfcheck.php collectreader`
Expected: `unavailable-is-safe` PASSES unconditionally; `roundtrip` either PASSES
(schema guess was right) or prints SKIP with the Task 12 pointer (schema guess
needs correcting) — either outcome is acceptable at this step; a FAIL is not.

- [ ] **Step 5: Commit**

```bash
git add src/Compliance/CollectReader.php
git commit -m "feat(sac): add GLPI Inventory Collect reader for config-compliance Tier 1"
```

---

### Task 8: `ConfigScan` orchestrator

**Files:**
- Create: `src/Compliance/ConfigScan.php`

**Interfaces:**
- Consumes: `CollectReader::isAvailable()/::latestFor()` (Task 7), `ScoreLine::parse()`
  (Task 3), `AssetResolver::activeComputers()` (Task 4), `Code::configScore()`
  (Task 2), `Severity::forConfigScore()` (Task 1), `Config::get()`/`::scanScope()`
  (existing + Task 6), `FindingWriter::upsert()/::clearExcept()/::categoryId()`
  (existing), `Reconcile::note()` (existing), `RunLog` (existing).
- Produces: `ConfigScan::run(RunLog $run): void`

- [ ] **Step 1: Write the failing test** — add a VM selfcheck exercising the
  full loop (new file, test block first, `run()` stubbed to a no-op):

```php
<?php

namespace GlpiPlugin\Sac\Compliance;

use GlpiPlugin\Sac\Code;
use GlpiPlugin\Sac\Config;
use GlpiPlugin\Sac\FindingWriter;
use GlpiPlugin\Sac\RunLog;

final class ConfigScan
{
    public static function run(RunLog $run): void
    {
        // Step 3 replaces this.
    }

    /** @return array<string,callable():void> VM-only. */
    public static function selfChecks(): array
    {
        return [
            'db/configscan-emits-finding-below-threshold' => static function (): void {
                if (!CollectReader::isAvailable() || !FindingWriter::isAvailable()) {
                    echo "SKIP  db/configscan-emits-finding-below-threshold — Collect schema or riskregister not available\n";
                    return;
                }
                global $DB;
                $name = '__sac_sc_cs_collect__';
                $cid  = (int) (new \Computer())->add(['name' => '__sac_sc_cs_host__', 'entities_id' => 0]);
                $collectId = 0;
                $code = Code::configScore($cid);
                $f = new \GlpiPlugin\Riskregister\Finding();
                try {
                    $DB->insert('glpi_plugin_glpiinventory_collects', ['name' => $name]);
                    $collectId = (int) $DB->insertId();
                    $DB->insert('glpi_plugin_glpiinventory_collects_contents', [
                        'plugin_glpiinventory_collects_id' => $collectId,
                        'computers_id'                      => $cid,
                        'value'                              => 'SACCFG1|xccdf_bm|40.00|10|20|0|2026-10-14T00:00:00Z',
                        'date'                                => '2026-10-14 00:00:00',
                    ]);
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_collect_name' => $name]);

                    $run = RunLog::begin('selftest');
                    self::run($run);
                    $run->finish('ok');

                    $rows = $f->find(['entities_id' => 0, 'code' => $code]);
                    sac_assert_eq(count($rows), 1, 'one Finding written for the below-threshold host');
                    $row = array_values($rows)[0];
                    sac_assert_eq($row['severity'], 'Critical', 'score 40 is Critical');

                    $scanRow = $DB->request(['FROM' => 'glpi_plugin_sac_configscan', 'WHERE' => ['computers_id' => $cid]])->current();
                    sac_assert($scanRow !== null, 'configscan row written');
                    sac_assert_eq((float) $scanRow['score'], 40.00, 'score persisted');

                    // Re-run with a fully-compliant line: the Finding must clear.
                    $DB->update('glpi_plugin_glpiinventory_collects_contents',
                        ['value' => 'SACCFG1|xccdf_bm|100.00|30|0|0|2026-10-15T00:00:00Z', 'date' => '2026-10-15 00:00:00'],
                        ['computers_id' => $cid]);
                    $run2 = RunLog::begin('selftest');
                    self::run($run2);
                    $run2->finish('ok');

                    $f->getFromDB((int) $row['id']);
                    sac_assert(str_contains((string) $f->fields['content'], 'Signal cleared'), 'full compliance clears the finding');
                } finally {
                    foreach (array_keys($f->find(['entities_id' => 0, 'code' => $code])) as $fid) {
                        $f->delete(['id' => (int) $fid], true);
                    }
                    $DB->delete('glpi_plugin_sac_configscan', ['computers_id' => $cid]);
                    $DB->delete('glpi_plugin_glpiinventory_collects_contents', ['computers_id' => $cid]);
                    if ($collectId > 0) {
                        $DB->delete('glpi_plugin_glpiinventory_collects', ['id' => $collectId]);
                    }
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            'db/configscan-malformed-line-reconciles' => static function (): void {
                if (!CollectReader::isAvailable()) {
                    echo "SKIP  db/configscan-malformed-line-reconciles — Collect schema not available\n";
                    return;
                }
                global $DB;
                $name = '__sac_sc_cs_bad__';
                $cid  = (int) (new \Computer())->add(['name' => '__sac_sc_cs_bad_host__', 'entities_id' => 0]);
                $collectId = 0;
                try {
                    $DB->insert('glpi_plugin_glpiinventory_collects', ['name' => $name]);
                    $collectId = (int) $DB->insertId();
                    $DB->insert('glpi_plugin_glpiinventory_collects_contents', [
                        'plugin_glpiinventory_collects_id' => $collectId,
                        'computers_id'                      => $cid,
                        'value'                              => 'garbage not a summary line',
                        'date'                                => '2026-10-14 00:00:00',
                    ]);
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_collect_name' => $name]);

                    $run = RunLog::begin('selftest');
                    self::run($run);
                    $run->finish('ok');

                    $reconciled = $DB->request([
                        'FROM'  => 'glpi_plugin_sac_reconcile',
                        'WHERE' => ['signal_type' => 'config', 'ref_itemtype' => 'Computer', 'ref_id' => $cid, 'reason' => 'bad_summary'],
                    ])->current();
                    sac_assert($reconciled !== null, 'malformed line produced a bad_summary reconcile row');
                } finally {
                    $DB->delete('glpi_plugin_sac_reconcile', ['ref_itemtype' => 'Computer', 'ref_id' => $cid]);
                    $DB->delete('glpi_plugin_glpiinventory_collects_contents', ['computers_id' => $cid]);
                    if ($collectId > 0) {
                        $DB->delete('glpi_plugin_glpiinventory_collects', ['id' => $collectId]);
                    }
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },
        ];
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php plugins/sac/tools/selfcheck.php configscan`
Expected: FAIL (or SKIP if Collect schema unconfirmed) — `run()` is a no-op, so
no Finding/reconcile row appears.

- [ ] **Step 3: Implement** — replace the stub body of `run()`:

```php
    public static function run(RunLog $run): void
    {
        if (!CollectReader::isAvailable()) {
            $run->bump('skipped');
            \Toolbox::logInFile('sac', 'ConfigScan: glpiinventory Collect not available — skipped' . "\n");
            return;
        }

        $collectName = (string) Config::get('compliance_collect_name');
        $critical    = (int) Config::get('compliance_score_critical');
        $high        = (int) Config::get('compliance_score_high');
        $medium      = (int) Config::get('compliance_score_medium');
        $staleDays   = (int) Config::get('compliance_stale_days');
        $catId       = FindingWriter::categoryId('Configuration Compliance', 0);
        $scope       = Config::scanScope();

        global $DB;

        foreach (\GlpiPlugin\Sac\AssetResolver::activeComputers($scope) as $cid => $asset) {
            $run->bump('scanned');
            $collected = CollectReader::latestFor($cid, $collectName);
            if ($collected === null || trim($collected['value']) === '') {
                continue; // agent hasn't reported under this task name yet — not an error
            }

            $parsed = \GlpiPlugin\Sac\Compliance\ScoreLine::parse($collected['value']);
            if ($parsed === null) {
                \GlpiPlugin\Sac\Reconcile::note('config', 'Computer', $cid, 0, 'bad_summary', $collected['value']);
                $run->bump('errors');
                continue;
            }

            $existing = $DB->request([
                'FROM'  => 'glpi_plugin_sac_configscan',
                'WHERE' => ['computers_id' => $cid],
            ])->current();
            $scanRow = [
                'computers_id'   => $cid,
                'benchmark_id'   => $parsed['benchmark_id'],
                'score'          => $parsed['score'],
                'pass_count'     => $parsed['pass'],
                'fail_count'     => $parsed['fail'],
                'other_count'    => $parsed['other'],
                'date_scan'      => $parsed['date_scan'],
                'date_collected' => $collected['date'],
                'raw_line'       => $collected['value'],
            ];
            if ($existing) {
                $DB->update('glpi_plugin_sac_configscan', $scanRow, ['id' => (int) $existing['id']]);
            } else {
                $DB->insert('glpi_plugin_sac_configscan', $scanRow);
            }

            $code = Code::configScore($cid);
            $sev  = Severity::forConfigScore($parsed['score'], $critical, $high, $medium);

            if ($sev === null) {
                FindingWriter::clearExcept($code, [], 'config score reached 100% on ' . date('Y-m-d'), $run, $scope);
                continue;
            }

            $stale = self::isStale($collected['date'], $staleDays);
            $content = sprintf(
                "Benchmark: %s\nScore: %.2f%%\nPass: %d  Fail: %d  Other: %d\nLast scan reported: %s%s",
                $parsed['benchmark_id'],
                $parsed['score'],
                $parsed['pass'],
                $parsed['fail'],
                $parsed['other'],
                $collected['date'] ?? __('unknown', 'sac'),
                $stale ? "\n\n[STALE — no fresher Collect report in over {$staleDays} days]" : ''
            );

            $entity = (int) $asset['entities_id'];
            $input = [
                'entities_id'    => $entity,
                'code'           => $code,
                'name'           => sprintf(__('Config compliance below threshold: %s', 'sac'), $asset['name']),
                'scope_label'    => sprintf('%s — %.1f%%', $asset['name'], $parsed['score']),
                'severity'       => $sev,
                'status'         => 'Open',
                'content'        => $content,
                'assessment_ref' => 'SAC/config',
                'plugin_riskregister_riskcategories_id' => $catId,
            ];

            FindingWriter::upsert($input, [$cid], '', $run);
        }
    }

    /** PURE. */
    private static function isStale(?string $collectedDate, int $staleDays): bool
    {
        if ($collectedDate === null || $staleDays <= 0) {
            return false;
        }
        $ts = strtotime($collectedDate);
        return $ts !== false && $ts < strtotime("-{$staleDays} days");
    }
```

Add a `use` of `Severity` and `FindingWriter` at the top of the file alongside
the existing ones (`Code`, `Config`, `RunLog` are already imported in Step 1's
block).

- [ ] **Step 4: Run to verify it passes**

Run: `php plugins/sac/tools/selfcheck.php configscan`
Expected: PASS (or SKIP with the Task 12 pointer, consistent with Task 7's
reader) for both checks.

- [ ] **Step 5: Commit**

```bash
git add src/Compliance/ConfigScan.php
git commit -m "feat(sac): add Tier 1 config-compliance scan orchestrator"
```

---

### Task 9: Cron wiring

**Files:**
- Modify: `src/Cron.php`

- [ ] **Step 1: Write the failing test** — add to `Cron::selfChecks()`, mirroring
  the existing `db/writer-riskregister-inactive` check:

```php
'db/configscan-riskregister-inactive' => static function (): void {
    if (Config::get('compliance_scan_enabled') === 0 || Config::get('compliance_scan_enabled') === '0') {
        return;
    }
    if (\GlpiPlugin\Sac\FindingWriter::isAvailable()) {
        return; // skip: riskregister active, covered by ConfigScan's own checks
    }
    global $DB;
    $floor = (int) ($DB->request(['SELECT' => ['MAX(id) AS m'], 'FROM' => 'glpi_plugin_sac_run'])->current()['m'] ?? 0);
    try {
        $rc = self::cronConfigScan(new \CronTask());
        sac_assert($rc === 0 || $rc === 1, 'cronConfigScan returned a valid code without throwing');

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_sac_run',
            'WHERE' => ['module' => 'config', 'id' => ['>', $floor]],
            'ORDER' => ['id DESC'],
        ])->current();
        sac_assert($row !== null, 'a _run row was opened for the tick');
        sac_assert_eq((string) $row['status'], 'ok', 'the _run row closed ok even with riskregister inactive');
    } finally {
        $DB->delete('glpi_plugin_sac_run', ['module' => 'config', 'id' => ['>', $floor]]);
    }
},
```

- [ ] **Step 2: Run to verify it fails**

Run: `php plugins/sac/tools/selfcheck.php configscan-riskregister-inactive`
Expected: FAIL — undefined method `cronConfigScan` (or SKIP if riskregister is
active on this VM, in which case temporarily deactivate it to exercise this
specific check, then reactivate)

- [ ] **Step 3: Implement**, in `src/Cron.php`:

add to `cronInfo()`'s `match`:

```php
            'ConfigScan' => ['description' => __('SAC — scan config-compliance Collect data', 'sac')],
```

add the method, alongside `cronLicenseScan()`:

```php
    public static function cronConfigScan(CronTask $task): int
    {
        if (!Config::get('compliance_scan_enabled')) { return 0; }
        RunLog::sweepStale();
        $run = RunLog::begin('config');
        try {
            \GlpiPlugin\Sac\Compliance\ConfigScan::run($run);
            $run->finish('ok');
            return 1;
        } catch (\Throwable $e) {
            $run->finish('error', $e->getMessage());
            \Toolbox::logInFile('sac', 'ConfigScan: ' . $e->getMessage() . "\n");
            return 0;
        }
    }
```

add to `register()`:

```php
        CronTask::register(self::class, 'ConfigScan', WEEK_TIMESTAMP, ['state' => CronTask::STATE_WAITING, 'mode' => CronTask::MODE_EXTERNAL]);
```

- [ ] **Step 4: Run to verify it passes**

Run: `php plugins/sac/tools/selfcheck.php configscan-riskregister-inactive`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Cron.php
git commit -m "feat(sac): register the weekly ConfigScan cron task"
```

---

### Task 10: `Menu` entry

**Files:**
- Modify: `src/Menu.php`

- [ ] **Step 1: Write the failing test** — update the existing
  `db/menu-entry-shape` check's expectation:

```php
sac_assert_eq(array_keys($entry['options']), ['overview', 'compliance', 'reconcile', 'runs'], 'option keys');
```

(replace the current `['overview', 'reconcile', 'runs']` line)

- [ ] **Step 2: Run to verify it fails**

Run: `php plugins/sac/tools/selfcheck.php menu-entry-shape`
Expected: FAIL — actual keys still `['overview', 'reconcile', 'runs']`

- [ ] **Step 3: Implement** — in `Menu::entry()`, add a `$compliance` URL
  variable alongside the existing three and insert its options entry between
  `overview` and `reconcile`:

```php
        $compliance = '/plugins/sac/front/compliance.php';
```

```php
                'compliance' => [
                    'title' => __('Config compliance', 'sac'),
                    'page'  => $compliance,
                    'icon'  => 'ti ti-shield-check',
                    'links' => ['search' => $compliance],
                ],
```

(placed immediately after the `'overview'` entry, before `'reconcile'`)

- [ ] **Step 4: Run to verify it passes**

Run: `php plugins/sac/tools/selfcheck.php menu-entry-shape`
Expected: PASS — including the loop that asserts each option's `page` file
exists, which is why Task 11 (creating `front/compliance.php`) must land
before this selfcheck can go green on the VM; offline it still can't run at
all (needs `Session`), so there's no ordering hazard for the offline suite.

- [ ] **Step 5: Commit**

```bash
git add src/Menu.php
git commit -m "feat(sac): add the config-compliance menu entry"
```

---

### Task 11: `front/compliance.php` + `templates/compliance.html.twig`

**Files:**
- Create: `front/compliance.php`
- Create: `templates/compliance.html.twig`

**Interfaces:**
- Consumes: `EntityScope::seesAll()` (existing — reuse exactly as `front/run.php`
  does), `Config::get('compliance_stale_days')`.

- [ ] **Step 1: Write the failing test** — this is a page, not a pure
  function; its "test" is the `db/menu-entry-shape` file-exists assertion from
  Task 10 Step 4, which currently fails because the file doesn't exist yet.
  Confirm that failure mode specifically:

Run: `php plugins/sac/tools/selfcheck.php menu-entry-shape`
Expected: FAIL — `compliance page exists` assertion fails (file not found)

- [ ] **Step 2: (same run as Step 1 — no separate repro needed for a page file)**

- [ ] **Step 3: Implement** — `front/compliance.php`:

```php
<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

/** @var \DBmysql $DB */
global $DB;

$seesAll   = \GlpiPlugin\Sac\EntityScope::seesAll();
$staleDays = (int) \GlpiPlugin\Sac\Config::get('compliance_stale_days');
$cutoff    = date('Y-m-d H:i:s', strtotime("-{$staleDays} days"));

$where = [];
if (!$seesAll) {
    $where = getEntitiesRestrictCriteria('glpi_computers', '', '', true);
}

$rows = [];
foreach ($DB->request([
    'SELECT'     => [
        'glpi_plugin_sac_configscan.*',
        'glpi_computers.name AS computer_name',
        'glpi_computers.entities_id AS entities_id',
    ],
    'FROM'       => 'glpi_plugin_sac_configscan',
    'INNER JOIN' => [
        'glpi_computers' => [
            'ON' => [
                'glpi_plugin_sac_configscan' => 'computers_id',
                'glpi_computers'             => 'id',
            ],
        ],
    ],
    'WHERE'      => [
        'glpi_computers.is_deleted'  => 0,
        'glpi_computers.is_template' => 0,
    ] + $where,
    'ORDER'      => ['glpi_plugin_sac_configscan.score ASC'],
]) as $row) {
    $rows[] = [
        'computer_name'  => (string) $row['computer_name'],
        'benchmark_id'   => (string) $row['benchmark_id'],
        'score'          => (float) $row['score'],
        'pass_count'     => (int) $row['pass_count'],
        'fail_count'     => (int) $row['fail_count'],
        'other_count'    => (int) $row['other_count'],
        'date_collected' => $row['date_collected'],
        'is_stale'       => $row['date_collected'] !== null && $row['date_collected'] < $cutoff,
    ];
}

Html::header(__('Security Assessment', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'compliance');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'compliance');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/compliance.html.twig', [
    'rows'       => $rows,
    'stale_days' => $staleDays,
]);

Html::footer();
```

`templates/compliance.html.twig`:

```twig
{#
 # SAC — config-compliance (OpenSCAP Tier 1) per-host score list.
 # Autoescape ON — no |raw on plugin data.
 # `rows` = [{computer_name, benchmark_id, score, pass_count, fail_count,
 #           other_count, date_collected, is_stale}], ascending by score.
 #}
<div class="card">
    <div class="card-body">
    <div class="alert alert-secondary">
        {{ __('Tier 1: one score per host from the OpenSCAP summary GLPI Agent\'s Collect task reports. Per-rule results are not imported in this phase.', 'sac') }}
    </div>
    {% if rows is empty %}
        <div class="text-center text-muted">{{ __('No config-compliance data collected yet', 'sac') }}</div>
    {% else %}
        <table class="table table-hover">
            <thead>
                <tr>
                    <th data-sortable>{{ __('Host', 'sac') }}</th>
                    <th data-sortable>{{ __('Benchmark', 'sac') }}</th>
                    <th data-sortable>{{ __('Score', 'sac') }}</th>
                    <th class="text-end">{{ __('Pass', 'sac') }}</th>
                    <th class="text-end">{{ __('Fail', 'sac') }}</th>
                    <th class="text-end">{{ __('Other', 'sac') }}</th>
                    <th data-sortable>{{ __('Last reported', 'sac') }}</th>
                </tr>
            </thead>
            <tbody>
            {% for r in rows %}
                {% set sc_class = r.score < 50 ? 'text-danger fw-bold' : (r.score < 85 ? 'text-warning fw-bold' : 'text-success') %}
                <tr>
                    <td>{{ r.computer_name }}</td>
                    <td class="text-muted small">{{ r.benchmark_id }}</td>
                    <td data-sort="{{ r.score }}" class="{{ sc_class }}">{{ r.score }}%</td>
                    <td class="text-end">{{ r.pass_count }}</td>
                    <td class="text-end">{{ r.fail_count }}</td>
                    <td class="text-end">{{ r.other_count }}</td>
                    <td>
                        {{ r.date_collected ?: __('Never', 'sac') }}
                        {% if r.is_stale %}
                            <span class="badge bg-secondary text-white ms-1">{{ __('Stale', 'sac') }}</span>
                        {% endif %}
                    </td>
                </tr>
            {% endfor %}
            </tbody>
        </table>
        {% include '@sac/_sortable-table.html.twig' %}
    {% endif %}
    </div>
</div>
```

- [ ] **Step 4: Run to verify it passes**

Run: `php plugins/sac/tools/selfcheck.php menu-entry-shape`
Expected: PASS — the page file now exists, so the `page exists` loop assertion
succeeds for every option including `compliance`.

Manual confirmation (not scriptable offline): load `front/compliance.php` in a
browser against VM-SERVER-22 as a user with `plugin_sac_finding` READ and confirm
the page renders with no PHP warnings, both with zero rows and with at least
one seeded `glpi_plugin_sac_configscan` row.

- [ ] **Step 5: Commit**

```bash
git add front/compliance.php templates/compliance.html.twig
git commit -m "feat(sac): add the config-compliance list page"
```

---

### Task 12: VM fact-finding — confirm the GLPI Inventory Collect schema

**Not a code task** — a manual reconnaissance step the user runs against
VM-SERVER-22, because `CollectReader` (Task 7) was written against a documented
guess (Global Constraints). This is the same category of step the project
already went through for Module 1's `fields` LifeCycle table name
(project-brief §6.1.2.a).

- [ ] **Step 1:** On the VM, as a user who can run `mysql` against the GLPI
  database (schema name `itsm`, not `glpi` — project-brief §8 gotcha), run:

```sql
SHOW TABLES LIKE '%glpiinventory%collect%';
```

- [ ] **Step 2:** For whichever table(s) that returns, run:

```sql
SHOW COLUMNS FROM <table_name>;
```

for each, and report back:
- the table that holds Collect task **definitions** (expected to have a `name`
  column — this is `self::COLLECTS_TABLE` in `CollectReader`)
- the table that holds **per-computer reported values** (expected to have
  `computers_id` and a value column — this is `self::VALUES_TABLE`)
- the exact column names for: the computer FK, the value, the FK back to the
  definition table, and whether a per-row report date/timestamp column exists

- [ ] **Step 3:** If any name differs from `CollectReader`'s current constants
  (`glpi_plugin_glpiinventory_collects` / `glpi_plugin_glpiinventory_collects_contents`)
  or column names (`name`, `computers_id`, `value`, `plugin_glpiinventory_collects_id`,
  `date`), update the constants/column references in `src/Compliance/CollectReader.php`
  to match, then re-run `php plugins/sac/tools/selfcheck.php collectreader` and
  `php plugins/sac/tools/selfcheck.php configscan` — both `db/collectreader-roundtrip`
  and the `db/configscan-*` checks should go from SKIP to PASS.

- [ ] **Step 4:** Separately confirm the **host side** is set up to produce
  data at all: a Collect task of type `command` exists under Setup, named to
  match `compliance_collect_name` (default `sac_oscap_summary`), running a
  wrapper script that executes `oscap xccdf eval` and prints exactly one line
  in the `ScoreLine` format (Task 3's docblock) to stdout. Writing that host-side
  wrapper script and its deployment (cron on each monitored host) is **out of
  scope for this plan** — it is operations/infra work for the user and/or a
  follow-up plan, not plugin code.

- [ ] **Step 5:** Log the outcome to the suite hub (`_pgn-glpi-suite.md` §7):
  confirmed table/column names (or "guess was correct"), whether the host-side
  Collect task is already defined, and the version number this phase ships as.
  No code commit for this task — Step 3's fix (if needed) was already committed
  there.

---

## Self-Review Notes

- **Spec coverage:** every Tier 1 bullet in project-brief §114 is covered:
  per-host score ingestion (Tasks 7-8), Finding emission at threshold (Task 8),
  dashboard (Task 11). Explicitly NOT covered (by design, per Global
  Constraints): per-rule checklist, OVAL patch detail, POA&M — all Tier 2/
  riskregister-owned, deferred.
- **Placeholder scan:** none — every step has real code; Task 12 is
  deliberately a non-code recon task with an exact SQL deliverable, not a
  disguised TBD.
- **Type consistency:** `ScoreLine::parse()`'s return shape
  (`benchmark_id/score/pass/fail/other/date_scan`) is used identically in
  Task 8; `CollectReader::latestFor()`'s `{value,date}` shape is used
  identically in Task 8 and its own Task 7 test.
- **Review Focus:** all five items have an owning task and an assertion (see
  the Review Focus section above for the task mapping).
