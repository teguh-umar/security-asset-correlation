# SAC Module 2 — licence classification and review queue

**Status:** design, awaiting approval · **Target:** SAC 1.9.0 · **Date:** 2026-10-25

## Intent

Module 2 decides whether installed software needs a licence by looking at its
**publisher**. On Oracle Linux that is wrong in a way that destroys the module's
usefulness, and the data says so plainly.

Measured on `vm-dev-damara` (Computer 136) on 2026-10-25:

| | |
|---|---|
| Software rows whose manufacturer is `Oracle` | **817** |
| Of those, versioned as a distro release (`.el9`, `+el9`) | **817** |
| Genuine commercial Oracle products among them | **0** |

`Oracle` is on the seeded commercial-publisher watch list
(`install/data/commercial_publishers.csv`), and the GLPI agent records the vendor
of every RPM on Oracle Linux as exactly `Oracle` — the match is case-insensitive
equality on the manufacturer name, so it hits. Every OS package therefore becomes
an "unlicensed commercial software" Finding. The same trap waits on any RHEL host
(`Red Hat` is on the same list) and on IBM-distributed stacks.

The failure is not the list's contents. It is the **granularity**: a publisher is
not a licensing decision. Within one publisher, editions differ — MySQL Community
is GPL and free, MySQL Enterprise is a subscription; OpenJDK is free, Oracle JDK
depends on the version; the VirtualBox base package is free, its Extension Pack is
not. No list of publishers can express that, and neither can a list of products.

This replaces the publisher watch list as the **decision** with an admin-editable
classification of software, and sends anything it cannot classify to a review
queue instead of asserting something false about it.

## Goals

1. **No Finding is raised for software that needs no licence.** The 817 false
   Findings on `vm-dev-damara`, and their equivalents on every other Linux host,
   stop existing.
2. **Nothing unknown is silently dropped.** Software from a commercial publisher
   that matches no rule stays visible in a review queue. Invisible is not the
   same as resolved — the same principle Module 3 Tier 2 is built on.
3. **The classification is legible to someone who was not in this conversation.**
   It is maintained by the user's team *and* the licence team; a rule must explain
   itself to whoever opens it next.
4. **The licence team decides for themselves.** They hold GLPI accounts and are
   the subject-matter owners; the queue is their screen, not a worklist the ICT
   team relays by email.

## Non-goals

- **Licence expiry and contract dates.** The licence team owns those and is
  migrating licence and contract records into GLPI now. SAC must not track,
  warn about or duplicate them. SAC reports one thing: software that needs a
  licence record and has none.
- **Product end-of-life.** That is Module 1, sourced from `endoflife.date`, and is
  unaffected. A product can be supported but unlicensed, or licensed but past
  end-of-support; the two must not be conflated.
- **Automatic licence purchasing advice, cost, or entitlement counting.** Module 2
  already does over-deployment (2b); that is unchanged by this work.

## Anti-goals

Things that would make this a failure even if it technically works:

- **A rule that silences software nobody looked at.** A broad pattern approved
  without seeing what it covers is how a real licence gap disappears. The flow
  must make the blast radius visible before approval.
- **A review queue that grows unread.** If unknown software accumulates with
  nobody accountable, this has replaced false Findings with a different silence.
- **SAC and the licence team disagreeing about the same software.** One answer,
  in one place.
- **A rule nobody can explain.** If someone opens the table in six months and
  cannot tell why a line is there, the table has failed regardless of accuracy.
- **A stale Finding left Open forever.** Retiring the old per-publisher Findings
  is part of the work, not a side effect of it.

## Constraints

- **Maintained across teams.** The user's team and the licence team both edit it.
  No implicit knowledge.
