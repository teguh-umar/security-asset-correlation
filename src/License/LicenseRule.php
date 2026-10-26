<?php

namespace GlpiPlugin\Sac\License;

/**
 * One per-entity licence classification rule (`glpi_plugin_sac_licenserules`).
 *
 * PURE: no `$DB`, no GLPI runtime. {@see validate()} is the only gate between a
 * malformed rule and the table — the database does not enforce a non-empty
 * `reason` or the `free`/`needs_license` value domain.
 */
final class LicenseRule
{
    /** @return array<string,callable():void> */
    public static function selfChecks(): array
    {
        return [
            'license/rule-validation' => static function (): void {
                sac_assert_eq(LicenseRule::validate(['pattern' => 'mysql-community-*', 'match_on' => 'name', 'classification' => 'free', 'reason' => 'GPL']), null, 'a complete rule validates');
                sac_assert(LicenseRule::validate(['pattern' => 'x', 'match_on' => 'name', 'classification' => 'free', 'reason' => '  ']) !== null, 'a blank reason is rejected: the table is maintained by another team');
                sac_assert(LicenseRule::validate(['pattern' => '*', 'match_on' => 'name', 'classification' => 'free', 'reason' => 'r']) !== null, 'a bare * would classify the whole estate from one row');
                sac_assert(LicenseRule::validate(['pattern' => '', 'match_on' => 'name', 'classification' => 'free', 'reason' => 'r']) !== null, 'an empty pattern is rejected');
            },
            'license/rule-specificity' => static function (): void {
                sac_assert(LicenseRule::specificity('mysql-community-server') > LicenseRule::specificity('mysql-*'), 'a literal beats a prefix');
                sac_assert(LicenseRule::specificity('mysql-community-*') > LicenseRule::specificity('mysql-*'), 'a longer prefix is more specific');
            },
            'license/rule-matching' => static function (): void {
                sac_assert(LicenseRule::matchesPattern('*.el9', 'openssl-3.0.7-28.el9'), 'suffix glob');
                sac_assert(LicenseRule::matchesPattern('*el9*', '8.1.27-1.module+el9.3.0+90130+7e4e1ce0'), 'the modular form is reachable by a pattern that does not assume a leading dot');
                sac_assert(!LicenseRule::matchesPattern('*.el9', '8.1.27-1.module+el9.3.0+90130+7e4e1ce0'), 'and the naive dotted pattern does NOT reach it — this is the case that made the design');
            },
        ];
    }

    /** Classes a rule may assign. There is no third class. */
    private const CLASSIFICATIONS = ['free', 'needs_license'];

    /** What a pattern is matched against. */
    private const MATCH_ON = ['name', 'version'];

    /** `glpi_plugin_sac_licenserules.pattern` is varchar(255). */
    private const PATTERN_MAX = 255;

    /**
     * PURE. Returns an error message, or null when the rule may be written.
     *
     * @param array<string,mixed> $input keys: pattern, match_on, classification, reason
     */
    public static function validate(array $input): ?string
    {
        $pattern        = (string) ($input['pattern'] ?? '');
        $matchOn        = (string) ($input['match_on'] ?? '');
        $classification = (string) ($input['classification'] ?? '');
        $reason         = (string) ($input['reason'] ?? '');

        if (!in_array($classification, self::CLASSIFICATIONS, true)) {
            return 'classification must be one of: ' . implode(', ', self::CLASSIFICATIONS);
        }
        if (!in_array($matchOn, self::MATCH_ON, true)) {
            return 'match_on must be one of: ' . implode(', ', self::MATCH_ON);
        }
        if (trim($pattern) === '') {
            return 'pattern must not be empty';
        }
        if (strlen($pattern) > self::PATTERN_MAX) {
            return 'pattern must be at most ' . self::PATTERN_MAX . ' characters';
        }
        // A pattern of only wildcards would classify the whole estate from one row.
        if (trim(str_replace('*', '', $pattern)) === '') {
            return 'pattern must contain at least one literal character besides *';
        }
        if (trim($reason) === '') {
            return 'reason is required: every classification must say why';
        }

        return null;
    }

    /**
     * PURE. Higher is more specific. A literal character outweighs a wildcard,
     * so `mysql-community-server` > `mysql-community-*` > `mysql-*`, and each
     * `*` costs one point so `foo*` ranks above `*foo*` of equal length.
     */
    public static function specificity(string $pattern): int
    {
        $stars    = substr_count($pattern, '*');
        $literals = strlen(str_replace('*', '', $pattern));

        return 2 * $literals - $stars;
    }

    /**
     * PURE. Case-insensitive glob; `*` is the only wildcard.
     */
    public static function matchesPattern(string $pattern, string $subject): bool
    {
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/iD';

        return preg_match($regex, $subject) === 1;
    }
}
