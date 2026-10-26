# Changelog

## [1.9.0-rc1] - 2026-10-26

**Module 2 v2: a software classification replaces the publisher watch list as
the licensing decision.** On `vm-dev-damara` the old publisher test produced
**817** "unlicensed commercial software" Findings, every one of them an Oracle
Linux distribution package whose vendor GLPI records as exactly `Oracle`; the
number of genuine commercial Oracle products among them was **0**. A publisher
is not a licensing decision — within one publisher, editions differ (MySQL
Community is GPL, MySQL Commercial is a subscription; OpenJDK is free, Oracle
JDK depends on the version). Module 2 now classifies software with per-entity
rules, and anything a rule cannot decide goes to a review queue instead of
being asserted as unlicensed.

### Added

- **Licence rules** (`License rules` page, `glpi_plugin_sac_licenserules`):
  a pattern matched on software **name** or installed **version**, assigning
  exactly `free` or `needs_license`, with a mandatory reason. Rules belong to an
  entity and are inherited by its sub-entities; the nearer entity wins, then the
  more specific pattern, then the lowest id. A version rule decides only when
  **every** installed version matches — a partial match leaves the software
  undecided rather than guessing. A duplicate `(entity, match_on, pattern)` is
  refused. There is no delete: a rule is superseded, not removed, so history
  stays readable.
- **Licence review queue** (`License review` page,
  `glpi_plugin_sac_licensereview`): software from a watched commercial publisher
  that no rule decides. A row can be **flagged** with a note for a colleague and
  **decided** later. A decision is saved immediately as an exact rule for that
  software in that entity; the page then offers to generalise the exact rule
  into a wider pattern and shows **every installed software the pattern would
  cover** before it is replaced, counting but never naming rows outside the
  approver's entities. A `decided` row is never reopened by a later scan.
- **Seeded baseline rules** (`install/data/license_rules.csv`), entity 0 and
  recursive, topped up on every install without overwriting an admin's edit:
  RHEL 7/8/9/10 distribution packages in both the dotted (`.el<N>`) and modular
  (`+el<N>`) version forms = free; `mysql-community-*` = free;
  `mysql-commercial-*` = needs licence; `*-openjdk*` = free; Oracle Database
  Enterprise/Standard Edition = needs licence. **Deliberately not seeded:**
  Oracle JDK (licence depends on version) and Oracle Database XE (free — a
  broad `Oracle Database*` rule would have charged for it). Both queue for a
  human decision instead.
- **`license_missing_severity`** (default `Medium`): the severity of a single
  unlicensed-software Finding, replacing the old 2a volume thresholds.
- **One Finding per software** (`SAC-LIC-SW-<software id>`), assignable and
  closable on its own, linked to its Software item as well as its Computers.

### Changed

- **The publisher watch list now only gates queue entry, never the verdict.**
  Software from a publisher not on the list stays invisible to Module 2 as
  before; software on the list that no rule decides becomes a queue row, not a
  Finding. A rule decides regardless of publisher.
- **Findings are written only for `needs_license` software with no licence
  row**, and are cleared when the software is licensed, leaves scope, or a rule
  stops classifying it as needing a licence.
- **The two `license_2a_*` thresholds are removed** from the Setup page and
  their stored values are deleted on install: a volume threshold over a
  publisher-wide Finding has no meaning when there is one Finding per software.
- **Upgrade closes the old per-publisher Findings** (`SAC-LIC-MISSING-*`) with a
  superseded note. Nothing is deleted and no status is changed; closing them
  stays a human decision.

### Fixed

- **Distribution packages are no longer reported as unlicensed commercial
  software.** The 817 false Findings on a single Oracle Linux host, and their
  equivalents on every RHEL host, are gone: the distro pattern matches first.
- **The modular version form is matched, not just the dotted one.** Eleven PHP
  packages on `vm-dev-damara` are versioned `8.1.27-1.module+el9.3.0+90130+…`
  — `+el9`, not `.el9`. A rule written only as `*.el9` leaves exactly those
  behind with no clue why; the seed now carries both forms.

### Notes for deployment

- **`cache:clear` is required** — this release adds and changes `.twig` files,
  and GLPI does not recompile them in production.
- **Run `php bin/console plugin:install sac --force`** to create the two new
  tables, seed the baseline rules, delete the removed settings' stored values
  and retire the old per-publisher Findings.
- **rc1 has not run against a database.** The offline suite covers the matching,
  resolution, seed data and decision logic, but the migration, the seed writes,
  the two new pages and the review/generalise flow are first exercised by that
  install. Verify them on the VM before promoting a final 1.9.0.

## [1.8.0] - 2026-10-25

**Module 3 Tier 2: per-rule SCAP results.** Tier 1 reported one score per host;
this release records every failing rule by name, tracks it until it is fixed, and
sends it to `riskregister` as an affected area of the host's Finding.

Verified end to end on 2026-10-25: the wrapper produced 57 rule lines on
`vm-dev-damara` (Oracle Linux 9.3), matching the scan's own `fail` count, and
after deployment GLPI held 57 rules with 54 carrying CIS references, with no
control linked automatically — correct, since the content cites CIS Controls v7
and `riskregister` holds only v8.

### Fixed

- **The host wrapper ships with Unix line endings.** `.gitattributes` already
  marked it `-text` so git would never rewrite it, which is right — but that
  also means git faithfully stored the CRLF an editor on Windows had written,
  and `git archive` put it straight into the zip.
- **The offline suite now refuses a wrapper containing a carriage return**
  (`hostagent/wrapper-has-unix-line-endings`), so this fails on the
  workstation instead of on the host after an scp and an install. Verified by
  re-introducing the CRLF: the check fails.

