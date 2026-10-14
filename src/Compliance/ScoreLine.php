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

    private const TAG = 'SACCFG1';

    /**
     * PURE. Parses the Tier 1 oscap summary line:
     * `SACCFG1|<benchmark_id>|<score 0-100>|<pass>|<fail>|<other>|<ISO-8601 timestamp>`.
     * Returns null on ANY malformation — callers route a null into
     * `_reconcile`, never a partial/best-effort result.
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

        // Preserve the wall-clock digits the host reported, exactly as every
        // other date_* column in this plugin does (RunLog::now(), etc. never
        // apply timezone math) — never run this through strtotime()/date(),
        // which convert via UTC and would silently shift "last scan" by the
        // server's UTC offset. Any trailing timezone designator (offset or
        // `Z`) is accepted but discarded, not converted.
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $tsRaw, $m)) {
            return null;
        }
        $dateScan = $m[1] . ' ' . $m[2];
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $dateScan);
        if ($dt === false || $dt->format('Y-m-d H:i:s') !== $dateScan) {
            return null; // rejects calendar-invalid values, e.g. 2026-02-30
        }

        return [
            'benchmark_id' => $benchmarkId,
            'score'        => $score,
            'pass'         => (int) $passRaw,
            'fail'         => (int) $failRaw,
            'other'        => (int) $otherRaw,
            'date_scan'    => $dateScan,
        ];
    }
}
