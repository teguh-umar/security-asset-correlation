<?php

namespace GlpiPlugin\Sac\Compliance;

/**
 * Splits one SSH pull into its summary line and its rule lines.
 *
 * A payload whose first usable line is NOT a summary line yields
 * `summary => null` AND an empty rule list, deliberately: a pull truncated
 * mid-transfer would otherwise present as "the host has zero failing
 * rules", which a caller would act on by resolving every open rule it has.
 */
final class SummaryPayload
{
    public static function selfChecks(): array
    {
        return [
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
                // Review Focus #1: a truncated pull that lost its first line
                // must NOT read as "zero rules failing".
                $out = self::split("SACRULE1|rule_a|fail|high||Title A\n");
                sac_assert($out['summary'] === null, 'no summary line = unusable payload');
                sac_assert_eq($out['rules'], [], 'rules are discarded with it, never applied on their own');
            },
            'payload/keeps-content-notes' => static function (): void {
                $raw = "SACCFG1|bm|49.17|22|55|753|2026-10-14T11:55:14+0700
"
                     . "SACRULE1|rule_a|fail|high||A
"
                     . "SACNOTE1|unknown_cis_href|https://www.cisecurity.org/controls/v8/|7
";
                $out = self::split($raw);
                sac_assert_eq(count($out['rules']), 1, 'the note is not mistaken for a rule');
                sac_assert_eq(count($out['notes']), 1, 'the note survives: a host on newer CIS content must be able to say so');
            },
            'payload/notes-die-with-an-unusable-payload' => static function (): void {
                $out = self::split("SACNOTE1|unknown_cis_href|x|1
");
                sac_assert_eq($out['notes'], [], 'no summary = nothing from that pull is trusted, notes included');
            },
            'payload/ignores-blank-and-foreign-lines' => static function (): void {
                $raw = "\nSACCFG1|bm|49.17|22|55|753|2026-10-14T11:55:14+0700\n\nsome banner text\nSACRULE1|rule_a|fail|high||A\n";
                $out = self::split($raw);
                sac_assert($out['summary'] !== null, 'leading blank line tolerated');
                sac_assert_eq(count($out['rules']), 1, 'foreign lines ignored, rule lines kept');
            },
        ];
    }

    /**
     * PURE.
     *
     * @return array{summary:?string,rules:array<int,string>,notes:array<int,string>}
     */
    public static function split(string $raw): array
    {
        $summary = null;
        $rules   = [];
        $notes   = [];

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
                continue;
            }
            // SACNOTE1 is the wrapper telling us something about the CONTENT
            // rather than about a rule — today, a CIS reference href SAC does
            // not recognise. Kept rather than treated as noise: it is the
            // only way a host whose SSG moved to a newer CIS edition can
            // announce itself, instead of its refs quietly going missing.
            if (str_starts_with($line, 'SACNOTE1|')) {
                $notes[] = $line;
            }
        }

        return $summary === null
            ? ['summary' => null, 'rules' => [], 'notes' => []]
            : ['summary' => $summary, 'rules' => $rules, 'notes' => $notes];
    }
}
