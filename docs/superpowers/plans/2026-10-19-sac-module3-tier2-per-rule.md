# SAC 1.8.0 — Module 3 Tier 2 (per-rule SCAP results) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn "49.17%, 55 fail" into an actionable list — store each failing SCAP
rule per host, close it automatically when a later scan shows it fixed, reopen it
if it regresses, and show the checklist on a per-host page.

**Architecture:** Design A, chosen over fetching the full `results.xml`: the host's
oscap wrapper appends one `SACRULE1|…` line per failing rule to the **same file**
SAC already pulls, so **no host's `authorized_keys` changes** — the forced command
stays `cat <one file>`. The plugin parses flat text (no gzip, no XMLReader, no XXE
surface), persists rules in its own `glpi_plugin_sac_configrules`, and — when
riskregister 2.1.0 is present — pushes them as Finding affected-areas through
`upsertAffected()`.

**Tech Stack:** PHP 8.4, GLPI 11 plugin APIs, MySQL via `$DB`; host side is bash +
python3 (already required by the existing wrapper).

**Spec:** No separate spec document. This plan argues from: `docs/project-brief.md`
§3 (Module 3) and §12 (Tier 2 deferral, now resolved), and the suite hub
`_pgn-glpi-suite.md` §7 entries of 2026-10-14/15 — SAC's own "Collect cannot
capture command output" entry, riskregister's `upsertAffected()` contract entry,
and its `framework_refs` amendment. Those three §7 entries are the binding
agreement; where this plan and they disagree, they win.

## Global Constraints

- **One Finding per host, never one per rule** (user decision, hub §7 2026-10-15).
  55 Findings per host would drown the register.
- **Store `fail` and `error` only.** The 753 not-applicable/not-checked results on
  the reference host are noise for mitigation.
- **SAC never stores a human-set state.** `is_manual_state` lives on riskregister's
  row; `upsertAffected()` leaves a human's answer alone. SAC sends scan-derived
  state only (`Open` while failing, `Resolved` once a scan stops reporting it).
- **Absence never means fixed.** A host that is unreachable, or whose oscap cron did
  not run, yields an empty rule list. Only a scan that actually produced a summary
  line may resolve anything.
- **Framework refs pass through verbatim.** Send `CISv8:4.1` exactly as the content
  states it, normalised only to `shortname:code`. Never map or re-interpret — a
  mismatch must surface in riskregister's `refs_unmatched`, not be smoothed over.
- **Omitting a field is not the same as sending it empty** (riskregister contract
  change, hub §7 2026-10-19). An omitted key leaves the stored value alone; `''`
  erases it. SAC therefore never sends `description` (a human's remediation note
  lives there) and omits `framework_refs` entirely when the content produced none.
- **riskregister 2.1.0-rc1 is built but NOT deployed.** Code against the contract,
  keep the `method_exists()` guard, and do not assume the method exists at runtime
  until the user confirms it on VM-HQ-GLPI.
- **No `authorized_keys` change on any host.** If a task seems to need one, stop:
  that is Design B, which was explicitly rejected.
- Existing suite rules still bind: `timestamp NULL DEFAULT NULL`, `'fkey'` (never
  `'integer'`) for FK-shaped columns, Twig autoescaped with no `|raw` on plugin
  data, `cache:clear` after any `.twig` change, and §0 rule 8 (ship as
  `1.8.0-rc1`, promote only after the user confirms on VM-HQ-GLPI).

## Review Focus

1. **A truncated or partial payload must not resolve anything.** If the SSH pull
   returns the summary line but the rule lines are cut off mid-transfer, the naive
   reading is "0 rules failing = host fixed", silently closing every open rule.
   Pinned in Task 6 (`RuleStore::sync()` is only ever called with rules parsed from
   a payload whose summary line parsed first) and Task 3's explicit test.
2. **A rule title containing `|`** must not shift every later field. Pinned in Task 2
   (`explode` with a field limit so the title keeps its pipes).
3. **A rule that disappears from the benchmark entirely** (SSG version bump retires
   it) is not "Resolved" — it should stop being asserted without claiming it was
   fixed. Pinned in Task 5.
4. **Reopen must work.** A rule resolved last month that fails again must return to
   Open with its original first-seen date intact, or a recurring regression looks
   like a brand-new finding each time. Pinned in Task 5.
5. **riskregister absent or older than 2.1.0** must not fatal the scan —
   `upsertAffected()` will not exist. Pinned in Task 7 (`method_exists` guard,
   mirroring `FindingWriter::isAvailable()`).

---

## File Structure

- Create: `src/Compliance/RuleLine.php` — pure `SACRULE1|…` parser.
- Create: `src/Compliance/SummaryPayload.php` — pure split of the multi-line pull.
- Create: `src/Compliance/RuleStore.php` — persistence + open/resolved/reopen lifecycle.
- Create: `src/Compliance/FindingAffected.php` — guarded bridge to `upsertAffected()`.
- Create: `front/compliancedetail.php`, `templates/compliancedetail.html.twig` — per-host checklist.
- Modify: `src/Compliance/ConfigScan.php` — consume the payload, persist rules, summarise into the Finding.
- Modify: `src/Migration.php` — `glpi_plugin_sac_configrules`.
- Modify: `src/Config.php` — `compliance_stale_days` default 45.
- Modify: `src/Cron.php` — `MONTH_TIMESTAMP`.
- Modify: `templates/compliance.html.twig` — link each host to its detail page.
- Modify: `tools/host-agent/sac-oscap-summary.sh` — emit the rule lines.

## Interfaces summary

- `SummaryPayload::split(string $raw): array{summary:?string,rules:array<int,string>}`
- `RuleLine::parse(string $line): ?array{rule_id:string,result:string,severity:string,framework_refs:string,title:string}`
- `RuleStore::sync(int $computerId, array $rules, string $dateScan): array{open:int,resolved:int,reopened:int}`
- `RuleStore::openFor(int $computerId): array<int,array<string,mixed>>`
- `FindingAffected::isAvailable(): bool`
- `FindingAffected::push(int $findingId, array $rules): ?array`

---