- **A failed per-rule parse no longer resolves every open rule.** The wrapper
  used to write the summary line alone when its per-rule step failed, which
  SAC could not tell apart from a host reporting nothing failing — so it would
  mark every rule on that host Resolved, telling a person dozens of findings
  were fixed on the word of a host that never said so. The wrapper now says
  `rule_parse_failed` in the payload, SAC skips the rule sync entirely, leaves
  the stored rules untouched and records it in the Reconcile tab.
- **A missing rule state is omitted, not read as "Open".** Defaulting would
  have reopened a rule a previous scan resolved.

### Added

- **Per-rule compliance results.** The host wrapper now emits one line per
  failing rule alongside the existing score line, and SAC stores each rule
  with its own open / resolved / reopened lifecycle. A rule that stops
  failing is marked Resolved rather than disappearing, so "it is no longer
  listed" can never be mistaken for "it was fixed".
- **Per-host rule checklist page**, reached from the Config compliance tab.
- **CIS control references** are passed to `riskregister` as affected-area
  `framework_refs`, taken verbatim from the SCAP content.
- **Config compliance scanning defaults to monthly** (`compliance_stale_days`
  45), matching how often a benchmark realistically changes.

### Changed

- **The wrapper's memory is now bounded on a real datastream.** It cleared
  each rule it finished, which does nothing for the large OVAL section that
  follows the benchmark: measured 22.0 MB peak on a 7.4 MB file shaped like
  the real one, against 24.2 MB for reading the whole file at once. Freeing
  everything that ends outside a rule brings it to 1.8 MB. This is headroom
  for small hosts, not a fix for a failure anyone has seen.

- **A scan that evaluated nothing is refused, not reported as 0.00.** An
  Oracle Linux 9 host carrying only Oracle Linux 7 SCAP content returns every
  rule as "notapplicable": 978 rules, 0 pass, 0 fail. The previous wrapper
  wrote that as a score of zero, which SAC's thresholds read as Critical — a
  host reported as maximally non-compliant because of a packaging mistake.
  The wrapper now leaves the previous payload untouched and explains why, so
  the host ages into the existing "stale" handling instead, which says what
  is actually true: it has not reported. A genuinely clean host has pass > 0
  and is unaffected.
- **CIS references are labelled per reference, from its own href**, never
  with one benchmark version assumed for the whole fleet. The content on
  Oracle Linux 9 references CIS Controls **v7** (its codes run to 20; v8 has
  18), so refs are sent as `CISv7:<n>`. A reference whose href SAC does not
  recognise is reported through the Reconcile tab instead of being guessed
  at, because a wrong control reference is worse than a missing one.
- **`framework_refs` is built to fit riskregister's 255-character column.**
  Refs are normalised exactly as its parser normalises them, deduplicated,
  then whole refs are dropped from the end if the list is still too long —
  never cut mid-reference. A cut landing inside a code (`CISv7:19` becoming
  `CISv7:1`) would still parse, and would point at the wrong control.

### Notes for deployment

- This release changes `.twig` files, so **`cache:clear` is required**.
- The host wrapper (`tools/host-agent/sac-oscap-summary.sh`) must be
  redeployed to every monitored host; without it, hosts keep sending the
  score line alone and SAC behaves exactly as in 1.7.1.
## [1.7.1] - 2026-10-15

### Added (rc4 → rc5)

- **The host name links to its GLPI asset** on both the Config compliance
  dashboard and the SSH targets list, so a failing host is one click from
  its Computer record instead of a name you have to search for.

### Fixed (rc3 → rc4)

- **The Finding's "Scope / affected area" showed a different percentage
  than everything else.** It was formatted to one decimal (`49.2%`) while
  the Finding's own Description and the dashboard both show two
  (`49.17%`), so the same measurement read as two different numbers. All
  three now use two decimals.

### Added (rc2 → rc3)

- **The SSH target's IP is taken from GLPI inventory.** Leave Host / IP
  blank and SAC fills in the Computer's IPv4 address from
  `glpi_ipaddresses` (loopback and IPv6 skipped); type one only to
  override, e.g. a management interface GLPI has not inventoried. The
  registered-targets list also shows what inventory currently knows next
  to the configured host, flagging a drifted address instead of leaving it
  to surface weeks later as a silently failing scan.

### Fixed (rc1 → rc2)

- **The Computer picker on the SSH targets screen listed nothing.** rc1
  passed `'entity' => 'all'` to `Dropdown::show()`; core accepts only an
  entity id, an array of ids, or `-1` ("all active entities", and already
  the default). A non-numeric string falls through
  `Session::getMatchingActiveEntities()` to its "Unexpected value type"
  branch, which returns an empty array — so the dropdown was restricted to
  zero entities and silently showed no Computers, with no error anywhere.
  The option is now simply not passed.

### Changed

- **Module 3 Phase 1's transport is now SSH, not GLPI Inventory Collect.**
  VM verification of v1.7.0 found Collect only supports file-metadata/
  registry/WMI collection — none of which can capture a Linux cron's
  arbitrary text output — so the design could not work as shipped. The
  host's oscap cron wrapper (`tools/host-agent/sac-oscap-summary.sh`,
  distributed separately — it runs on the monitored host, not through
  this plugin) now writes its one-line summary to a fixed path, and SAC
  pulls it over a restricted, forced-command, read-only SSH key — the
  remote key can only `cat` that one file, nothing else, no shell. Hosts
  are registered one at a time on a new **SSH targets** admin screen
  (linked from the Config compliance dashboard) — nothing is
  auto-discovered from GLPI's own network inventory.
- `compliance_collect_name` is removed; replaced by `compliance_ssh_key_path`,
  `compliance_ssh_known_hosts_path`, `compliance_remote_summary_path`, and
  `compliance_ssh_timeout_seconds`. New table `glpi_plugin_sac_sshtargets`
  (one row per registered Computer: host, port, SSH user, active flag).
