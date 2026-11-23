# SAC compared with grcmanager

> Why SAC is not replaced by grcmanager: grcmanager has no EOL tracking, no license compliance and no OpenSCAP results (section 2). SAC keeps the data; grcbridge sends the KRI `sac_eol_pct` to grcmanager.

> This is a copy of `docs/PLUGIN-REFERENCE.md` in the grcbridge repository, which is the maintained version. Update that file first, then copy it here.

Reference for the ORG suite plugins that grcbridge connects to grcmanager. It answers one question: can
grcmanager replace riskregister, SAC or NVD? **No.** grcmanager is a GRC/ISMS layer that overlaps partly
with riskregister and barely with SAC and NVD, so our plugins keep collecting and storing the data and
grcbridge sends the results to grcmanager.

- Compared versions: grcmanager 2.53.0, riskregister 2.2.0, SAC 1.8.0, NVD 2.7.x.
- Date: 2026-11-17.
- Method: reading the grcmanager 2.53.0 code (staging copy) and the suite's integration map. Nothing here
  was measured on production data. Not tested: grcmanager's fleet-wide vulnerability watch and the
  remediation deadlines added in 2.47. riskregister's internals were not re-audited for this document.

## Summary

| Our plugin | Replaceable by grcmanager? | Why |
|---|---|---|
| SAC | **No** | No EOL tracking, no license compliance, no OpenSCAP in grcmanager |
| NVD | **Only a small part** | Software CVEs only, from an admin-declared catalog; no operating-system CVEs |
| riskregister | **Not safely** | Other plugins depend on its Finding contract |

## 1. NVD vs grcmanager (vulnerability matching)

| Capability | NVD plugin | grcmanager 2.53.0 |
|---|---|---|
| Operating-system CVE matching (Windows Server, main Linux distros) | **Yes**, with automatic OS classification | **No**: no OS-matching code found |
| How assets are matched | Automatic CPE classification from inventory | Only software named in an **admin-declared CPE catalog**, no automatic guess |
| Asset scope | Computers, phones | Every software-capable item type |
| When matching runs | Scheduled task | **On demand**, when a CVE is reviewed on a security incident (the code comments say this is by design) |
| Extra threat data | NVD | Adds CISA KEV, EPSS and CERT-FR |
| Fleet remediation deadlines | No | Added in 2.47 (not tested) |
| Feeds the KRI `nvd_critical_os` | **Yes** | No equivalent data |

Verdict: only partly replaceable. OS CVEs are NVD's core job and are not covered.

## 2. SAC vs grcmanager (security assessment)

| Capability | SAC | grcmanager 2.53.0 |
|---|---|---|
| End-of-life (EOL) tracking | **Yes** | **No** (no match in the code) |
| Software license compliance | **Yes** | **No** (`SoftwareLicense` is never used) |
| OpenSCAP results | **Yes** | **No** (the only "oscap" hits are the word "Cytoscape", a graph library) |
| Feeds the KRI `sac_eol_pct` | **Yes** | No equivalent data |

Verdict: not replaceable.

## 3. riskregister vs grcmanager (risk register)

| Capability | riskregister 2.2.0 | grcmanager 2.53.0 |
|---|---|---|
| Core risk register (owner, status, treatment, residual) | Yes | Yes |
| Rating scale | 5x5 likelihood x impact | 4x4 matrix |
| Treatment actions, signed acceptance, risk appetite | Partial | **Yes** |
| Review reminder mails | Yes | Yes, only for risks that have a review date |
| CSV import and export | No | **Yes** (since 2.46, keyed by an external reference) |
| Entity scoping per risk | Yes | Yes (since 2.53) |
| ISMS consistency checks (22 audit-readiness rules) | No | **Yes** (since 2.50) |
| **Finding contract** used by other plugins | **Yes** (`Finding`, `Finding_Item`, framework-control links) | No |
| Asset links and Tools ICT asset sections | **Yes** | Different mechanism |
| Dependants in our suite | SAC (56 references), backupinfo (10), drillreport (4), grcbridge, datainjection, pdf | None of our plugins |

Verdict: the largest overlap, but not safely replaceable. Replacing riskregister would mean rewriting every
plugin that creates or reads its Findings, plus mapping the 5x5 scale to 4x4. That mapping already exists
inside grcbridge's `risk_sync` (the Risk Register level is kept in all 25 cells).

## Recommendation

Keep the current split: our plugins collect and store the data, grcbridge sends the results, grcmanager
is the GRC/ISMS layer. If riskregister is ever retired, grcmanager's CSV import (since 2.46) is the
migration path; it would need testing with real riskregister data first.

## How grcbridge uses this split

| Source | Direction | What grcmanager receives |
|---|---|---|
| `sac_eol_pct`, `nvd_critical_os`, `backup_unprotected_pct`, `drill_overdue_actions` | one way | A daily value into a manual KRI the admin mapped |
| `risk_sync` | two way for title, treatment, status, owner | Risk Register risks as grcmanager risks, in the same entity |

See grcbridge's README for the rules (governance in Risk Register, entities, consistency-check flags).