### Task 1: VM recon — confirm the `<reference>` shape in the SSG content

**Not a code task.** The CIS-reference parsing in Task 10 is written against what
this returns. Guessing a data shape is exactly what cost this project v1.7.0.

- [ ] **Step 1:** On a monitored host, list the distinct reference shapes:

```bash
grep -o '<reference href="[^"]*"[^>]*>[^<]*</reference>' \
  /usr/share/xml/scap/ssg/content/ssg-ol9-ds.xml | sort -u | head -30
```

- [ ] **Step 2:** Confirm whether CIS references are present at all, and under which
  href:

```bash
grep -o 'href="[^"]*"' /usr/share/xml/scap/ssg/content/ssg-ol9-ds.xml \
  | sort | uniq -c | sort -rn | head -20
```

- [x] **ANSWER — recon run on `vm-dev-damara` (Oracle Linux 9), 2026-10-22.**

  The content dir holds two datastreams, `ssg-firefox-ds.xml` and
  `ssg-ol9-ds.xml` (16 MB, dated Sep 2023). The wrapper's product-exclusion
  list correctly selects the ol9 one, so the hardcoded `ssg-ol9-ds.xml` in the
  commands above happens to be right on THIS host — but auto-detect is what
  actually decides, and a host with different content would diverge.

  - **Element shape:** `<xccdf-1.2:reference href="...">CODE</xccdf-1.2:reference>`
  - **Namespace URI:** `http://checklists.nist.gov/xccdf/1.2` (prefix `xccdf-1.2:`).
    Task 10's `ns` map already uses this URI, so it needs no change — ElementTree
    resolves by URI, not by prefix.
  - **`CIS_HREF` = `cisecurity.org/controls/`** (full value
    `https://www.cisecurity.org/controls/`), 3654 occurrences.
  - **Code format: bare integers. No dotted sub-controls anywhere.** The full set
    of distinct codes present is `1 2 3 4 5 6 7 8 9 11 12 13 14 15 16 18 19 20`
    (10 and 17 are absent — no rule references them). The project note about CIS
    sub-control refs truncating to a parent number (`5.2` -> `5`) therefore does
    NOT apply to this content: nothing needs truncating.
  - **PCI DSS references are excluded for free.** They appear as
    `<xccdf-1.2:reference>https://www.pcisecuritystandards.org/...</...>` with
    **no `href` attribute at all**, so selecting on the href substring drops them.
    (Beware a naive text search for `cisecurity`: it also matches
    `p-cisecurity-standards`.)

  - ⚠️ **BLOCKING FINDING — this content references CIS Controls v7, not v8.**
    The codes run to 20; CIS v8 has only 18 controls, v7 has 20. Emitting these
    as `CISv8:<n>`, as Task 10 Step 2 currently does, mislabels them. riskregister's
    `cis_v8.csv` holds controls 1-18, so `19`/`20` would match nothing, and 1-18
    would match the WRONG control, because v8 reorganised v7 rather than trimming
    it. A silently wrong control reference is worse than none.
    **Task 10 must not use the `CISv8:` prefix until this is resolved.** Options:
    emit `CISv7:<n>` and let riskregister decide whether it can map v7, or emit a
    neutral `CIS:<n>`, or send nothing. Needs the riskregister session's input,
    since it owns the framework tables.

- [ ] **Step 3 (original instruction):** Record the answer here, in this task, before starting Task 10:
  the href substring that identifies CIS, and the exact code format (`4.1` vs
  `CIS-4.1` vs `4`). If the content carries **no** CIS references (plausible — this
  is the `standard` profile on Oracle Linux, which has no CIS profile at all), say
  so: Task 10 then emits an empty `framework_refs` field and Task 7 sends nothing,
  which is a valid outcome and not a defect.

---

### Task 2: `RuleLine` — pure parser for one `SACRULE1` line

**Files:** Create `src/Compliance/RuleLine.php`