- Staleness and the Finding's reported scan time are now read from the
  oscap summary line's OWN embedded timestamp (set by the host's cron),
  not from "when did SAC last manage to connect" — a successful SSH pull
  of a file the host hasn't refreshed in weeks no longer reads as fresh.

## [1.7.0] - 2026-10-14

### Added

- **Module 3 Phase 1 — Config compliance (OpenSCAP Tier 1).** Reads the
  one-line `oscap` summary GLPI Agent's Collect task reports per host
  (score, pass/fail/other counts, benchmark id, timestamp), stores the
  latest reading per `Computer`, and emits a Finding into `riskregister`
  when a host's score falls below a configurable threshold (category
  `Configuration Compliance`). New "Config compliance" dashboard page lists
  every host's latest score, flags hosts whose Collect data has gone stale,
  and clears a Finding automatically once a host reports 100%. New weekly
  cron `ConfigScan`; new Setup section with the collect-task name and the
  Critical/High/Medium score thresholds.
- Full per-rule checklist (Open/Not a Finding/Not Applicable/Not Reviewed),
  OVAL patch-state detail, and POA&M are **not** part of this phase — they
  need the full `results.xml`/ARF pull (Tier 2), deferred pending a
  transport decision (SSH vs Kerberos/GSSAPI).

## [1.6.1] - 2026-10-06

### Added

- **Switch to refresh endoflife.date data** (`eol_cache_refresh_enabled`,
  default on). Off: the scans use the stored data only and never call
  endoflife.date; the EOL Cache Refresh cron does nothing. "Refresh data
  older than" keeps its day count and applies while the switch is on.

### Changed

- **"Software categories to ignore" is a pick-list of the software categories
  defined in GLPI**, not a JSON text box. The stored value is still the list of
  category names, because the licence scan matches on the name. A name that
  GLPI does not have is dropped on save.

## [1.6.0] - 2026-10-06

Preparation for the endoflife.date API connection: until now a failed fetch
was invisible, counted only as `errors` on the run card.

### Added

- **`tools/net_check.php`** — CLI, read-only. Walks the real call path and
  reports the first step that fails: PHP curl/openssl, the GLPI proxy
  settings (never printing the password), GLPI's URL allowlist, DNS, a direct
  TLS connect on 443, the fetch through `Toolbox::getURLContent()` exactly as
  the cron does it, and the payload shape. Ends with what is at stake (cached
  rows, pairs to refresh, open `no_mapping` rows) and a remedy per failure.

### Fixed

- **A failed endoflife.date fetch now says why.** `Http::get()` discarded
  `Toolbox::getURLContent()`'s `$msgerr`, so a proxy failure, a DNS failure
  and a blocked URL were indistinguishable. The reason is kept in
  `Http::lastError()` and written to `files/_log/sac.log` per product.

### Changed

- Outbound requests send a `User-Agent` and an explicit connect timeout.
  GLPI's proxy settings are honoured as before (`Toolbox::getURLContent()`);
  the no-GLPI fallback path, which cannot use the proxy, says so when it
  fails.

## [1.5.3] - 2026-10-05

### Fixed

- **The Overview summary counted findings the scan no longer asserts.** After
  the per-entity split, the pre-1.5.0 Root findings carry a cleared note but
  stay Open in riskregister, so Root showed 47 "open" findings with 0 hosts,
  the total was doubled, and each signal counted one entity too many. The
  summary now leaves out a finding that carries a cleared note. Nothing in
  riskregister changes: closing those findings is still a human decision.

## [1.5.2] - 2026-10-05

### Changed

- **The group-level view of SAC's pages is for a Super-Admin only** (user
  decision), identified by UPDATE on core `config`. 1.5.1 used "sees every
  entity" for the view and "access to the root entity" for the Reconcile
  actions, which any profile granted recursively on Root would pass. Now
  only a Super-Admin gets the instance-wide scan counters and error text,
  the EOL Cache Refresh rows, every reconcile row, and the Map / Resolve /
  Re-open actions. Everyone else gets the per-entity view, whatever entity
  they are in. 1.5.1 was built and not reported deployed; deploy 1.5.2
  instead.

## [1.5.1] - 2026-10-05

### Changed

- **SAC's own pages follow the entities of the viewer** (entity audit). No
  schema change: a scan run is instance-wide and a reconcile row points at a
  global dropdown, so the entity is applied when reading (`EntityScope`).
  - *Overview / Scan runs*: a user who does not see every entity gets each
    run's time and status, but not its instance-wide counters or error text,
    and no EOL Cache Refresh rows. The open-reconcile count covers only the
    rows that user can see.
  - *Reconcile*: such a user sees only rows about an OS + version present on
    a Computer of their entities; publisher rows are group level.
  - Mapping, resolving and re-opening change shared reference data and now
    need access to the root entity on top of the UPDATE right.

### Deploy note

- **After every upgrade run `php bin/console cache:clear`.** GLPI does not
  recompile a changed Twig template in production, so without it the old
  screens keep being served. Seen on 1.5.0: the Setup page still showed the
  old table, with `scan_entities` as a text box holding `[]`, instead of the
  new cards with the entity picker.

## [1.5.0] - 2026-10-04

### Changed

- **Findings are written per entity of the affected assets, never
  recursive.** Until 1.4.x every Finding went to one configured entity
  (`finding_entity`, Root in production) with `is_recursive = 1`, so every
  sub-entity saw every SAC Finding and its host list. In this instance a
  sub-entity is another company. Now the scans still run instance-wide, but
  group the affected Computers by `entities_id` and upsert one Finding per
  `(entity, code)`, linking only that entity's Computers; counts, host list
  and severity are per entity.
