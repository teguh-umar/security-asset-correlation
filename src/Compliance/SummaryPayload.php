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
     * @return array{summary:?string,rules:array<int,string>}
     */
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