- **Visibility and editing go through the existing `plugin_sac_finding` right**
  (the user's decision). No new SAC right is introduced. **Consequence, accepted
  knowingly:** licence-team accounts holding that right also see SAC's other
  pages, including Config compliance, Reconcile, Scan runs, the registered SSH
  target list and the configured key paths. The user chose this with that
  consequence stated. It removes the need for a new right, a new menu gate and a
  right-seeding migration.
- **A decision takes effect immediately.** No approval step, no staged
  activation. The licence team's judgement is final at the moment they record it.
- **riskregister 2.1.0 is on production** and already receives SAC Findings.
- **Version-pattern matching alone is not safe.** Evidence: a `.el<N>` rule misses
  RHEL *modular* packages, which are versioned `8.1.27-1.module+el9.3.0+90130+…`
  — note `+el9`, not `.el9`. Eleven PHP packages on `vm-dev-damara` take exactly
  that form. A rule written on the obvious pattern would leave precisely those as
  Findings, with no clue why PHP alone survived. **This case is a required test.**
- **The existing ignore-categories setting cannot solve this and must stay.**
  763 of the 817 packages sit in the `Unspecified` software category, a catch-all
  that would also hide genuinely unknown software. It remains as an admin
  override, not as the fix.

## Decisions taken in the conversation

These are the user's, recorded as they were made:

1. **Classification replaces the publisher list as the decision.** Rules match on
   package name, and on version where needed.
2. **Classes: `free` / `needs licence` / `review`.** *(The "subscription" class
   from the original direction is folded into `needs licence` — my call, below.)*
3. **Unmatched software from a commercial publisher goes to the review queue, not
   to a Finding.**
4. **Flow is: mark, then decide.** A queue entry can be flagged and left for
   someone who knows, and decided later.
5. **A decision applies to that package first; the system then offers to
   generalise it into a rule**, showing exactly which other packages the proposed
   pattern would cover, before it is approved. (Chosen over "the decision becomes
   a rule automatically" and over "one decision per package, never generalised".)
6. **Every rule carries a mandatory reason**, in free text, e.g. "distro package
   on OL9, not a paid product".
7. **Rights: reuse `plugin_sac_finding`.** No new right. See the constraint above
   for the consequence.
8. **Decisions take effect immediately.**
9. **The licence team opens the queue and decides in it directly.**
10. **Rules are per-entity with inheritance.** A rule belongs to an entity and
    applies to its sub-entities, the way SAC's other per-entity data already
    works. A subsidiary can classify software for itself without changing the
    group's policy.
11. **One Finding per software**, replacing today's one-Finding-per-publisher
    aggregation (`SAC-LIC-MISSING-<manufacturers_id>`). Each unlicensed software
    is its own Finding, assignable and closable on its own.

## Calls I made, for the user to check

- **"Subscription" is not a separate class.** Both subscription and perpetual
  software need a licence record in GLPI; what differs is the record's expiry,
  which belongs to the licence team and to GLPI contracts, not to SAC. Keeping a
  third class would invite SAC to track expiry and duplicate their work.
- **Classification is evaluated per installed version, not per `Software` row.**
  The evidence this design rests on (`.el9` versus `module+el9.3.0`) lives in
  `glpi_softwareversions.name`, one level below `Software`. A rule that cannot see
  a version cannot do the job. **If any installed version of a software needs a
  licence and no licence record exists, that is a gap** — a mixed estate is not an
  excuse to stay silent.
- **The rule decides; the publisher list only decides what enters the queue.** A
  `needs licence` rule fires regardless of whether the publisher is on the list.
  The alternative — a rule that is silently inert because a manufacturer row is
  blank or misspelled — is the same class of trap this design exists to remove.
- **Software with no publisher recorded (`manufacturers_id = 0`) stays out of the
  queue in 1.9.0**, as it is out of scope today. This is an accepted blind spot,
  written down so it is inherited on purpose rather than by accident; a commercial
  product can sit there. Revisit when the queue has a known volume.
- **The blast-radius preview covers all installed software the approver can see,
  not only queued rows.** Test case 5 is the floor; anti-goal 1 is the purpose. A
  pattern can also shadow an existing rule or reclassify software that never
  queued, and the approver must see that before approving.
- **Software in an ignored category is excluded before classification**, so it
  never reaches the queue.
- **This is SAC 1.9.0**, built after 1.8.0 (released 2026-10-25), not merged into
  it.

## Consequences of decisions 10 and 11 that the plan must handle

- **Per-entity rules change match resolution.** "Most specific match wins" now has
  two axes: pattern specificity and entity distance. The plan must state which
  wins when they disagree — a precise rule in a parent entity against a looser one
  in the child — and the screen must show which rule actually decided.
- **Per-entity rules interact with the preview.** An entity-restricted account can
  approve a pattern whose true blast radius includes rows it cannot see. The
  preview must either be computed within the approver's visible scope and say so
  plainly, or state the out-of-scope count without disclosing the rows.
- **One Finding per software retires the old code scheme.** Today's
  `SAC-LIC-MISSING-<manufacturers_id>` Findings are cleared by
  `FindingWriter::clearExcept()`, which takes one exact code and is driven by a
  loop over the active publisher list. Once Findings are keyed per software, and
  once the publisher list stops gating, nothing visits the old codes again and
  they would sit Open forever on production riskregister. **1.9.0 therefore needs
  a one-shot retirement step** that enumerates the existing `SAC-LIC-MISSING-*`
  Findings and clears them through the normal path, dated and auditable, before
  the new scheme writes anything.
- **Severity needs rethinking.** The existing 2a severity comes from
  `license_2a_products_threshold` / `license_2a_installs_threshold`, which count
  products and installs within one publisher Finding. Per-software Findings have
  no such aggregate. The plan must say what severity a single unlicensed software
  carries, and whether the thresholds survive in another form.
- **Finding volume rises.** Three unlicensed Oracle products used to be one
  Finding; now they are three. This is the user's choice, made for assignability.

## Input received after this document was handed over

From the suite orchestrator, 2026-10-26, offered as input to the plan rather
than as decisions. Recorded here so the plan does not have to rediscover them;
the user has not ruled on either.

- **On the two axes: the nearer entity wins first, then the more specific
  pattern.** The reasoning is that a local rule is a deliberate override of an
  inherited default, so an inherited broad rule beating a local exact one would
  surprise whoever wrote the local one. The orchestrator also suggests putting
  the chosen order **and a worked example on the Setup page itself**, since other
  teams maintain the table — which serves goal 3 directly.
- **On retiring `SAC-LIC-MISSING-*`: close them through `FindingWriter` with a
  cleared note, never delete.** A human may have promoted one to a Risk or linked
  controls to it, and riskregister keeps that history; deleting would destroy it.
  **The step must be idempotent and safe to re-run**, because `plugin:install
  --force` is the supported upgrade path and the install function therefore runs
  on every upgrade — an unguarded retirement would re-run on each release. Guard
  it with a marker in the plugin's config, read before and written after, so a
  failed run retries. Count what it closed in the run log.

## Left to the builder

- **Rule shape and matching.** Whether a rule is a glob, a prefix, or a small
  pattern language; whether version matching is a second optional field; how a
  rule records that it matched on name versus name+version.
- **How specificity is computed and displayed**, and how ties are surfaced (the
  two-axis question above must be *answered*, but the mechanism is open).
- **Where the queue and table live** in the menu, and how they relate to the
  existing SAC pages.
- **Seed rules.** Seed only what is certain: distro-release packages (`.el<N>`
  *and* the modular `+el<N>` form) = free; MySQL Community = free; MySQL
  Enterprise = needs licence; `*-openjdk*` = free; Oracle Database EE/SE = needs
  licence. Anything uncertain — Oracle JDK, which depends on version — is
  deliberately left out for the admin to decide. Debian/Ubuntu equivalents only if
  a Debian-family host is actually monitored.
- **How the queue avoids becoming unread.** The anti-goal names the risk; the
  mechanism (a count on the dashboard, an age column, a digest) is open.
- **Whether `commercial_publishers.csv` survives** as the queue's entry trigger —
  its only remaining job — or is replaced.

## Test cases that must exist

1. A distro package versioned `.el9` is classified free and raises no Finding.
2. A **modular** distro package versioned `…module+el9.3.0+…` is classified free.
   This is the case a naive pattern misses.
3. `mysql-community-server` is free; `mysql-commercial-server` needs a licence.
   Same publisher, same product family, opposite answers.
4. Software from a commercial publisher matching no rule produces a queue entry
   and **no Finding**.
5. Approving a generalised rule shows the exact list of software it will cover
   before it is applied, including software not currently in the queue.
6. A rule saved without a reason is rejected.
7. A child-entity rule and a parent-entity rule both matching the same software
   resolve deterministically, and the screen names the rule that decided.
8. After the retirement step, no `SAC-LIC-MISSING-<manufacturers_id>` Finding is
   left Open.
9. A software whose installed versions are mixed — one matching a `free` rule,
   another matching none — does not silently become free.