- **Licences are counted per entity**: a licence covers installs in an
  entity only when that entity owns it, or an ancestor owns it with "child
  entities" on. A group-wide licence is one on Root, recursive.
- Settings `finding_entity` and `finding_recursive` are removed (stored
  values are deleted on install).

### Added

- **Entities to scan** (`scan_entities`, `scan_entities_recursive`): limit
  the scans to selected entities, with or without their child entities.
  Empty means every entity. Findings of an entity that is no longer scanned
  are kept as they are (no cleared note).
- **Overview: open findings by entity, and the signals affecting the most
  hosts.** Both read riskregister's findings through core's entity
  restriction, so a subsidiary's user sees its own entity only and a
  group-level user sees every entity plus the total.
- **Setup page redesigned**: three cards (Scan scope, End of life, License
  governance) with labels, hints and units instead of a table of raw keys;
  read-only for a profile without the UPDATE right.

### Also changed
- Shared `lib/pgnsuite/Menu.php` r2 (the sub-nav is hidden when printing).

### Existing findings

- Nothing is deleted. A pre-1.5.0 Finding keeps its id, status, history and
  derived risks in its entity; it becomes non-recursive and loses its links
  to other entities' Computers. Other entities get new Findings, status Open.
  If the old entity has no affected asset left, its Finding gets a cleared
  note.
- The scans do this for every Finding they still assert. New
  `tools/entity_isolate.php` reports every SAC Finding that is recursive or
  linked to another entity's Computers (dry run by default) and, with
  `--apply`, repairs those too — including Findings no scan touches any more.
  **Run the dry run before the first scan or selfcheck after the upgrade.**

### Fixed

- A failed endoflife.date fetch counted as an error with no log line; it now
  writes one to `files/_log/sac.log`.

## [1.4.1] - 2026-10-04

Found by the first full `tools/selfcheck.php` run on the VM (55 checks, 5
failed) — the first run since the tool could boot again in 1.4.0.

### Fixed

- **Super-Admin was not re-granted full SAC rights on an upgrade.**
  `RightDefinition::addOnInstall()` returned early once both rights existed,
  so the "Super-Admin selalu penuh" re-grant (suite rule, 2026-09-16) only
  ever ran on a first install. It now runs on every
  `plugin:install sac --force`; every other profile is still seeded only for
  rights created in that run.
- The Reconcile help text showed a literal `Map &amp; resolve`.

### Fixed (selfcheck only)

- `db/reconcile-resolve` and `db/reconcile-subid-distinct` used fixture
  `signal_type` values longer than the column (`varchar(20)`).
- `db/migration-eolmap-key` and `db/migration-reconcile-subid-key` read the
  column list from `$DB`'s cache, primed before the migration ran.
  `Migration::isUnsignedInt()` now reads uncached too.

## [1.4.0] - 2026-10-04

### Changed

- **One entry in the shared suite sidebar menu ("ICT Tools") instead of an
  own top-level sector** (hub §7 2026-10-03). "Security Assessment" is now
  entry `sac` (`order` 40) of sector `pgnsuite`; Overview / Reconcile / Scan
  runs became its options and are reached through a sub-nav printed under
  the page header. `GlpiPlugin\Sac\Menu` is now only the provider
  (`Menu::entry()`), returning null without `plugin_sac_finding` READ. Ships
  `lib/pgnsuite/Menu.php` r1 and `ConfigTab.php` r4, byte-identical to the
  canonical copies. **Users must log out and back in after the deploy** (the
  sidebar is cached in the session).
- `'author'` is now `'ICT'` (suite decision).

### Fixed

- **`tools/selfcheck.php` and `tools/export_findings.php` could not boot on
  GLPI 11**: they loaded `inc/includes.php`, which no longer loads anything,
  so plugin classes were never autoloaded. Both now use
  `vendor/autoload.php` + `Kernel::boot()`, as core `front/cron.php` does.
- **Labels written to riskregister were clipped by bytes, not characters.**
  `substr($s, 0, 255)` could split a multi-byte character ("×", "—") at the
  boundary and hand MySQL invalid UTF-8. New `Text::clip()` clips on
  characters; `FindingWriter::upsert()` also clips `scope_label` itself, so
  no caller can pass an over-long label.

## [1.3.6] - 2026-09-16

### Fixed

- **v1.3.5 called `ProfileRight::getProfileRight()`, which does not exist in
  GLPI 11 core** — the real API is the plural `getProfileRights($pid,
  array $rights): array`, keyed by right name, empty when the profile has
  no row. Every call site would have fataled with "Call to undefined
  method" on `plugin:install sac --force`, taking the install down with it.
  **v1.3.5 must not be deployed; production stays on 1.3.4 until this
  lands.** Caught before any deployment (peer review against GLPI's
  `11.0/bugfixes` source, `src/ProfileRight.php`) — `php -l` only parses
  and the offline test suite is pure-logic, so nothing local could catch a
  wrong core method name; the two new `db/` checks that would have hit it
  need a VM run to execute at all. Added a private
  `RightDefinition::readProfileRight()` wrapper around the correct plural
  API and routed every call site through it. No behavior change from the
  v1.3.5 design — only the broken API call is fixed.

## [1.3.5] - 2026-09-16

### Fixed

- **Super-Admin detection in `RightDefinition::addOnInstall()` keyed off the
  profile name, and was still filtered by `$toAdd`** — `$profile['name'] ===
  'Super-Admin'` breaks if the profile is renamed/localised, and filtering
  its seed through `$toAdd` meant a `--force` reinstall after rights already
  existed would never refresh it, risking an instance where nobody can
  configure the plugin. Per the user's "Super-Admin selalu penuh" directive
  (hub §4): a profile is now identified by capability — holds `UPDATE` on
  the core `config` right — and, once identified, always gets the plugin's
  full mask on every run, unfiltered by `$toAdd`. Every other profile stays
  `$toAdd`-only exactly as in v1.3.4. If no profile passes the capability
  test, nothing extra is seeded (no fallback to granting everyone full
  rights). Added `db/rightdefinition-super-admin-always-full` and adjusted
  `db/rightdefinition-preserves-tuned-matrix` to target a non-super-admin
  profile specifically.

