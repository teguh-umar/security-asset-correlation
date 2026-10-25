#!/usr/bin/env bash
#
# SAC Module 3 Phase 1 (Config Compliance, Tier 1) — host-side collector.
#
# Runs on the MONITORED host (not the GLPI server), as root via cron.
# Evaluates a CIS profile with `oscap`, then writes a summary line plus one
# line per FAILING rule to
# $OUTPUT_FILE in the exact format GlpiPlugin\Sac\Compliance\ScoreLine
# expects:
#
#   SACCFG1|<benchmark_id>|<score 0-100>|<pass>|<fail>|<other>|<timestamp>
#
# The GLPI host reads this file over a restricted, forced-command SSH key
# (`command="cat <this path>"` in pgnusr's authorized_keys) — it never runs
# oscap itself, never logs in interactively, never gets a shell. This
# script is the only thing that ever writes the file; the SSH key can only
# read it.
#
# Deploy: copy to /usr/local/sbin/sac-oscap-summary.sh on the host,
# `chmod 750 /usr/local/sbin/sac-oscap-summary.sh`, then a root crontab
# entry (weekly, offset from the GLPI-side ConfigScan cron so the file is
# never more than a few hours stale when SAC reads it):
#
#   0 2 * * 0 /usr/local/sbin/sac-oscap-summary.sh >>/var/log/sac-oscap-summary.log 2>&1
#
set -euo pipefail

# ---- Configuration -----------------------------------------------------
# Override any of these by exporting the variable before calling the
# script, or editing the defaults below once content/profile are confirmed
# on this host.

OUTPUT_FILE="${SAC_OUTPUT_FILE:-/var/lib/sac/oscap_summary.txt}"
OUTPUT_OWNER="${SAC_OUTPUT_OWNER:-root:pgnusr}"
OUTPUT_MODE="${SAC_OUTPUT_MODE:-640}"

# SSG datastream content file. Auto-detected if not set: picks the first
# ssg-*-ds.xml under the standard RHEL/CentOS/Rocky/Alma path.
CONTENT_FILE="${SAC_CONTENT_FILE:-}"
CONTENT_GLOB='/usr/share/xml/scap/ssg/content/ssg-*-ds.xml'

# CIS profile id. Auto-detected if not set: prefers a Level 1 Server
# profile (id without "_l2"/"level2") over whatever else matches "cis".
PROFILE_ID="${SAC_PROFILE_ID:-}"

WORKDIR="$(mktemp -d /tmp/sac-oscap.XXXXXX)"
trap 'rm -rf "$WORKDIR"' EXIT

log() { echo "[sac-oscap-summary] $*" >&2; }

# ---- Pre-flight ----------------------------------------------------------

command -v oscap >/dev/null 2>&1 || { log "oscap not found — install openscap-scanner"; exit 1; }
command -v python3 >/dev/null 2>&1 || { log "python3 not found — needed to parse the XCCDF results"; exit 1; }

if [ -z "$CONTENT_FILE" ]; then
    # The content dir can hold several ssg-*-ds.xml files side by side —
    # the OS benchmark plus product-specific ones (Firefox, Java, MySQL,
    # ...). A plain `head -n1` on an alphabetic listing can pick the wrong
    # one (e.g. "ssg-firefox-ds.xml" sorts before "ssg-ol9-ds.xml" on a
    # real host this was tested against) and silently scan a browser
    # policy instead of the OS. Exclude known non-OS product content so
    # the first remaining match is the OS datastream.
    EXCLUDE_PATTERN='firefox|chromium|java|jre|webmin|mysql|postgres|mariadb|httpd|kubernetes|openshift|example'
    # shellcheck disable=SC2086 — intentional glob expansion, single word expected
    CONTENT_FILE=$(ls $CONTENT_GLOB 2>/dev/null | grep -Ev "$EXCLUDE_PATTERN" | head -n1 || true)
fi
if [ -z "$CONTENT_FILE" ] || [ ! -f "$CONTENT_FILE" ]; then
    log "No SSG datastream content found (looked for $CONTENT_GLOB). Set SAC_CONTENT_FILE explicitly."
    exit 1
