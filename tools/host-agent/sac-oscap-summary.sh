#!/usr/bin/env bash
#
# SAC Module 3 Phase 1 (Config Compliance, Tier 1) — host-side collector.
#
# Runs on the MONITORED host (not the GLPI server), as root via cron.
# Evaluates a CIS profile with `oscap`, then writes ONE line to
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

# ---- Write atomically -------------------------------------------------
# Write to a temp file in the SAME directory (so the final `mv` is an
# atomic rename, not a cross-filesystem copy) and only replace the real
# file once the write is complete — a reader (the GLPI SSH pull) must
# never observe a half-written line.

OUTPUT_DIR="$(dirname "$OUTPUT_FILE")"
mkdir -p "$OUTPUT_DIR"
TMP_OUTPUT="$(mktemp "$OUTPUT_DIR/.oscap_summary.XXXXXX")"
printf '%s\n' "$LINE" > "$TMP_OUTPUT"
chown "$OUTPUT_OWNER" "$TMP_OUTPUT"
chmod "$OUTPUT_MODE" "$TMP_OUTPUT"
mv -f "$TMP_OUTPUT" "$OUTPUT_FILE"

log "Wrote $OUTPUT_FILE"