## [1.3.4] - 2026-09-16

### Fixed

- **`RightDefinition::addOnInstall()` seeded rights off `$_SESSION` instead of
  the DB, and re-seeded on every run** (hub §4/§7) — a CLI
  `plugin:install sac --force` has no session, so a fresh install granted no
  profile any SAC right; a web reinstall/upgrade re-granted
  READ|UPDATE|CREATE|PURGE to the installer's profile, silently overwriting
  any hand-tuned rights matrix. Now computes `$toAdd` (rights not yet in
  `ProfileRight::getAllPossibleRights()`) and only ever seeds those: once a
  right exists, re-running `addOnInstall()` is a no-op. Seeding loops every
  profile from the DB — Super-Admin gets full access, everyone else inherits
  their existing core `config` mask — so a fresh CLI install seeds
  Super-Admin correctly with no session involved. Added a VM-only regression
  test (`db/rightdefinition-preserves-tuned-matrix`) that narrows a right,
  re-runs `addOnInstall()`, and asserts the narrowed value survives.

## [1.3.3] - 2026-09-14

### Fixed

- **Shared "PGN Suite" tab (`lib/pgnsuite/ConfigTab.php`) had an unreadable
  active sub-tab** — pale-yellow text on `.nav-pills` active state. Visual
  fix only, approved by the suite maintainers upstream (hub §5, canonical
  copy now r2, SHA-256 `347d56c91508e53326c8d4de74105628d0fd535bde619fe8a94eb147a448cbd6`).
  Same API, `CONTRACT_VERSION` unchanged at 1 — re-copied the canonical
  file, nothing else in SAC changes.

## [1.3.2] - 2026-09-14

### Fixed

- **`lib/pgnsuite/ConfigTab.php` was being silently corrupted by
  `core.autocrlf=true`** — the repo has autocrlf on, which rewrote this
  file's LF line endings to CRLF not only in the local working-tree
  checkout but also in `git archive` output, breaking the hub §5
  byte-identical-copy contract for a file that must match a canonical
  SHA-256 across every plugin in the suite. Confirmed the corruption
  reached the actual shipped `sacv1.3.1.zip` (its
  `sac/lib/pgnsuite/ConfigTab.php` hashed to `57c480c2...` instead of the
  canonical `89e864d9...`) — **`sacv1.3.1.zip` should not be deployed;
  use `sacv1.3.2.zip` instead.** The git-committed blob itself was always
  correct LF (`git show HEAD:...` hashed correctly); the corruption only
  happened on the way out, on checkout/archive. Fixed with a
  `.gitattributes` entry marking `lib/pgnsuite/ConfigTab.php -text`, so
  git never touches its line endings regardless of `core.autocrlf`.
  Verified via `git archive` from the fixed commit reproducing the
  canonical hash.

## [1.3.1] - 2026-09-14

### Fixed

- **v1.3.0's standalone config page always redirected to the shared PGN
  Suite tab after saving — stranding the one user it exists for.** A user
  with `plugin_sac_config` but not core `config` READ has no route to
  Setup > General at all; unconditionally redirecting them to
  `ConfigTab::getReturnUrl('sac')` on every save sent them somewhere they
  couldn't open (caught before this shipped to a VM, hub §5). Fixed per the
  suite-standard pattern: `SuiteConfig::render()` now takes a
  `$fromPgnSuite` flag (true only via the new `renderForTab()` entry point
  the shared tab actually calls), which adds a hidden `_from_pgnsuite`
  field to the form; `front/config.php`'s POST handler redirects to the
  shared tab only when that field is present, `Html::back()` otherwise.

## [1.3.0] - 2026-09-14

### Added

- **SAC's Setup screen joins the shared "PGN Suite" tab** on Setup > General
  (hub §5 contract) — one tab, one vertical sub-tab per plugin. New
  `lib/pgnsuite/ConfigTab.php` (byte-identical copy of the canonical suite
  file, SHA-256 verified before committing — never edit this file directly,
  changes go through the canonical copy) and `src/SuiteConfig.php`
  (`section()` gates on `plugin_sac_config` READ — `ConfigTab` itself only
  checks core `config`; `render()` echoes the same form as before).

### Changed

- **SAC's standalone "Setup" menu entry is gone** — config now lives in the
  shared tab. The old URL (`front/config.php`) still works: a user with core
  `config` READ is redirected straight to the shared tab (no divergent
  second copy of the form); a user with `plugin_sac_config` but not core
  `config` (so no route to Setup > General at all) still sees the standalone
  page directly. Saving from either entry point lands back on the shared
  tab with SAC's section active.

## [1.2.1] - 2026-09-14

### Changed

- **Overview page: the "EOLCACHE" card was confusing** — it rendered as a
  bare uppercased module key sitting alongside "EOL" and "LICENSE", reading
  as a 3rd security-signal category when it's actually the internal
  `EolCacheRefresh` cron that just refreshes the local `endoflife.date`
  mirror the EOL scan reads from. Every card now shows a proper title
  ("EOL / EOS", "License Governance", "EOL Cache Refresh") and a one-line
  description; the EOL Cache Refresh card's description explicitly says
  it's maintenance, not a signal. Also dropped its always-zero "Open
  reconcile" line, which never applied to that module (it never had its own
  reconcile queue) and only added to the confusion.

## [1.2.0] - 2026-09-14

### Added

