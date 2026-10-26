# SAC 1.9.0 — Licence Classification and Review Queue, Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Module 2's publisher-based licensing decision with per-entity classification rules, and route anything unclassified to a review queue instead of asserting a Finding about it.

**Architecture:** A new `glpi_plugin_sac_licenserules` table holds per-entity rules (pattern, classification, mandatory reason). A pure `RuleMatcher` resolves which rule decides a given software/version — nearer entity first, then more specific pattern. `LicenseScan`'s 2a branch stops consulting the publisher watch list for its verdict, evaluates each **installed version**, emits one Finding per unlicensed software, and files unmatched software from a watch-list publisher into `glpi_plugin_sac_licensereview`. A one-shot, marker-guarded step closes the retired per-publisher Findings.

**Tech Stack:** PHP 8.1, GLPI 11.0.5 plugin APIs, MySQL/MariaDB via `$DB`, Twig templates, the dependency-free check suite in `tools/offline-selfcheck.php`.

**Spec:** `docs/superpowers/specs/2026-10-25-sac-license-classification-design.md`

## Global Constraints

- Target version `1.9.0`; built on `main` after 1.8.0. Branch from `main`.
- Rights reuse the existing `plugin_sac_finding` right. **No new right, no `RightDefinition` change, no menu gate change.**
- Rules are **per-entity with inheritance** (`entities_id` + `is_recursive`), like SAC's other per-entity data.
- **One Finding per software**, code `SAC-LIC-SW-<softwares_id>`. The old `SAC-LIC-MISSING-<manufacturers_id>` scheme is retired, never deleted.
- Every rule carries a **non-empty `reason`**; a rule without one is rejected before it is written.
- Classification is evaluated **per installed version** (`glpi_softwareversions.name`), not per `Software` row.
- Classes are exactly `free` and `needs_license`. There is no `subscription` class and no expiry handling anywhere in this work.
- Checks live in each class's `selfChecks()`; pure ones run in `tools/offline-selfcheck.php`, DB-backed ones are prefixed `db/` and skipped offline.
- `php tools/offline-selfcheck.php` must end `0 failed` at every commit.
- Table DDL follows `src/Migration.php`: `int unsigned` keys, `timestamp` (never `DATETIME`), declared in `self::$tables[...]`, guarded by `tableExists`.

## Review Focus

Five conditions the spec implies that no task's happy-path test covers. Each has a test added to the owning task.

1. **A rule with an empty or bare-`*` pattern** would classify the whole estate from one row — Task 2 rejects it at validation, not at match time.
2. **The retirement step re-run on the next upgrade** (install runs on every `--force`) must close nothing a second time and must not re-open what a human closed — Task 6.
3. **A software whose installed versions are mixed**, one matching a `free` rule and one matching nothing, must not come out free — Task 5.
4. **An approver restricted to a child entity** previewing a pattern that also matches software outside their scope — Task 8 shows the out-of-scope count without disclosing the rows.
5. **A `Software` row whose `manufacturers_id` points at a deleted manufacturer** must not crash the scan or silently skip the software — Task 5.

---

### Task 1: `glpi_plugin_sac_licenserules` table

**Files:**
- Modify: `src/Migration.php` (add to `define()`, add checks to `selfChecks()`)

**Interfaces:**
- Produces: table `glpi_plugin_sac_licenserules` with columns `id`, `entities_id`, `is_recursive`, `pattern` varchar(255), `match_on` varchar(10) (`name`|`version`), `classification` varchar(20) (`free`|`needs_license`), `reason` text, `date_creation`, `date_mod`; `UNIQUE KEY (entities_id, match_on, pattern)`; `KEY (entities_id, classification)`.

- [ ] **Step 1: Write the failing check** — add `db/migration-licenserules-table` to `Migration::selfChecks()`, asserting the table name is present in `self::$tables` and that its DDL contains `entities_id`, `is_recursive`, `match_on`, `classification`, `reason`, and the unique key.
- [ ] **Step 2: Run to verify it fails** — `php tools/offline-selfcheck.php licenserules`. Expected: the check is skipped offline (`db/` prefix); run it instead by temporarily renaming to `migration/…` or assert on `Migration::tables()` from a pure check. Use whichever the file's existing `db/migration-configrules-table` check does.
- [ ] **Step 3: Implement** — add the DDL to `Migration::define()` following the `glpi_plugin_sac_configrules` block as the model, with the comment explaining why the unique key is `(entities_id, match_on, pattern)`: the same pattern may legitimately exist in two entities, and a name-rule and a version-rule may share a pattern string.
- [ ] **Step 4: Run to verify it passes** — `php tools/offline-selfcheck.php`. Expected: `0 failed`.
- [ ] **Step 5: Commit** — `feat(sac): add the licence classification rules table`