**Format** (decided here; Task 10's host script must emit exactly this):

```
SACRULE1|<rule_id>|<result>|<severity>|<framework_refs>|<title>
```

`framework_refs` may be empty. `title` is last **on purpose** so a title containing
`|` cannot shift the other fields.

- [ ] **Step 1: Write the failing test** — new file, with `parse()` stubbed to `return null;`:

```php
<?php

namespace GlpiPlugin\Sac\Compliance;

final class RuleLine
{
    public static function selfChecks(): array
    {
        return [
            'ruleline/parses-well-formed' => static function (): void {
                $r = self::parse('SACRULE1|xccdf_org.ssgproject.content_rule_sshd_disable_root_login|fail|high|CISv8:4.1,CISv8:5.4|Disable SSH root login');
                sac_assert($r !== null, 'well-formed line parses');
                sac_assert_eq($r['rule_id'], 'xccdf_org.ssgproject.content_rule_sshd_disable_root_login', 'rule_id');
                sac_assert_eq($r['result'], 'fail', 'result');
                sac_assert_eq($r['severity'], 'high', 'severity');
                sac_assert_eq($r['framework_refs'], 'CISv8:4.1,CISv8:5.4', 'framework refs kept verbatim');
                sac_assert_eq($r['title'], 'Disable SSH root login', 'title');
            },
            'ruleline/title-may-contain-pipes' => static function (): void {
                $r = self::parse('SACRULE1|rule_x|fail|low||Ensure A | B is set');
                sac_assert($r !== null, 'parses');
                sac_assert_eq($r['title'], 'Ensure A | B is set', 'a pipe in the title does not shift fields');
                sac_assert_eq($r['framework_refs'], '', 'empty refs field is allowed');
            },
            'ruleline/rejects-malformed' => static function (): void {
                sac_assert(self::parse('') === null, 'empty');
                sac_assert(self::parse('SACCFG1|bm|50|1|2|3|2026-10-14T00:00:00Z') === null, 'a summary line is not a rule line');
                sac_assert(self::parse('SACRULE1|rule_x|fail|low') === null, 'too few fields');
                sac_assert(self::parse('SACRULE1||fail|low||t') === null, 'blank rule id');
                sac_assert(self::parse('SACRULE1|rule_x|pass|low||t') === null, 'only fail/error are stored');
                sac_assert(self::parse('SACRULE2|rule_x|fail|low||t') === null, 'wrong tag');
            },
            'ruleline/normalises-severity' => static function (): void {
                sac_assert_eq(self::parse('SACRULE1|r|fail|HIGH||t')['severity'], 'high', 'severity lowercased');
                sac_assert_eq(self::parse('SACRULE1|r|fail|||t')['severity'], 'unknown', 'missing severity becomes unknown');
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

Run: `"/c/php/php.exe" tools/offline-selfcheck.php ruleline/`
Expected: FAIL on `ruleline/parses-well-formed` ("well-formed line parses")

- [ ] **Step 3: Implement** — replace the stub:

```php
    private const TAG = 'SACRULE1';

    /** Results worth storing — everything else is noise for mitigation. */
    private const KEPT_RESULTS = ['fail', 'error'];

    /**
     * PURE. Parses one rule line. Returns null on ANY malformation, and on a
     * result this module does not store.
     *
     * @return array{rule_id:string,result:string,severity:string,framework_refs:string,title:string}|null
     */
    public static function parse(string $line): ?array
    {
        // Limit 6: the title is last and may legitimately contain '|'.
        $parts = explode('|', trim($line), 6);
        if (count($parts) !== 6 || $parts[0] !== self::TAG) {
            return null;
        }
        [, $ruleId, $result, $severity, $refs, $title] = $parts;

        $ruleId = trim($ruleId);
        $result = strtolower(trim($result));
        if ($ruleId === '' || !in_array($result, self::KEPT_RESULTS, true)) {
            return null;
        }

        $severity = strtolower(trim($severity));
        if ($severity === '') {
            $severity = 'unknown';
        }

        return [
            'rule_id'         => $ruleId,
            'result'          => $result,
            'severity'        => $severity,
            'framework_refs'  => trim($refs),
            'title'           => trim($title),
        ];
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php ruleline/`
Expected: PASS (all four checks)

- [ ] **Step 5: Commit**

```bash
git add src/Compliance/RuleLine.php
git commit -m "feat(sac): add per-rule SCAP result line parser"
```

---

### Task 3: `SummaryPayload` — split the multi-line pull

**Files:** Create `src/Compliance/SummaryPayload.php`

**Interfaces:**
- Produces: `SummaryPayload::split(string $raw): array{summary:?string,rules:array<int,string>}`

This is where Review Focus #1 is enforced: `summary` is null unless the FIRST
non-empty line is a summary line, and a caller must treat a null summary as "this
pull told us nothing", never as "no rules are failing".

- [ ] **Step 1: Write the failing test** — new file, `split()` stubbed to `return ['summary' => null, 'rules' => []];`:

```php
'payload/splits-summary-and-rules' => static function (): void {
    $raw = "SACCFG1|bm|49.17|22|55|753|2026-10-14T11:55:14+0700\n"
         . "SACRULE1|rule_a|fail|high||Title A\n"
         . "SACRULE1|rule_b|error|low||Title B\n";
    $out = self::split($raw);
    sac_assert($out['summary'] !== null, 'summary line found');
    sac_assert(str_starts_with($out['summary'], 'SACCFG1|'), 'summary is the first line');
    sac_assert_eq(count($out['rules']), 2, 'both rule lines kept');
},
'payload/summary-only-is-valid' => static function (): void {
    $out = self::split("SACCFG1|bm|100.00|30|0|0|2026-10-14T00:00:00Z\n");
    sac_assert($out['summary'] !== null, 'a host still running the old wrapper is not an error');
    sac_assert_eq($out['rules'], [], 'no rule lines');
},
'payload/no-summary-means-nothing-usable' => static function (): void {
    // Review Focus #1: a truncated pull that lost its first line must NOT
    // read as "zero rules failing".
    $out = self::split("SACRULE1|rule_a|fail|high||Title A\n");
    sac_assert($out['summary'] === null, 'no summary line = unusable payload');
    sac_assert_eq($out['rules'], [], 'rules are discarded with it, never applied on their own');
},
'payload/ignores-blank-and-foreign-lines' => static function (): void {
    $raw = "\nSACCFG1|bm|49.17|22|55|753|2026-10-14T11:55:14+0700\n\nsome banner text\nSACRULE1|rule_a|fail|high||A\n";
    $out = self::split($raw);
    sac_assert($out['summary'] !== null, 'leading blank line tolerated');
    sac_assert_eq(count($out['rules']), 1, 'foreign lines ignored, rule lines kept');
},
```

- [ ] **Step 2: Run to verify it fails**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php payload/`
Expected: FAIL on `payload/splits-summary-and-rules`

- [ ] **Step 3: Implement:**

```php
<?php

namespace GlpiPlugin\Sac\Compliance;

/**
 * Splits one SSH pull into its summary line and its rule lines.
 *
 * A payload whose first usable line is NOT a summary line yields
 * `summary => null` AND an empty rule list, deliberately: a pull truncated
 * mid-transfer would otherwise present as "the host has zero failing rules",
 * which a caller would act on by resolving every open rule it has.
 */
final class SummaryPayload
{
    public static function split(string $raw): array
    {
        $summary = null;
        $rules   = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($summary === null) {
                if (str_starts_with($line, 'SACCFG1|')) {
                    $summary = $line;
                }
                // Anything before the summary line is banner/noise and is
                // skipped; rule lines seen before it are skipped too, since
                // without a summary nothing may be applied.
                continue;
            }
            if (str_starts_with($line, 'SACRULE1|')) {
                $rules[] = $line;
            }
        }

        return $summary === null
            ? ['summary' => null, 'rules' => []]
            : ['summary' => $summary, 'rules' => $rules];
    }
}
```

(plus the `selfChecks()` block from Step 1)

- [ ] **Step 4: Run to verify it passes**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php payload/`
Expected: PASS (all four)

- [ ] **Step 5: Commit**

```bash
git add src/Compliance/SummaryPayload.php
git commit -m "feat(sac): split the SSH payload into summary and rule lines"
```

---

### Task 4: `glpi_plugin_sac_configrules` table

**Files:** Modify `src/Migration.php`

- [ ] **Step 1: Write the failing test** — add to `Migration::selfChecks()`:

```php
'db/migration-configrules-table' => static function (): void {
    global $DB;
    $t = 'glpi_plugin_sac_configrules';
    sac_assert($DB->tableExists($t), 'table created by install()');
    foreach (['computers_id', 'rule_id', 'result', 'severity', 'framework_refs', 'title', 'state', 'date_first_seen', 'date_last_seen', 'date_resolved'] as $col) {
        sac_assert($DB->fieldExists($t, $col), "$col column");
    }
    sac_assert(self::keyExists($DB, $t, 'computer_rule'), 'UNIQUE key on (computers_id, rule_id)');
},
```

- [ ] **Step 2: Run to verify it fails**

Run: `php plugins/sac/tools/selfcheck.php migration-configrules`
Expected: FAIL — table does not exist

- [ ] **Step 3: Implement** — add to `Migration::define()`:

```php
        self::$tables['glpi_plugin_sac_configrules'] = "
            CREATE TABLE `glpi_plugin_sac_configrules` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `computers_id` int unsigned NOT NULL DEFAULT 0,
              `rule_id` varchar(255) NOT NULL DEFAULT '',
              `result` varchar(20) NOT NULL DEFAULT '',
              `severity` varchar(20) NOT NULL DEFAULT 'unknown',
              `framework_refs` varchar(255) NOT NULL DEFAULT '',
              `title` text,
              `state` varchar(20) NOT NULL DEFAULT 'open',
              `date_first_seen` timestamp NULL DEFAULT NULL,
              `date_last_seen` timestamp NULL DEFAULT NULL,
              `date_resolved` timestamp NULL DEFAULT NULL,
              `date_creation` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `computer_rule` (`computers_id`,`rule_id`),
              KEY `computer_state` (`computers_id`,`state`)
            ) $table_options";
```

`rule_id` is a SCAP id (long, never empty), so `(computers_id, rule_id)` is a safe
UNIQUE — unlike the polymorphic `(findings_id, itemtype, items_id)` shape
riskregister warned about, which silently permits only one untargeted row.

- [ ] **Step 4: Run to verify it passes**

Run: `php plugins/sac/tools/selfcheck.php migration-configrules`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Migration.php
git commit -m "feat(sac): add glpi_plugin_sac_configrules table"
```

---

### Task 5: `RuleStore` — persistence and the open/resolved/reopen lifecycle

**Files:** Create `src/Compliance/RuleStore.php`

**Interfaces:**
- Consumes: `RuleLine::parse()` output shape (Task 2)
- Produces: `RuleStore::sync(int $computerId, array $rules, string $dateScan): array{open:int,resolved:int,reopened:int}`, `RuleStore::openFor(int $computerId): array`

**Lifecycle, exactly:**
- rule present in `$rules`, no row → insert `state=open`, both dates = `$dateScan`
- rule present, row `open` → update fields, `date_last_seen=$dateScan`
- rule present, row `resolved` → **reopen**: `state=open`, `date_resolved=NULL`,
  `date_last_seen=$dateScan`, **`date_first_seen` untouched** (Review Focus #4)
- rule absent from `$rules`, row `open` → `state=resolved`, `date_resolved=$dateScan`
- rule absent, row already `resolved` → untouched

- [ ] **Step 1: Write the failing test** — new file with `sync()`/`openFor()` stubbed, plus:

```php
'db/rulestore-lifecycle' => static function (): void {
    global $DB;
    $cid = (int) (new \Computer())->add(['name' => '__sac_rs_host__', 'entities_id' => 0]);
    $mk = static fn (string $id, string $sev = 'high'): array => [
        'rule_id' => $id, 'result' => 'fail', 'severity' => $sev,
        'framework_refs' => '', 'title' => 'T ' . $id,
    ];
    try {
        // First scan: two failing rules.
        $r1 = self::sync($cid, [$mk('rule_a'), $mk('rule_b')], '2026-10-01 02:00:00');
        sac_assert_eq($r1['open'], 2, 'two rules opened');
        sac_assert_eq(count(self::openFor($cid)), 2, 'both listed as open');

        // Second scan: rule_a fixed, rule_b still failing.
        $r2 = self::sync($cid, [$mk('rule_b')], '2026-11-01 02:00:00');
        sac_assert_eq($r2['resolved'], 1, 'the rule that stopped failing resolved');
        $open = array_column(self::openFor($cid), 'rule_id');
        sac_assert_eq($open, ['rule_b'], 'only the still-failing rule remains open');

        $a = $DB->request(['FROM' => 'glpi_plugin_sac_configrules', 'WHERE' => ['computers_id' => $cid, 'rule_id' => 'rule_a']])->current();
        sac_assert_eq((string) $a['state'], 'resolved', 'rule_a resolved');
        sac_assert($a['date_resolved'] !== null, 'resolved date stamped');
        $firstSeenA = (string) $a['date_first_seen'];

        // Third scan: rule_a regresses.
        $r3 = self::sync($cid, [$mk('rule_a'), $mk('rule_b')], '2026-12-01 02:00:00');
        sac_assert_eq($r3['reopened'], 1, 'the regressed rule reopened');
        $a2 = $DB->request(['FROM' => 'glpi_plugin_sac_configrules', 'WHERE' => ['computers_id' => $cid, 'rule_id' => 'rule_a']])->current();
        sac_assert_eq((string) $a2['state'], 'open', 'reopened');
        sac_assert($a2['date_resolved'] === null, 'resolved date cleared on reopen');
        sac_assert_eq((string) $a2['date_first_seen'], $firstSeenA, 'first-seen preserved — a recurring regression is not a brand-new finding');
    } finally {
        $DB->delete('glpi_plugin_sac_configrules', ['computers_id' => $cid]);
        (new \Computer())->delete(['id' => $cid], true);
    }
},
'db/rulestore-empty-list-resolves-nothing-by-accident' => static function (): void {
    // Review Focus #1/#3: callers must never hand sync() an empty list from a
    // failed pull, but if they do, this records what it actually does so the
    // behaviour is a decision rather than an accident.
    global $DB;
    $cid = (int) (new \Computer())->add(['name' => '__sac_rs_empty__', 'entities_id' => 0]);
    try {
        self::sync($cid, [['rule_id' => 'r1', 'result' => 'fail', 'severity' => 'low', 'framework_refs' => '', 'title' => 't']], '2026-10-01 02:00:00');
        $out = self::sync($cid, [], '2026-11-01 02:00:00');
        sac_assert_eq($out['resolved'], 1, 'an empty list DOES resolve open rules — which is why ConfigScan must only call sync() for a payload whose summary line parsed');
    } finally {
        $DB->delete('glpi_plugin_sac_configrules', ['computers_id' => $cid]);
        (new \Computer())->delete(['id' => $cid], true);
    }
},
```

- [ ] **Step 2: Run to verify it fails**

Run: `php plugins/sac/tools/selfcheck.php rulestore`
Expected: FAIL — stub returns zero counts

- [ ] **Step 3: Implement:**

```php
    /**
     * @param array<int,array{rule_id:string,result:string,severity:string,framework_refs:string,title:string}> $rules
     * @return array{open:int,resolved:int,reopened:int}
     */
    public static function sync(int $computerId, array $rules, string $dateScan): array
    {
        global $DB;
        $table = 'glpi_plugin_sac_configrules';

        $existing = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => ['computers_id' => $computerId]]) as $row) {
            $existing[(string) $row['rule_id']] = $row;
        }

        $counts = ['open' => 0, 'resolved' => 0, 'reopened' => 0];
        $seen   = [];

        foreach ($rules as $rule) {
            $ruleId      = $rule['rule_id'];
            $seen[$ruleId] = true;
            $common = [
                'result'         => $rule['result'],
                'severity'       => $rule['severity'],
                'framework_refs' => $rule['framework_refs'],
                'title'          => $rule['title'],
                'date_last_seen' => $dateScan,
            ];

            if (!isset($existing[$ruleId])) {
                $DB->insert($table, $common + [
                    'computers_id'    => $computerId,
                    'rule_id'         => $ruleId,
                    'state'           => 'open',
                    'date_first_seen' => $dateScan,
                ]);
                $counts['open']++;
                continue;
            }

            $row = $existing[$ruleId];
            if ((string) $row['state'] === 'resolved') {
                // Reopen. date_first_seen is deliberately NOT reset: a rule
                // that keeps coming back should read as one long-running
                // problem, not a new one each time.
                $DB->update($table, $common + ['state' => 'open', 'date_resolved' => null], ['id' => (int) $row['id']]);
                $counts['reopened']++;
            } else {
                $DB->update($table, $common, ['id' => (int) $row['id']]);
                $counts['open']++;
            }
        }

        foreach ($existing as $ruleId => $row) {
            if (isset($seen[$ruleId]) || (string) $row['state'] === 'resolved') {
                continue;
            }
            $DB->update($table, ['state' => 'resolved', 'date_resolved' => $dateScan], ['id' => (int) $row['id']]);
            $counts['resolved']++;
        }

        return $counts;
    }

    /** @return array<int,array<string,mixed>> open rules, worst severity first */
    public static function openFor(int $computerId): array
    {
        global $DB;
        $order = ['high' => 0, 'medium' => 1, 'low' => 2, 'unknown' => 3];
        $out   = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_sac_configrules',
            'WHERE' => ['computers_id' => $computerId, 'state' => 'open'],
        ]) as $row) {
            $out[] = (array) $row;
        }
        usort($out, static fn (array $a, array $b): int =>
            ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9)
            ?: strcmp((string) $a['rule_id'], (string) $b['rule_id']));
        return $out;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php plugins/sac/tools/selfcheck.php rulestore`
Expected: PASS (both checks)

- [ ] **Step 5: Commit**

```bash
git add src/Compliance/RuleStore.php
git commit -m "feat(sac): add per-rule store with open/resolved/reopen lifecycle"
```

---

### Task 6: Wire the rules into `ConfigScan`

**Files:** Modify `src/Compliance/ConfigScan.php`

**Interfaces:** Consumes Tasks 2, 3, 5.

- [ ] **Step 1: Write the failing test** — add to `ConfigScan::selfChecks()`:

```php
'db/configscan-processtarget-persists-rules' => static function (): void {
    global $DB;
    $cid = (int) (new \Computer())->add(['name' => '__sac_pt_rules__', 'entities_id' => 0]);
    $target = ['computers_id' => $cid, 'entities_id' => 0, 'name' => '__sac_pt_rules__'];
    $catId = FindingWriter::categoryId('Configuration Compliance', 0);
    try {
        $payload = "SACCFG1|bm|49.17|22|55|753|2026-10-14T11:55:14+0700\n"
                 . "SACRULE1|rule_a|fail|high|CISv8:4.1|Disable root login\n"
                 . "SACRULE1|rule_b|fail|low||Set umask\n";
        $run = RunLog::begin('selftest');
        self::processTarget($target, $payload, $catId, null, $run);
        $run->finish('ok');

        $rows = $DB->request(['FROM' => 'glpi_plugin_sac_configrules', 'WHERE' => ['computers_id' => $cid]]);
        sac_assert_eq(count(iterator_to_array($rows)), 2, 'both rules persisted');
    } finally {
        $DB->delete('glpi_plugin_sac_configrules', ['computers_id' => $cid]);
        $DB->delete('glpi_plugin_sac_configscan', ['computers_id' => $cid]);
        (new \Computer())->delete(['id' => $cid], true);
    }
},
```

- [ ] **Step 2: Run to verify it fails**

Run: `php plugins/sac/tools/selfcheck.php configscan-processtarget-persists-rules`
Expected: FAIL — 0 rows, `processTarget()` does not parse rule lines yet

- [ ] **Step 3: Implement** — in `processTarget()`, replace the direct
  `ScoreLine::parse($value)` call with the payload split, and persist the rules
  after the `_configscan` upsert:

```php
        $payload = SummaryPayload::split($value);
        if ($payload['summary'] === null) {
            // Nothing usable in this pull — never treated as "no rules
            // failing", which would resolve every open rule on this host.
            Reconcile::note('config', 'Computer', $cid, 0, 'bad_summary', $value);
            $run->bump('errors');
            return;
        }

        $parsed = ScoreLine::parse($payload['summary']);
        if ($parsed === null) {
            Reconcile::note('config', 'Computer', $cid, 0, 'bad_summary', $payload['summary']);
            $run->bump('errors');
            return;
        }
```

then, after the `glpi_plugin_sac_configscan` insert/update:

```php
        $rules = [];
        foreach ($payload['rules'] as $ruleLine) {
            $rule = RuleLine::parse($ruleLine);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }
        // Only ever reached with a summary that parsed, so an empty $rules
        // here genuinely means "this scan found nothing failing".
        RuleStore::sync($cid, $rules, $parsed['date_scan']);
```

and extend the Finding `$content` with a digest of the worst open rules:

```php
        $openRules = RuleStore::openFor($cid);
        if ($openRules !== []) {
            $content .= "\n\nTop failing rules:";
            foreach (array_slice($openRules, 0, 10) as $r) {
                $content .= sprintf("\n- [%s] %s", $r['severity'], $r['title']);
            }
            $remaining = count($openRules) - 10;
            if ($remaining > 0) {
                $content .= sprintf("\n…and %d more — see Config compliance in SAC for the full checklist.", $remaining);
            }
        }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php plugins/sac/tools/selfcheck.php configscan`
Expected: PASS, including the pre-existing `processtarget` checks

- [ ] **Step 5: Commit**

```bash
git add src/Compliance/ConfigScan.php
git commit -m "feat(sac): persist per-rule results and digest them into the Finding"
```

---

### Task 7: `FindingAffected` — guarded bridge to riskregister's `upsertAffected()`

**Files:** Create `src/Compliance/FindingAffected.php`; modify `ConfigScan::processTarget()` to call it.

**Contract** (hub §7, riskregister 2.1.0): `Finding_Item::upsertAffected($findingsId, $rows)`,
rows keyed by `external_key`, returning `['inserted','updated','skipped','controls_linked','refs_unmatched']`.

- [ ] **Step 1: Write the failing test:**

```php
'sshreader/…' // (not this file)
'affected/maps-rule-to-row' => static function (): void {
    // PURE — the mapping, independent of riskregister being installed.
    $rows = FindingAffected::rowsFor([
        ['rule_id' => 'rule_a', 'severity' => 'high', 'title' => 'Disable root login', 'framework_refs' => 'CISv8:4.1', 'state' => 'open'],
        ['rule_id' => 'rule_b', 'severity' => 'unknown', 'title' => 'Set umask', 'framework_refs' => '', 'state' => 'resolved'],
    ]);
    sac_assert_eq(count($rows), 2, 'one row per rule');
    sac_assert_eq($rows[0]['external_key'], 'rule_a', 'SCAP rule id is the upsert key');
    sac_assert_eq($rows[0]['severity'], 'High', 'SCAP high maps onto riskregister High');
    sac_assert_eq($rows[0]['state'], 'Open', 'open maps onto Open');
    sac_assert_eq($rows[0]['framework_refs'], 'CISv8:4.1', 'refs passed through verbatim, never re-interpreted');
    sac_assert_eq($rows[1]['severity'], 'Info', 'unknown severity maps onto Info');
    sac_assert_eq($rows[1]['state'], 'Resolved', 'resolved maps onto Resolved');
},

// Both of these pin riskregister's 2026-10-19 contract change: a field
// that is OMITTED leaves the stored value alone, while a field sent as ''
// actively empties it. Getting this wrong means a monthly scan quietly
// wipes what a person wrote.
'affected/never-sends-description' => static function (): void {
    $rows = FindingAffected::rowsFor([
        ['rule_id' => 'rule_a', 'severity' => 'high', 'title' => 'Disable root login', 'framework_refs' => '', 'state' => 'open'],
    ]);
    sac_assert(!array_key_exists('description', $rows[0]), 'description is never sent: it is the one field a human writes the remediation note into, and SAC has nothing to say about it that is worth overwriting a person every month');
},
'affected/omits-empty-framework-refs' => static function (): void {
    $withRefs = FindingAffected::rowsFor([
        ['rule_id' => 'r1', 'severity' => 'low', 'title' => 't', 'framework_refs' => 'CISv8:4.1', 'state' => 'open'],
    ]);
    sac_assert_eq($withRefs[0]['framework_refs'], 'CISv8:4.1', 'refs sent when the content actually had some');

    $noRefs = FindingAffected::rowsFor([
        ['rule_id' => 'r2', 'severity' => 'low', 'title' => 't', 'framework_refs' => '', 'state' => 'open'],
    ]);
    sac_assert(!array_key_exists('framework_refs', $noRefs[0]), 'no refs = key omitted, NOT sent as empty — sending "" would erase refs that a later benchmark or a human supplied');
},
'affected/absent-riskregister-is-safe' => static function (): void {
    if (FindingAffected::isAvailable()) {
        return;
    }
    sac_assert(FindingAffected::push(1, []) === null, 'push is a no-op when upsertAffected() does not exist');
},
```

- [ ] **Step 2: Run to verify it fails**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php affected/`
Expected: FAIL — class does not exist

- [ ] **Step 3: Implement:**

```php
<?php

namespace GlpiPlugin\Sac\Compliance;

/**
 * The only place SAC calls riskregister's affected-areas API. Guarded with
 * method_exists() as well as isPluginActive(), because the method arrives in
 * riskregister 2.1.0 — an older installed copy is active but has no such
 * method, and calling it would fatal the whole cron run.
 */
final class FindingAffected
{
    /** SCAP severity -> riskregister's Finding::SEVERITIES vocabulary. */
    private const SEVERITY_MAP = [
        'high'    => 'High',
        'medium'  => 'Medium',
        'low'     => 'Low',
        'unknown' => 'Info',
    ];

    private const STATE_MAP = ['open' => 'Open', 'resolved' => 'Resolved'];

    public static function isAvailable(): bool
    {
        return \Plugin::isPluginActive('riskregister')
            && class_exists(\GlpiPlugin\Riskregister\Finding_Item::class)
            && method_exists(\GlpiPlugin\Riskregister\Finding_Item::class, 'upsertAffected');
    }

    /**
     * PURE. Maps stored rule rows onto the contract's row shape.
     *
     * Per riskregister's 2026-10-19 contract change, an OMITTED key means
     * "I am saying nothing about this field, keep what is stored", while a
     * key sent as '' means "empty it". Two consequences, both deliberate:
     *
     *  - `description` is NEVER sent. It is where a person writes the
     *    remediation note, and the only thing SAC could put there is
     *    boilerplate restating the rule id. Sending it would overwrite that
     *    note on every monthly scan.
     *  - `framework_refs` is sent only when the content actually produced
     *    some. Sending '' for a rule with no CIS reference would erase refs
     *    that a later benchmark version, or a human, supplied.
     *
     * @param array<int,array<string,mixed>> $rules
     * @return array<int,array<string,string>>
     */
    public static function rowsFor(array $rules): array
    {
        $rows = [];
        foreach ($rules as $r) {
            $row = [
                'external_key' => (string) $r['rule_id'],
                'label'        => (string) $r['title'],
                'severity'     => self::SEVERITY_MAP[(string) $r['severity']] ?? 'Info',
                'state'        => self::STATE_MAP[(string) $r['state']] ?? 'Open',
            ];
            // Verbatim, per the contract: a shape mismatch must surface in
            // riskregister's refs_unmatched, not be smoothed over here.
            $refs = trim((string) ($r['framework_refs'] ?? ''));
            if ($refs !== '') {
                $row['framework_refs'] = $refs;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<string,int>|null null when riskregister cannot take them */
    public static function push(int $findingId, array $rules): ?array
    {
        if (!self::isAvailable() || $findingId <= 0) {
            return null;
        }
        $result = \GlpiPlugin\Riskregister\Finding_Item::upsertAffected($findingId, self::rowsFor($rules));
        if (!empty($result['refs_unmatched'])) {
            \Toolbox::logInFile('sac', sprintf(
                "FindingAffected: %d framework ref(s) did not match a seeded control on finding %d — SAC's codes and riskregister's seed disagree\n",
                (int) $result['refs_unmatched'],
                $findingId
            ));
        }
        return $result;
    }
}
```

In `ConfigScan::processTarget()`, after `FindingWriter::upsert()` returns the id:

```php
        $findingId = FindingWriter::upsert($input, [$cid], '', $run);
        if ($findingId !== null) {
            // Both open and recently-resolved rules, so a fixed item shows as
            // Resolved on the Finding instead of vanishing from it.
            FindingAffected::push($findingId, RuleStore::allFor($cid));
        }
```

(add `RuleStore::allFor()` alongside `openFor()`, same query without the state filter)

- [ ] **Step 4: Run to verify it passes**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php affected/`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Compliance/FindingAffected.php src/Compliance/RuleStore.php src/Compliance/ConfigScan.php
git commit -m "feat(sac): push per-rule results to riskregister affected areas when available"
```

---

### Task 8: Per-host detail page

**Files:** Create `front/compliancedetail.php`, `templates/compliancedetail.html.twig`; modify `templates/compliance.html.twig`.

- [ ] **Step 1:** The page's own guard is its test, as in Task 11 of the Tier 1 plan:
  `db/menu-entry-shape` already asserts every linked page exists, and this page is
  linked from the dashboard rather than the menu, so add to `ConfigScan::selfChecks()`:

```php
'db/compliancedetail-page-exists' => static function (): void {
    sac_assert(is_file(dirname(__DIR__, 2) . '/front/compliancedetail.php'), 'detail page exists for the dashboard link');
},
```

- [ ] **Step 2: Run to verify it fails**

Run: `php plugins/sac/tools/selfcheck.php compliancedetail`
Expected: FAIL — file does not exist

- [ ] **Step 3: Implement** — `front/compliancedetail.php`:

```php
<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

$cid = (int) ($_GET['computers_id'] ?? 0);
$computer = new Computer();
if ($cid <= 0 || !$computer->getFromDB($cid) || !$computer->can($cid, READ)) {
    // can() enforces the viewer's entity scope: a sub-entity user must not
    // read another entity's rule list by guessing an id.
    Html::displayRightError();
}

$rules = \GlpiPlugin\Sac\Compliance\RuleStore::allFor($cid);

Html::header(__('Security Assessment', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'compliance');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'compliance');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/compliancedetail.html.twig', [
    'computer_name' => $computer->fields['name'],
    'computer_url'  => Computer::getFormURLWithID($cid),
    'rules'         => $rules,
    'back_url'      => Html::getPrefixedUrl('/plugins/sac/front/compliance.php'),
]);

Html::footer();
```

`templates/compliancedetail.html.twig` — a table of `rules` with columns Severity,
Rule, Framework refs, State, First seen, Last seen, Resolved; `state == 'resolved'`
rows muted; include `@sac/_sortable-table.html.twig`; autoescaped, no `|raw`.

In `templates/compliance.html.twig`, make the fail count link to this page:

```twig
<td class="text-end"><a href="{{ r.detail_url }}">{{ r.fail_count }}</a></td>
```

(and pass `detail_url` from `front/compliance.php`, built with
`Html::getPrefixedUrl('/plugins/sac/front/compliancedetail.php?computers_id=' . $cid)`)

- [ ] **Step 4: Run to verify it passes**

Run: `php plugins/sac/tools/selfcheck.php compliancedetail`
Expected: PASS. Manual VM check (not scriptable): open the page for a host with
rules, confirm the list renders and a resolved rule is visually distinct.

- [ ] **Step 5: Commit**

```bash
git add front/compliancedetail.php templates/compliancedetail.html.twig templates/compliance.html.twig front/compliance.php
git commit -m "feat(sac): add the per-host rule checklist page"
```

---

### Task 9: Monthly cadence defaults

**Files:** Modify `src/Cron.php`, `src/Config.php`

These change **fresh installs only** — verified against core: `CronTask::register()`
returns early for an already-registered task, and `Config::setDefaults()` only
writes missing keys. VM-HQ-GLPI was already changed through the UI.

- [ ] **Step 1: Write the failing test** — add to `Config::selfChecks()`:

```php
sac_assert_eq(self::DEFAULTS['compliance_stale_days'], 45, 'stale threshold matches the monthly cadence — at 14 a monthly scan would flag every host STALE permanently');
```

- [ ] **Step 2: Run to verify it fails**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php config/defaults-shape`
Expected: FAIL — expected 45, got 14

- [ ] **Step 3: Implement** — `Config::DEFAULTS`: `'compliance_stale_days' => 45`.
  `Cron::register()`: `ConfigScan` registers with `MONTH_TIMESTAMP`.

- [ ] **Step 4: Run to verify it passes**

Run: `"/c/php/php.exe" tools/offline-selfcheck.php config/`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Config.php src/Cron.php
git commit -m "feat(sac): default config-compliance scanning to monthly"
```

---

### Task 10: Host wrapper emits the rule lines

**Files:** Modify `tools/host-agent/sac-oscap-summary.sh`

Uses Task 1's recon for the CIS reference selector. The existing python3 block
already parses the results file; extend it to also emit rule lines, and to resolve
references from `$CONTENT_FILE` (which lives on the host — this is why Design A
can produce `framework_refs` at all).

- [ ] **Step 1:** Extend the python3 block to collect failing rules:

```python
kept = {'fail', 'error'}
rules = []
for rr in test_result.findall('xccdf:rule-result', ns):
    res_el = rr.find('xccdf:result', ns)
    status = res_el.text.strip() if res_el is not None and res_el.text else ''
    if status not in kept:
        continue
    rules.append({
        'id': rr.get('idref', ''),
        'result': status,
        'severity': (rr.get('severity') or 'unknown').lower(),
    })
```

- [ ] **Step 2:** Resolve each rule's title and CIS references from the content
  datastream, using the href substring Task 1 confirmed (`CIS_HREF` below is set
  from that recon, not guessed):

```python
import re
CIS_HREF = 'cisecurity.org'   # ← confirm/replace from Task 1 Step 3
titles, refs = {}, {}
content = ET.parse(content_path).getroot()
for rule in content.iter():
    if not rule.tag.endswith('}Rule'):
        continue
    rid = rule.get('id', '')
    if not rid:
        continue
    t = rule.find('xccdf:title', ns)
    if t is not None and t.text:
        titles[rid] = ' '.join(t.text.split())
    codes = [
        (r.text or '').strip()
        for r in rule.findall('xccdf:reference', ns)
        if CIS_HREF in (r.get('href') or '')
    ]
    if codes:
        refs[rid] = ','.join('CISv8:' + c for c in codes if c)
```

- [ ] **Step 3:** Emit one line per rule after the summary line:

```python
for r in rules:
    title = titles.get(r['id'], r['id']).replace('\n', ' ')
    print("SACRULE1|{}|{}|{}|{}|{}".format(
        r['id'], r['result'], r['severity'], refs.get(r['id'], ''), title))
```

- [ ] **Step 4: Verify on a host**

```bash
sudo /usr/local/sbin/sac-oscap-summary.sh
head -3 /var/lib/sac/oscap_summary.txt
wc -l /var/lib/sac/oscap_summary.txt
```

Expected: line 1 is `SACCFG1|…` unchanged; lines 2+ are `SACRULE1|…`; the line
count is roughly `fail + error` + 1. Then confirm the pull still works unchanged:

```bash
sudo -u nginx ssh -i /etc/sac-collect/sac_collect_key \
  -o UserKnownHostsFile=/etc/sac-collect/known_hosts pgnusr@<host>
```

Expected: the whole multi-line payload, no `authorized_keys` change needed.

- [ ] **Step 5: Commit**

```bash
git add tools/host-agent/sac-oscap-summary.sh
git commit -m "feat(sac): emit per-rule SCAP lines from the host wrapper"
```

---

### Task 11: Ship as `1.8.0-rc1`

**Files:** Modify `setup.php`, `CHANGELOG.md`

- [ ] **Step 1:** `PLUGIN_SAC_VERSION` → `1.8.0-rc1`; CHANGELOG heading `## [1.8.0-rc1] - <date>` with the Added/Changed entries.
- [ ] **Step 2:** `"/c/php/php.exe" tools/offline-selfcheck.php` → all green.
- [ ] **Step 3:** Commit, `git tag 1.8.0-rc1`, build `sacv1.8.0-rc1.zip` with
      `git archive --format=zip --prefix=sac/ -o sacv1.8.0-rc1.zip HEAD -- . ':!docs' ':!.gitattributes'`.
- [ ] **Step 4:** Deploy note to the user: this release changes `.twig` files, so
      **`cache:clear` is required**; the host wrapper must be redeployed to every
      monitored host for rule lines to appear.
- [ ] **Step 5:** Log to hub §7: version, what shipped, and the first real
      `refs_unmatched` count once riskregister 2.1.0 is on the VM.

---

## Self-Review Notes

- **Spec coverage:** every bullet of the 2026-10-15 hub agreement is covered —
  fail+error only (Task 2), one Finding per host (Task 6, unchanged behaviour),
  SAC's own table first (Tasks 4-5), `upsertAffected()` with `framework_refs`
  (Task 7), absence never means fixed (Tasks 3, 5, 6), no manual state in SAC
  (absent by construction — no column for it in Task 4).
- **Placeholder scan:** none. Task 1 is a deliberate recon task with exact
  commands, and Task 10 names the one value it supplies (`CIS_HREF`).
- **Type consistency:** `RuleLine::parse()`'s five keys are the same five consumed
  by `RuleStore::sync()` and mapped by `FindingAffected::rowsFor()`.
- **Review Focus:** all five items have an owning task and an assertion.
- **Known gap, accepted:** a rule retired from the benchmark entirely resolves
  rather than being removed (riskregister's note of 2026-10-15). Correct handling
  needs their explicit removal call; deferred until an SSG version bump actually
  orphans rules.