- **License Findings now also link to their Software item, not just affected
  Computers** — riskregister already has a native "Findings" tab on Software
  (confirmed with risk-register-dashboard-c8, via `Finding_Item`
  `addtabon`), but SAC never created a link pointing there, so it stayed
  empty for SAC's own findings. `FindingWriter::upsert()` gained an optional
  `$extraLinks` parameter (`array<int,array{itemtype:string,items_id:int}>`)
  for `Finding_Item` links beyond Computer; `LicenseScan` now passes it for
  both **2b** (the specific over-deployed Software) and **2a** (every
  unlicensed product from that publisher). No new tab, no schema change, no
  migration — riskregister's own tab already existed, this just feeds it.
  `EolScan`'s calls are unaffected (the parameter defaults to empty).
- Considered and deferred a similar `OperatingSystem` tab: riskregister
  doesn't have OS in its linkable-itemtype candidate list today (would need
  a small change on their side), and EOL Findings are already visible via
  the Computer tab they're linked to — low marginal value for the added
  cross-plugin coordination. Revisit if there's a concrete need for an
  OS-centric view.

## [1.1.9] - 2026-09-13

### Added

- **`db/migration-reconcile-subid-key` selfCheck** — v1.1.7's unsigned-key
  fix for `_reconcile.ref_subid` had no direct test coverage (only
  `_eolmap.operatingsystemversions_id` did). `ref_subid` is specifically the
  column no name-based audit (the hub §4 query, possibly core's
  `migration:unsigned_keys`) will ever catch, since it doesn't match the
  `*_id` naming convention — so it's the one that most needed its own
  selfCheck guard. `migrateReconcileSubId()` gained the same optional `$t`
  table-name parameter `migrateEolmapVersionKey()` already had, so this new
  check can exercise it against a throwaway fixture table the same way.

## [1.1.8] - 2026-09-13

### Fixed

- **The actual root cause of EOL scan's "Error" status, finally visible
  thanks to v1.1.4's error-message surfacing:**
  `MySQL query error: Unknown column 'MAX(date_fetched)' in 'field list'`.
  `EolCache::isStale()` (called by `ensureFresh()` between `EolScan`'s two
  passes — on the hot path of every real run) passed
  `'SELECT' => ['MAX(date_fetched) AS newest']` to `$DB->request()`'s array
  query builder. The builder quotes each `SELECT` entry as a literal column
  identifier rather than treating it as a SQL expression, so the whole
  aggregate-plus-alias string got backtick-wrapped verbatim into an
  obviously-nonexistent column name. Rewritten to raw `doQuery()` +
  `fetch_assoc()` (the same pattern already proven in this file's own
  `staleProducts()`), with `$DB->quote()` (VM-proven since `LicenseScan`
  v1.0.0/v1.0.1) for the product value — which traces back to admin-typed
  Reconcile input, not just the shipped seed CSV.
- **Same broken `'SELECT' => ['MAX(id) AS m']` pattern found in 4 more
  places** (a repo-wide grep after finding the first one): the
  `db/writer-riskregister-inactive` selfCheck in `src/Cron.php`, the
  `db/eolcache-ensurefresh` selfCheck in `src/Eol/EolCache.php`, and the
  `scRunFloor()` helper duplicated in `src/Eol/EolScan.php` and
  `src/License/LicenseScan.php` (used by every one of their own selfCheck
  teardowns). None of these run during a normal cron tick, but every one
  would have crashed `tools/selfcheck.php` on first real use. All 5 fixed
  the same way.

## [1.1.7] - 2026-09-13

### Fixed

- **`operatingsystemversions_id` / `ref_subid` were signed, not unsigned** —
  suite-wide rule (`pgn-glpi-suite` §4 rule 2b, GLPI's Setup warning
  *"primary or foreign keys columns are using signed integers"*): GLPI's
  `\Migration::addField()`/`changeField()` only emit `UNSIGNED` for the
  `'fkey'` type keyword (or `'autoincrement'` for a PK) — `'integer'`/`'int'`
  silently produce a signed column even though this file's `CREATE TABLE`
  DDL has always said `int unsigned`. Both v1.1.0 upgrade-path `addField()`
  calls used `'integer'` — same bug class drillreport hit first (v0.8.5).
  Fixed: both now use `'fkey'`; added a `changeField()` retrofit for any VM
  that already upgraded through v1.1.0–v1.1.6 with the signed column,
  gated on a new `Migration::isUnsignedInt()` check so it's a no-op once
  fixed. `ref_subid` doesn't match GLPI's `*_id` naming convention, so it
  needed this explicit retrofit regardless of whether a later
  `migration:unsigned_keys` run would have caught it. `db/migration-eolmap-key`
  now also asserts the migrated column is unsigned.
- No negative sentinel values exist in any SAC key column (audited
  suite-wide) — nothing else to retrofit.

## [1.1.6] - 2026-09-13

### Added

- **Click-to-sort on the Reconcile and Scan-runs tables** — per the
  suite-wide UI rule (`pgn-glpi-suite`, generalizing riskregister's Reconcile
  pattern): any column carrying a single sortable value gets it, control
  columns don't. New shared partial `templates/_sortable-table.html.twig`
  (`{% include '@sac/_sortable-table.html.twig' %}`) — event-delegated
  (bound on `document`, not on the table itself), so it works regardless of
  where the script tag sits relative to the table markup, avoiding the exact
  ordering bug riskregister v1.5.1 hotfixed (a script querying the DOM at
  parse time, before its own table existed further down the page).
  - **Reconcile:** Status, Signal, Reason, Reference, Mapping, First seen,
    Last seen are sortable; Actions (buttons/forms) is not. The Reference
    column sorts on the plain label, ignoring its "(Itemtype #id)" suffix.
  - **Scan runs:** Module, Started, Ended, Status are sortable; Counts is
    not — it's several key/value pairs per cell, not one sortable value.