---

### Task 2: `LicenseRule` — validation and the specificity score

**Files:**
- Create: `src/License/LicenseRule.php` (`GlpiPlugin\Sac\License\LicenseRule`)

**Interfaces:**
- Produces:
  - `LicenseRule::validate(array $input): ?string` — returns an error message, or `null` when valid.
  - `LicenseRule::specificity(string $pattern): int` — higher is more specific.
  - `LicenseRule::matchesPattern(string $pattern, string $subject): bool` — case-insensitive glob, `*` is the only wildcard.

- [ ] **Step 1: Write the failing test** — `license/rule-validation` and `license/rule-specificity` in `LicenseRule::selfChecks()`:

```php
sac_assert_eq(LicenseRule::validate(['pattern' => 'mysql-community-*', 'match_on' => 'name', 'classification' => 'free', 'reason' => 'GPL']), null, 'a complete rule validates');
sac_assert(LicenseRule::validate(['pattern' => 'x', 'match_on' => 'name', 'classification' => 'free', 'reason' => '  ']) !== null, 'a blank reason is rejected: the table is maintained by another team');
sac_assert(LicenseRule::validate(['pattern' => '*', 'match_on' => 'name', 'classification' => 'free', 'reason' => 'r']) !== null, 'a bare * would classify the whole estate from one row');
sac_assert(LicenseRule::validate(['pattern' => '', 'match_on' => 'name', 'classification' => 'free', 'reason' => 'r']) !== null, 'an empty pattern is rejected');
sac_assert(LicenseRule::specificity('mysql-community-server') > LicenseRule::specificity('mysql-*'), 'a literal beats a prefix');
sac_assert(LicenseRule::specificity('mysql-community-*') > LicenseRule::specificity('mysql-*'), 'a longer prefix is more specific');
sac_assert(LicenseRule::matchesPattern('*.el9', 'openssl-3.0.7-28.el9'), 'suffix glob');
sac_assert(LicenseRule::matchesPattern('*el9*', '8.1.27-1.module+el9.3.0+90130+7e4e1ce0'), 'the modular form is reachable by a pattern that does not assume a leading dot');
sac_assert(!LicenseRule::matchesPattern('*.el9', '8.1.27-1.module+el9.3.0+90130+7e4e1ce0'), 'and the naive dotted pattern does NOT reach it — this is the case that made the design');
```

- [ ] **Step 2: Run to verify it fails** — `php tools/offline-selfcheck.php license/rule`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement** the three static methods. `specificity()` counts non-wildcard characters and penalises each `*`; the exact formula is yours, the ordering assertions above are the contract. `matchesPattern()` converts the glob with `preg_quote` + `str_replace('\*', '.*', …)` and matches case-insensitively.
- [ ] **Step 4: Run to verify it passes** — Expected: `0 failed`.
- [ ] **Step 5: Commit** — `feat(sac): add licence rule validation and specificity`

---

### Task 3: `RuleMatcher` — which rule decides

**Files:**
- Create: `src/License/RuleMatcher.php` (`GlpiPlugin\Sac\License\RuleMatcher`)

**Interfaces:**
- Consumes: `LicenseRule::matchesPattern()`, `LicenseRule::specificity()`.
- Produces: `RuleMatcher::decide(string $softwareName, array $versionNames, array $rules, array $entityAncestry): ?array` — returns the winning rule row plus a `matched_on` key (`'name'` or the version string that matched), or `null` when nothing matches. `$entityAncestry` is the software's entity id first, then its ancestors, nearest first.

- [ ] **Step 1: Write the failing test** — `license/matcher-two-axes` and `license/matcher-mixed-versions`:

```php
// Nearer entity wins first, then specificity (spec: orchestrator input, 2026-10-26).
$parentExact = ['id' => 1, 'entities_id' => 0, 'pattern' => 'acme-db', 'match_on' => 'name', 'classification' => 'needs_license'];
$childBroad  = ['id' => 2, 'entities_id' => 5, 'pattern' => 'acme-*',  'match_on' => 'name', 'classification' => 'free'];
$win = RuleMatcher::decide('acme-db', ['1.0'], [$parentExact, $childBroad], [5, 0]);
sac_assert_eq($win['id'], 2, 'the nearer entity wins even with a looser pattern: a local rule is a deliberate override');

// Within one entity, specificity decides.
$broad = ['id' => 3, 'entities_id' => 5, 'pattern' => 'acme-*', 'match_on' => 'name', 'classification' => 'free'];
$exact = ['id' => 4, 'entities_id' => 5, 'pattern' => 'acme-db', 'match_on' => 'name', 'classification' => 'needs_license'];
sac_assert_eq(RuleMatcher::decide('acme-db', ['1.0'], [$broad, $exact], [5, 0])['id'], 4, 'same entity: the more specific pattern wins');

// Review Focus 3: a free version must not make the whole software free.
$freeVer = ['id' => 5, 'entities_id' => 0, 'pattern' => '*el9*', 'match_on' => 'version', 'classification' => 'free'];
sac_assert(RuleMatcher::decide('php', ['8.1.27-1.module+el9.3.0', '8.2.0-upstream'], [$freeVer], [0]) === null, 'one unmatched installed version leaves the software undecided, never free');
```

- [ ] **Step 2: Run to verify it fails** — Expected: FAIL, class not found.
- [ ] **Step 3: Implement `decide()`** — a `name` rule is tested against `$softwareName`; a `version` rule must match **every** entry in `$versionNames` to decide, and returns `null` if any version is unmatched. Among candidates, sort by entity distance (index in `$entityAncestry`) ascending, then `specificity()` descending, then `id` ascending so ties are deterministic.
- [ ] **Step 4: Run to verify it passes** — Expected: `0 failed`.
- [ ] **Step 5: Commit** — `feat(sac): resolve the deciding licence rule, nearer entity first`

---

### Task 4: `glpi_plugin_sac_licensereview` table and store

**Files:**
- Modify: `src/Migration.php`
- Create: `src/License/ReviewQueue.php` (`GlpiPlugin\Sac\License\ReviewQueue`)

**Interfaces:**
- Produces: table with `id`, `softwares_id`, `entities_id`, `state` varchar(20) (`new`|`flagged`|`decided`), `note` text, `date_first_seen`, `date_mod`; `UNIQUE KEY (softwares_id, entities_id)`.
  - `ReviewQueue::sync(int $entityId, array $softwareIds, string $now): array` — upserts queue rows for the given software, returns counts `['new' => int, 'kept' => int]`. Rows already `decided` are left alone.
  - `ReviewQueue::openFor(?int $entityId): array` — rows not `decided`.

- [ ] **Step 1: Write the failing checks** — `db/reviewqueue-sync-is-idempotent` (syncing the same set twice yields `new => 0` the second time) and `db/reviewqueue-decided-is-not-resurrected`.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the DDL and the store, following `src/Compliance/RuleStore.php` as the model for upsert-and-count.
- [ ] **Step 4: Run to verify they pass.**
- [ ] **Step 5: Commit** — `feat(sac): add the licence review queue table and store`

---

### Task 5: Rewire `LicenseScan` 2a to classification

**Files:**
- Modify: `src/License/LicenseScan.php` (`run()` around L34-110, `candidateSoftware()` L231, replace `emitMissingLicenceFindings()` L149)
- Modify: `src/Code.php` (add `licSoftware()`)

**Interfaces:**
- Consumes: `RuleMatcher::decide()`, `ReviewQueue::sync()`, `LicenseRule` rows.
- Produces: `Code::licSoftware(int $softwaresId): string` returning `SAC-LIC-SW-<id>`; Findings keyed by it.

- [ ] **Step 1: Write the failing tests** — add to `Code::selfChecks()` a format and ≤50-length check for `licSoftware()`, matching the existing `code/length` style. Add to `LicenseScan::selfChecks()`:

```php
'license/unmatched-goes-to-queue-not-finding' // a watch-list publisher's software matching no rule produces a queue row and no Finding input
'license/free-rule-raises-nothing'            // a software whose versions all match a free rule produces neither
'license/needs-license-rule-fires-for-any-publisher' // a needs_license rule decides even when the publisher is NOT on the watch list
'license/missing-manufacturer-row-is-not-skipped'    // Review Focus 5: manufacturers_id pointing at a deleted row still reaches classification
```

- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** — extend `candidateSoftware()`'s SELECT to carry each software's installed version names (join `glpi_softwareversions` + `glpi_items_softwareversions`, grouped). In `run()`, replace the `in_array($mid, $commercial, true)` verdict with `RuleMatcher::decide(...)`:
  - decided `free` → nothing;
  - decided `needs_license` **and** no licence row for that entity → Finding via `FindingWriter::upsert()` with `Code::licSoftware($sid)`, `assessment_ref` `SAC/license`, severity from Task 9's config key;
  - undecided **and** publisher on the watch list → `ReviewQueue::sync()`, **no Finding**;
  - undecided and publisher not on the list → nothing (the accepted blind spot, spec).
  Keep the 2b over-deployment branch untouched.
- [ ] **Step 4: Run to verify they pass.**
- [ ] **Step 5: Commit** — `feat(sac): decide licensing by classification rule, not by publisher`

---

### Task 6: Retire the per-publisher Findings, once

**Files:**
- Create: `src/License/RetirePublisherFindings.php`
- Modify: `hook.php` (call it from the install path, after `Migration::install()`)
- Modify: `src/Config.php` (`DEFAULTS` + `FIELD_TYPES`: `license_publisher_findings_retired` default `0`)

**Interfaces:**
- Produces: `RetirePublisherFindings::run(RunLog $run): int` — number of Findings closed; returns `0` immediately when the marker is already set.

- [ ] **Step 1: Write the failing checks** — `db/retire-closes-old-codes` (a `SAC-LIC-MISSING-*` Finding is cleared and the marker set) and **Review Focus 2**: `db/retire-is-idempotent` (a second run returns `0`, writes nothing, and does not touch a Finding a human has since closed).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** — read the marker first; enumerate Findings whose `code` is `LIKE 'SAC-LIC-MISSING-%'` and whose `assessment_ref` is `SAC/%`; close each through `FindingWriter::clearExcept($code, [], 'retired: licensing is now decided per software', $run, null)` so the cleared note, history, links and any promoted Risk survive; `$run->bump()` the count; write the marker **after** the loop so a failed run retries. **Never delete a Finding.**
- [ ] **Step 4: Run to verify they pass.**
- [ ] **Step 5: Commit** — `feat(sac): retire the per-publisher licence Findings, once`

---

### Task 7: Rules table page

**Files:**
- Create: `front/licenserules.php`, `templates/licenserules.html.twig`
- Modify: `src/Menu.php` (add the entry beside `compliance` and `reconcile`)

**Interfaces:**
- Consumes: `LicenseRule::validate()`, the table from Task 1.

- [ ] **Step 1: Write the failing check** — extend `Menu`'s existing `db/menu-entry-shape` check to assert the new entry has a `page` and a `title`, following the two entries already there.
- [ ] **Step 2: Run to verify it fails.**
- [ ] **Step 3: Implement** — `Session::checkRight('plugin_sac_finding', READ)` at the top, as `front/compliance.php` does. List rules grouped by entity, showing pattern, match-on, classification, **reason**, and the entity they belong to. Add/edit posts back to the same file and runs `LicenseRule::validate()` before writing; a validation error re-renders with the message. **The page states the resolution order in plain words and carries one worked example** (spec, goal 3): a parent `acme-db` rule and a child `acme-*` rule, and which wins.
- [ ] **Step 4: Run to verify it passes**, and load the page on the VM.
- [ ] **Step 5: Commit** — `feat(sac): add the licence rules page`

---

### Task 8: Review queue page, with the blast-radius preview

**Files:**
- Create: `front/licensereview.php`, `templates/licensereview.html.twig`
- Create: `src/License/RulePreview.php`

**Interfaces:**
- Produces: `RulePreview::forPattern(string $pattern, string $matchOn, ?array $visibleEntities): array` — `['visible' => array<int,string> software names, 'hidden_count' => int]`.