fi
log "Using content: $CONTENT_FILE"

# The product-exclusion list above can tell OS content from browser content,
# but not one OS VERSION from another: on an OL9 host carrying only
# ssg-ol7-ds.xml it picks the ol7 file and oscap then reports every rule as
# notapplicable. Observed on vm-hq-glpi, 2026-10-22: 978 rules, 0 pass,
# 0 fail, score 0.00. Warn loudly; the hard stop is the pass+fail check below,
# which catches the same thing without having to know OS naming conventions.
if [ -r /etc/os-release ]; then
    . /etc/os-release
    OS_TAG="${ID:-}${VERSION_ID%%.*}"
    case "$(basename "$CONTENT_FILE")" in
        *"$OS_TAG"*) : ;;
        *) log "WARNING: content $(basename "$CONTENT_FILE") does not look like it matches this host ($OS_TAG). Rules may all come back notapplicable. Install the matching scap-security-guide content, or set SAC_CONTENT_FILE." ;;
    esac
fi

if [ -z "$PROFILE_ID" ]; then
    # Extract any profile id substring anywhere in `oscap info`'s output,
    # instead of anchoring on an exact "Id: " line format — that anchor
    # missed real output (indentation varies by oscap/content version) and
    # silently returned zero matches on a tested host.
    ALL_PROFILES_RAW=$(oscap info "$CONTENT_FILE" 2>/dev/null \
        | grep -oE 'xccdf_org\.ssgproject\.content_profile_[A-Za-z0-9_-]*' \
        | sort -u)

    mapfile -t CIS_PROFILES < <(echo "$ALL_PROFILES_RAW" | grep -E '^xccdf_org\.ssgproject\.content_profile_cis' || true)

    PROFILE_ID=""
    for p in "${CIS_PROFILES[@]}"; do
        case "$p" in
            *_l2*|*level2*) continue ;;
            *) PROFILE_ID="$p"; break ;;
        esac
    done
    [ -n "$PROFILE_ID" ] || { [ "${#CIS_PROFILES[@]}" -gt 0 ] && PROFILE_ID="${CIS_PROFILES[0]}"; }

    if [ -z "$PROFILE_ID" ]; then
        # No CIS profile on this content — known gap for some SSG content
        # sets, e.g. Oracle Linux's own ssg-ol9-ds.xml ships ANSSI/CUI/E8/
        # HIPAA/OSPP/PCI-DSS/standard/STIG but no CIS profile at all.
        # STIG is explicitly OUT per this project's suite directive (CIS/
        # ISO only, never DoD STIG) — never auto-selected even as a
        # fallback. Fall back to the content's own generic "standard"
        # profile, which exists across SSG content sets as the baseline
        # OS hardening profile.
        log "No CIS profile in $CONTENT_FILE — falling back to the 'standard' profile (STIG is intentionally never auto-selected). Available profiles were:"
        echo "$ALL_PROFILES_RAW" | sed 's/^/  /' >&2
        PROFILE_ID=$(echo "$ALL_PROFILES_RAW" | grep -E '_standard$' | head -n1 || true)
        if [ -z "$PROFILE_ID" ]; then
            log "No 'standard' profile either. Set SAC_PROFILE_ID explicitly to one of the ids listed above (never a *_stig* one)."
            exit 1
        fi
    fi
fi
log "Using profile: $PROFILE_ID"

# ---- Run the scan ----------------------------------------------------------
# --results is the XCCDF TestResult XML we parse below. Exit code 2 from
# oscap means "scan completed, at least one rule failed" — that is a NORMAL
# outcome (most hosts fail some rules), not a script error, so it is
# explicitly tolerated. Exit codes 0 and 1 (genuine error) are not.

RESULTS_XML="$WORKDIR/results.xml"
set +e
oscap xccdf eval \
    --profile "$PROFILE_ID" \
    --results "$RESULTS_XML" \
    "$CONTENT_FILE" >"$WORKDIR/stdout.log" 2>&1