## [1.1.5] - 2026-09-13

### Fixed

- **Reconcile page's "Resolved"/"Open" badges had no explicit text colour**
  (`templates/reconcile.html.twig`) — unlike the status badges added in
  v1.1.3 elsewhere in the plugin. Two real contrast bugs, found on a
  suite-wide audit prompted by `pgn-glpi-suite-*`'s badge-contrast rule:
  the "Resolved" badge (`bg-success`) sat inside a row with
  `class="text-muted"`, so it inherited muted grey text on a green
  background instead of setting its own; the "Open" badge (`bg-warning`)
  set no text colour at all, so in a dark theme it likely rendered light
  text on its pale-yellow background. Both now set an explicit,
  contrast-correct colour: `text-white` for the green "Resolved" badge,
  `text-dark` for the yellow "Open" badge (warning/yellow backgrounds need
  dark text, unlike success/danger/secondary, which need white — the same
  pairing already used correctly for the OK/Error badges elsewhere in this
  plugin).

## [1.1.4] - 2026-09-13

### Added

- **A failed run's error message is now visible in the UI.** A run's `status`
  can be `error` (an uncaught exception escaped `EolScan`/`LicenseScan`
  entirely — a bug, distinct from the internal `errors` counter, which only
  counts caught/handled per-item failures) with the exception message
  stored in `glpi_plugin_sac_run.message` — but neither `front/overview.php`
  nor `front/run.php` ever passed that column to their templates, so an
  admin had no way to see *why* a run failed short of querying the database
  directly. Both front controllers now include `message`; both Overview
  (a small `alert-danger`) and "Scan runs" (red text under the status
  badge) render it when a run's `status` is `error` and it has one.

## [1.1.3] - 2026-09-13

### Fixed