- [ ] **Step 1: Write the failing test** — `license/preview-covers-more-than-the-queue` (a pattern matching installed software that never queued still appears in `visible`) and **Review Focus 4**: `license/preview-hides-rows-outside-scope-but-counts-them` (software outside `$visibleEntities` is absent from `visible` and counted in `hidden_count`).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** — the page lists queue rows with state, lets a row be **flagged with a note** (the "mark" step) and **decided** (`free` / `needs_license`). Deciding writes the decision for that software, then offers a generalised rule: the operator types or accepts a suggested pattern, and the page shows `RulePreview::forPattern()` — the exact software it would cover, plus `hidden_count` as a bare number with no rows — **before** the rule is saved. Saving goes through `LicenseRule::validate()`, so the reason is mandatory here too.
- [ ] **Step 4: Run to verify they pass**, and exercise the flow on the VM.
- [ ] **Step 5: Commit** — `feat(sac): add the licence review queue page`

---

### Task 9: Seed rules and the severity key

**Files:**
- Create: `install/data/license_rules.csv`
- Modify: `src/License/LicenseRule.php` (add `seedFromCsv(): void`), `hook.php`, `src/Config.php`

**Interfaces:**
- Produces: `LicenseRule::seedFromCsv(): void`, seeding only into entity `0`, skipping a pattern that already exists there.

- [ ] **Step 1: Write the failing check** — `license/seed-covers-both-distro-forms`: after seeding, a `*.el9` **and** a `*el9*`-style rule exist such that `RuleMatcher::decide()` returns `free` for both `openssl-3.0.7-28.el9` and `8.1.27-1.module+el9.3.0+90130+7e4e1ce0`.
- [ ] **Step 2: Run to verify it fails.**
- [ ] **Step 3: Implement** — CSV columns `match_on,pattern,classification,reason`. Seed exactly: distro releases (both the dotted and the modular form) = `free`; `mysql-community-*` = `free`; `mysql-commercial-*` = `needs_license`; `*-openjdk*` = `free`; Oracle Database EE/SE = `needs_license`. **Do not seed Oracle JDK** — version-dependent, left to the admin (spec). Add `license_missing_severity` (default `Medium`) to `DEFAULTS` and `FIELD_TYPES`, used by Task 5.
- [ ] **Step 4: Run to verify it passes.**
- [ ] **Step 5: Commit** — `feat(sac): seed the certain licence classifications`

---

### Task 10: Ship as `1.9.0-rc1`

**Files:** Modify `setup.php`, `CHANGELOG.md`

- [ ] **Step 1:** `PLUGIN_SAC_VERSION` → `1.9.0-rc1`; CHANGELOG heading `## [1.9.0-rc1] - <date>` with Added/Changed/Fixed.
- [ ] **Step 2:** `php tools/offline-selfcheck.php` → `0 failed`.
- [ ] **Step 3:** Commit, `git tag 1.9.0-rc1`, build with `git archive --format=zip --prefix=sac/ -o sacv1.9.0-rc1.zip HEAD -- . ':!docs' ':!.gitattributes'`.
- [ ] **Step 4: Verify the artefact, not the working copy** — open the zip and confirm `sac/setup.php` reads `1.9.0-rc1` and `sac/tools/host-agent/sac-oscap-summary.sh` contains no `\r`. This is the check 1.8.0-rc2 lacked.
- [ ] **Step 5:** Deploy note to the user: `.twig` files changed, so **`cache:clear` is required**; the retirement step runs on `plugin:install --force` and closes the old publisher Findings.

## Self-Review Notes

- **Spec coverage:** goals 1-4 → Tasks 5, 4+8, 7+2, 7+8. Non-goals need no task. Anti-goals → Task 8 (preview), Task 4 (queue state), Task 6 (no stale Finding). Decisions 1-11 → Tasks 1-9. All four of my recorded calls → Tasks 3 (per version), 5 (rule decides regardless of publisher; blind spot), 8 (preview scope), 5 (ignore categories excluded before classification, inherited from `candidateSoftware()`).
- **Review Focus:** all five have a named test in Tasks 2, 3, 5, 6 and 8.
- **Type consistency:** `decide()` returns a rule row plus `matched_on` and is consumed only in Task 5; `validate()` returns `?string` and is consumed in Tasks 7 and 8; `Code::licSoftware()` is defined in Task 5 and used nowhere else.
- **Known gap, accepted:** software with no manufacturer never enters the queue (spec). Task 5's test pins that a *deleted* manufacturer row is still classified, which is a different case.
- **Not in this plan:** pushing `main` to origin, and any change to Module 1 or Module 3.