OSCAP_RC=$?
set -e

if [ "$OSCAP_RC" -ne 0 ] && [ "$OSCAP_RC" -ne 2 ]; then
    log "oscap exited $OSCAP_RC (not 0=clean or 2=some rules failed) — treating as a real error"
    cat "$WORKDIR/stdout.log" >&2
    exit 1
fi
[ -f "$RESULTS_XML" ] || { log "oscap did not produce $RESULTS_XML"; exit 1; }

# ---- Parse the XCCDF results into one SACCFG1 line ------------------------
# python3's stdlib xml.etree is used deliberately over grep/xmllint: an
# XCCDF TestResult nests <rule-result> inside other elements that also
# happen to contain the word "result", so a line-oriented grep can silently
# miscount. A real parser can't make that mistake.

LINE=$(python3 - "$RESULTS_XML" "$PROFILE_ID" <<'PYEOF'
import sys
import xml.etree.ElementTree as ET
from datetime import datetime

results_path, profile_id = sys.argv[1], sys.argv[2]
ns = {'xccdf': 'http://checklists.nist.gov/xccdf/1.2'}
tree = ET.parse(results_path)
root = tree.getroot()

test_result = root if root.tag.endswith('TestResult') else root.find('.//xccdf:TestResult', ns)
if test_result is None:
    print(f"ERROR no TestResult element in {results_path}", file=sys.stderr)
    sys.exit(1)

score_el = test_result.find("xccdf:score[@system='urn:xccdf:scoring:default']", ns)
if score_el is None:
    score_el = test_result.find('xccdf:score', ns)
if score_el is None or score_el.text is None:
    print("ERROR no <score> element found", file=sys.stderr)
    sys.exit(1)
score = float(score_el.text)

counts = {}
for rr in test_result.findall('xccdf:rule-result', ns):
    res_el = rr.find('xccdf:result', ns)
    status = res_el.text.strip() if res_el is not None and res_el.text else 'unknown'
    counts[status] = counts.get(status, 0) + 1

passed = counts.get('pass', 0)
failed = counts.get('fail', 0)
other = sum(v for k, v in counts.items() if k not in ('pass', 'fail'))

ts = datetime.now().astimezone().strftime('%Y-%m-%dT%H:%M:%S%z')

print(f"SACCFG1|{profile_id}|{score:.2f}|{passed}|{failed}|{other}|{ts}")
PYEOF
)

if [ -z "$LINE" ]; then
    log "Failed to build the summary line — see parser output above"
    exit 1
fi
log "Summary line: $LINE"

# A scan where NOTHING was evaluated is not a score of zero, it is a failed
# scan. Writing it would hand SAC a 0.00 that its thresholds read as Critical,
# and a host would be reported as maximally non-compliant because its content
# did not match. Refusing to write leaves the previous file in place, so the
# host ages into SAC's "stale" handling instead — which says "this host has
# not reported", which is the truth.
# A genuinely clean host has fail=0 but pass>0, so it is unaffected.
PASS_N=$(printf '%s' "$LINE" | cut -d'|' -f4)
FAIL_N=$(printf '%s' "$LINE" | cut -d'|' -f5)
if [ "$((PASS_N + FAIL_N))" -eq 0 ]; then
    log "REFUSING to write: the scan evaluated nothing (pass=0, fail=0, other=$(printf '%s' "$LINE" | cut -d'|' -f6))."
    log "This is a broken scan, not a score of 0. Almost always the content does not match the OS:"
    log "  host content : $CONTENT_FILE"
    log "  host OS      : ${PRETTY_NAME:-unknown}"
    log "Install the matching scap-security-guide, or set SAC_CONTENT_FILE. $OUTPUT_FILE is left untouched."
    exit 1
fi

# ---- Parse the same results into per-rule SACRULE1 lines ------------------
# Deliberately a SECOND, separate step rather than folded into the summary
# parser above. If anything here fails - an SSG layout this does not expect,
# python running out of memory on a large datastream - the host still
# reports its score exactly as it did before 1.8.0. A host that goes quiet
# is worse than a host with no per-rule detail: SAC reads a missing summary
# as a broken pull and, correctly, refuses to act on it.