- **Status pills still weren't readable after v1.1.2** — the Tabler
  `status`/`status-dot` classes used on both Overview and "Scan runs"
  render as a flat neutral-gray pill in this GLPI install's theme, with no
  colour distinction between `OK` and `Error` at all. Replaced with plain
  `badge bg-success` / `badge bg-danger` / `badge bg-secondary` + explicit
  `text-white` — the same colour classes already proven to render
  correctly (they already colour the Overview card's top bar) — on both
  `templates/overview.html.twig` and `templates/run.html.twig`.

## [1.1.2] - 2026-09-13

### Fixed

- **"Scan runs" page (`templates/run.html.twig`) was unreadable** — every
  count rendered as a dark `badge bg-secondary` pill (the same low-contrast
  issue Overview had before v1.0.2, but this page never got that fix). Now
  matches Overview's convention: theme-aware text (`text-muted` label /
  `text-body` value, no fixed background colour) instead of solid badges, a
  colour-coded `status`/`status-dot` pill for the Status column, human
  labels for every counter via the same `count_labels` map Overview uses
  (now including the v1.1.0 `skipped` / `cache_pending` counters, which this
  page didn't know about either), and a non-zero `errors` row rendered in
  red.

## [1.1.1] - 2026-09-12

First real v1.1.0 upgrade attempt on VM-HQ-GLPI failed with `Attempted to
call an undefined method named "indexExists" of class "DB"` inside
`Migration::migrateEolmapVersionKey()`.

### Fixed

- **`src/Migration.php`** — GLPI's `DB`/`DBmysql` class has no `indexExists()`
  method; both schema-migration helpers (`migrateEolmapVersionKey()`,
  `migrateReconcileSubId()`) called it directly and crashed the upgrade
  before any of the v1.1.0 schema/data migration ran. Replaced with a new
  `Migration::keyExists()` using a direct `SHOW KEYS FROM <table>` query,
  which needs no database/schema name at all (also sidesteps hardcoding
  `TABLE_SCHEMA = 'glpi'` — VM-HQ-GLPI's actual database is `itsm`, per the
  suite-wide `nvd` 2.4.1 gotcha). `Migration::install()` is idempotent, so a
  VM that hit this crash mid-upgrade (partially migrated: the
  `operatingsystemversions_id` column may already be present, the key swap
  and everything after it did not run) just needs `install()` re-run —
  no manual cleanup required.

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

## [1.0.4] - 2026-09-10

### Added

- Reconcile page: an inline help panel — what an `eol` / `no_mapping` row means,
  how to fill `product` / `cycle` (with an endoflife.date link + examples), the
  LifeCycle-tab alternative, and what plain "Resolve" does.

## [1.0.3] - 2026-09-10

### Added

- **Reconcile page: "Re-open" button on resolved rows** — sends a signal back to
  the open queue so a wrong mapping can be corrected. `Reconcile::reopen()`.
- **"Mapping" column** — every OS reconcile row shows its current
  `_eolmap` product/cycle (`—` when unmapped). The inline "Map & resolve" form is
  now **pre-filled** with the existing mapping, so re-mapping is an edit, not a
  retype. `EolMap::put()` already upserts, so the next `EolScan` updates the
  affected Finding in place.

## [1.0.2] - 2026-09-10

### Added

- **Reconcile page: "Show resolved" toggle** (`?resolved=1`). Resolved rows carry
  a green "Resolved" badge, greyed text, and no action buttons; open rows a
  yellow "Open" badge. `listForDisplay()` now returns `is_resolved` per row.

### Changed

- **Overview cards rebuilt on Tabler components** (`card-status-top`, `status`
  dot, `datagrid`) instead of dark `badge bg-secondary` pills — readable in both
  light and dark themes. Counters get human labels ("Findings written",
  "Reconcile added", …); a non-zero error count renders red.

## [1.0.1] - 2026-09-10

First real deploy on VM-HQ-GLPI (GLPI 11) surfaced three schema-drift bugs.

### Fixed

- **EOL hardware pass** queried `glpi_computers.warranty_date` /
  `warranty_duration` — those columns do not exist in GLPI 11. Warranty data
  lives in `glpi_infocoms` (keyed by `itemtype` + `items_id`). `runHardware()`
  now joins `glpi_infocoms`, is `tableExists`/`fieldExists`-guarded, skips
  `warranty_duration <= 0` (permanent / none), and the call is wrapped so a
  hardware-pass failure logs + bumps `errors` instead of failing the whole EOL
  run (the OS pass already stands).
- **License aggregate query** used `MIN(NULLIF(`expire`, '0000-00-00'))` — MySQL
  in `NO_ZERO_DATE` / strict mode rejects the `'0000-00-00'` DATE literal, so
  `LicenseScan` aborted on the first software with a legacy zero expiry. Now
  `CAST(`expire` AS CHAR)` before the compare. The per-software loop is also
  wrapped in try/catch so one bad row bumps `errors` and continues.
- `reconcile_open` run counter is now bumped with the count of OSes routed to the
  Reconcile queue this run (was always `0`).

### Changed

- Overview cards: counters render as a plain two-column table instead of dark
  low-contrast badges; the status badge is colour-coded (`ok` green, `error`
  red).
- `LifeCycleReader` date-column probe tries **`endoflifefield`** first —
  pluginsGLPI/fields `Field::prepareName()` appends `field` to the label-derived
  name, so "EndOfLife" becomes `endoflifefield` (falls through to `endoflife`,
  `endoflife_date`, then the sole non-audit date column). **VM-confirmed**: table
  `glpi_plugin_fields_operatingsystemlifecycles`, column `endoflifefield`
  (`varchar(255)`), keyed `items_id` + `itemtype = 'OperatingSystem'`.

## [1.0.0] - 2026-09-10

Released `sacv1.0.0.zip`. First tagged release — code-complete, **never run on a
real GLPI instance** (mode-A); the deploy must follow the README VM verification
checklist.

### Fixed

- Config screen: `finding_entity` (and every other key) now renders from an
  explicit `Config::FIELD_TYPES` map instead of guessing checkbox-vs-text from
  the default value — an int-`0` entity id is no longer silently reset to 0 on
  Save. `front/config.php` validates every posted value per type (bool / int /
  entity / JSON array) and rejects bad input with an error message.
- Setup → Profiles rights tab: ported to the riskregister approach — a real
  `<form>` posting to core `front/profile.form.php`, so rights actually persist;
  removed the `\Profile::getRightsFor()` call that could fatal on GLPI 11.
- `tools/selfcheck.php` / `tools/export_findings.php`: refuse non-CLI (HTTP)
  invocation.
- `FindingWriter::appendClearedNote()` is idempotent per day — no more unbounded
  `content` growth / repeated `findings_cleared` when a signal stays cleared.
- EOL hardware pass and the License candidate/count queries now filter
  `is_template` / `is_deleted` consistently.
- Per-iteration `Config::get()` / category-id lookups hoisted out of the scan
  loops.
- License candidate query: ignored-category names are `$DB->quote()`d verbatim
  (no character stripping) so accented names keep their ignore rule.
- Ambiguous / unresolvable commercial-publisher names get a distinct
  `ref_id = crc32(name)` on the Reconcile row so they no longer collide; they
  are **logged to the Reconcile queue for manual DB correction** — there is no
  "re-seed from Setup" workflow.
- `LifeCycleReader`: `items_id` query guarded by `fieldExists`, pinned to
  `itemtype = 'OperatingSystem'` when that column exists, and the table LIKE
  fallback constrained to `glpi_plugin_fields_operatingsystem%lifecycle%` so it
  cannot latch onto a Computer-attached container.
- selfChecks: EOL-1 now exercises the LifeCycle path only; added
  `db/eol-lifecycle-wins`, `db/eol-bad-date`, `db/writer-riskregister-inactive`
  (spec §9.2 WRITER-1). `db/` seeds create core rows via `->add()` (captured
  ids) instead of hardcoded ~2.1e9 ids.

### Added

- Plugin scaffold: PSR-4 `GlpiPlugin\Sac\` → `src/`, own top-level menu sector
  (`sac`), 2 rights (`plugin_sac_finding`, `plugin_sac_config`), 14 `plugin:sac`
  config keys, 5 tables (`glpi_plugin_sac_{eolmap,eolcache,commercialpublishers,reconcile,run}`).
- Module 1 — EOL/EOS: per-OS end-of-life resolution (`fields` "LifeCycle" date →
  `endoflife.date` cache fallback → Reconcile queue when neither resolves); one
  `Finding` per OperatingSystem graded against the configured horizon; a
  hardware-warranty EOL pass (`warranty_date + warranty_duration`), gated on
  `eol_hardware_enabled`.
- Module 2 — License Governance: **2a** missing-license-record Findings (one per
  commercial publisher with unlicensed products, always on); **2b**
  over-deployment Findings (installs > licensed seats), gated on
  `license_check_overdeployment`.
- Reconcile queue + UI (`front/reconcile.php`) for unmapped OS EOL signals and
  ambiguous publisher names, with a "confirm mapping" / "resolve" workflow.
- `tools/export_findings.php` — CSV export of emitted Findings for audit /
  server-refresh KAK evidence.
- 3 weekly cron tasks: `GlpiPlugin\Sac\Cron::{EolScan, EolCacheRefresh,
  LicenseScan}`.
- `tools/selfcheck.php` (full VM self-check runner, bootstraps GLPI and runs
  every `GlpiPlugin\Sac\*::selfChecks()` including the `db/` fixtures) and
  `tools/offline-selfcheck.php` (dependency-free pure-logic subset).