RULE_LINES=""
set +e
RULE_LINES=$(python3 - "$RESULTS_XML" "$CONTENT_FILE" <<'PYEOF'
# ---- Per-rule lines (SACRULE1) and content notes (SACNOTE1) ---------------
import sys
import xml.etree.ElementTree as ET

results_path, content_path = sys.argv[1], sys.argv[2]
XCCDF = 'http://checklists.nist.gov/xccdf/1.2'
ns = {'xccdf': XCCDF}

# Each <reference> is labelled from ITS OWN href, never from one per-host
# prefix: the fleet can mix SSG versions, and one datastream can carry
# several CIS reference sets. Only hrefs confirmed by recon on a real host
# belong here -- an unrecognised one is reported, never guessed at, because
# a wrong control reference is worse than a missing one.
CIS_HREFS = {
    # href substring            -> riskregister framework shortname
    'cisecurity.org/controls/': 'CISv7',
    #   ^ confirmed on vm-dev-damara, ssg-ol9-ds.xml (Sep 2023), 2026-10-22:
    #     bare integer codes, observed set 1-9,11-16,18-20. Codes reaching
    #     20 is what identifies v7; v8 has 18 controls.
    # Add a v8 entry ONLY after running the recon on a host that carries it.
}

unknown_cis = {}


def label_for(href, code):
    """Framework shortname for one reference, or None to skip it."""
    for frag, short in CIS_HREFS.items():
        if frag not in href:
            continue
        # v7 cites whole controls ("19"), v8 cites dotted safeguards ("4.1").
        # A shape that contradicts the label its href earned means the table
        # is out of date for this host: report rather than mislabel.
        if short == 'CISv7' and '.' in code:
            return None
        return short
    return None


# 1. Which rules failed. Only fail/error are sent: a pass is not a finding,
#    and shipping ~750 passes per host would dwarf the payload.
wanted = {}
order = []
troot = ET.parse(results_path).getroot()
tr = troot if troot.tag.endswith('TestResult') else troot.find('.//xccdf:TestResult', ns)
if tr is None:
    sys.exit(0)   # no TestResult: the summary block already reported that

for rr in tr.findall('xccdf:rule-result', ns):
    res_el = rr.find('xccdf:result', ns)
    status = res_el.text.strip() if res_el is not None and res_el.text else ''
    if status not in ('fail', 'error'):
        continue
    rid = rr.get('idref', '')
    if not rid or rid in wanted:
        continue
    wanted[rid] = {'result': status, 'severity': (rr.get('severity') or '').strip().lower()}
    order.append(rid)

if not wanted:
    sys.exit(0)

# 2. Titles and references, from the CONTENT datastream. Read with iterparse
#    and cleared as it goes: the OL9 datastream is 16 MB and a full ET.parse()
#    of it costs several hundred MB on a host that may be small, while only a
#    few dozen failing rules out of ~800 are ever needed.
titles, refs = {}, {}
context = ET.iterparse(content_path, events=('start', 'end'))
_, root = next(context)
in_rule = 0
for ev, el in context:
    is_rule = el.tag.endswith('}Rule')
    if ev == 'start':
        if is_rule:
            in_rule += 1
        continue
    if is_rule:
        in_rule -= 1
        rid = el.get('id', '')
        if rid in wanted:
            t = el.find('xccdf:title', ns)
            if t is not None and t.text:
                titles[rid] = ' '.join(t.text.split())
            if not wanted[rid]['severity']:
                wanted[rid]['severity'] = (el.get('severity') or '').strip().lower()
            labelled, seen = [], set()
            for r in el.findall('xccdf:reference', ns):
                href = r.get('href') or ''
                code = (r.text or '').strip()
                if not code:
                    continue
                # PCI DSS refs in this content carry no href at all, so they
                # fall out here without needing an exclusion of their own.
                short = label_for(href, code)
                if short is None:
                    if 'cisecurity.org' in href:
                        unknown_cis[href] = unknown_cis.get(href, 0) + 1
                    continue
                pair = short + ':' + code
                if pair in seen:
                    continue   # riskregister discards a repeated pair anyway
                seen.add(pair)
                labelled.append(pair)
            if labelled:
                refs[rid] = ','.join(labelled)
        el.clear()
        root.clear()
    elif in_rule == 0:
        # Anything that ends OUTSIDE a Rule is freed immediately. This is what
        # actually bounds memory: a real SSG datastream carries a large OVAL
        # section AFTER the Benchmark, and clearing only Rules leaves all of it
        # in memory. Measured on a 7.4 MB file shaped like the real one:
        # 22.0 MB peak before, 1.8 MB after.
        el.clear()
        root.clear()

# 3. Emit. A title may legitimately contain '|' (the PHP parser splits on a
#    field limit and keeps the remainder), but never a newline.
for rid in order:
    info = wanted[rid]
    title = titles.get(rid, rid).replace('\n', ' ').replace('\r', ' ')
    print("SACRULE1|{}|{}|{}|{}|{}".format(
        rid, info['result'], info['severity'] or 'unknown', refs.get(rid, ''), title))

for href, n in sorted(unknown_cis.items()):
    print("SACNOTE1|unknown_cis_href|{}|{}".format(href, n))
PYEOF
)
RULES_RC=$?
set -e

if [ "$RULES_RC" -ne 0 ]; then
    log "Per-rule parsing failed (rc=$RULES_RC) - writing the summary line plus a rule_parse_failed note, so SAC leaves the existing rules alone instead of resolving them"
    # Say so in the payload, not only in a log nobody reads. Without this
    # line SAC cannot tell 'parsing broke' from 'this host has nothing
    # failing', and would resolve every open rule it holds for the host.
    RULE_LINES="SACNOTE1|rule_parse_failed|$RULES_RC"
else
    log "Per-rule lines: $(printf '%s' "$RULE_LINES" | grep -c '^SACRULE1|' || true)"
    NOTES=$(printf '%s' "$RULE_LINES" | grep -c '^SACNOTE1|' || true)
    if [ "$NOTES" -gt 0 ]; then
        log "Content notes: $NOTES CIS reference href(s) SAC does not recognise - see the Reconcile tab"
    fi
fi

# ---- Write atomically -------------------------------------------------
# Write to a temp file in the SAME directory (so the final `mv` is an
# atomic rename, not a cross-filesystem copy) and only replace the real
# file once the write is complete — a reader (the GLPI SSH pull) must
# never observe a half-written line.

OUTPUT_DIR="$(dirname "$OUTPUT_FILE")"
mkdir -p "$OUTPUT_DIR"
TMP_OUTPUT="$(mktemp "$OUTPUT_DIR/.oscap_summary.XXXXXX")"
# Extend the EXIT trap to also remove this temp file: with `set -e`, a
# failed `chown` (e.g. the configured group doesn't exist on this host)
# exits immediately and, without this, leaves a stray .oscap_summary.*
# file behind forever — $OUTPUT_FILE itself is never touched in that case,
# so it silently goes stale with no obvious cause. `rm -f` is a no-op once
# the file has already been moved away by a successful run below.
trap 'rm -rf "$WORKDIR"; rm -f "$TMP_OUTPUT"' EXIT
# The summary line MUST be first: SummaryPayload::split() treats anything
# before it as banner noise, and a payload without it as an unusable pull.
{
    printf '%s\n' "$LINE"
    if [ -n "$RULE_LINES" ]; then
        printf '%s\n' "$RULE_LINES"
    fi
} > "$TMP_OUTPUT"
chown "$OUTPUT_OWNER" "$TMP_OUTPUT"
chmod "$OUTPUT_MODE" "$TMP_OUTPUT"
mv -f "$TMP_OUTPUT" "$OUTPUT_FILE"

log "Wrote $OUTPUT_FILE"
