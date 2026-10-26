<?php

namespace GlpiPlugin\Sac\License;

/**
 * One per-entity licence classification rule (`glpi_plugin_sac_licenserules`).
 *
 * The matching half ({@see validate()}, {@see specificity()},
 * {@see matchesPattern()}) is PURE: no `$DB`, no GLPI runtime, and
 * {@see validate()} is the only gate between a malformed rule and the table.
 * {@see seedFromCsv()} is the one method that writes, and it runs at install
 * time only.
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
                sac_assert(LicenseRule::validate(['pattern' => 'x', 'match_on' => 'name', 'classification' => 'free', 'reason' => ['x']]) !== null, 'an array reason is rejected, not cast to the string "Array"');
                sac_assert(LicenseRule::validate(['pattern' => 'x', 'match_on' => 'name', 'classification' => 'free']) !== null, 'a missing reason key is rejected');
                sac_assert(LicenseRule::validate(['pattern' => ' mysql-* ', 'match_on' => 'name', 'classification' => 'free', 'reason' => 'r']) !== null, 'an untrimmed pattern is rejected: it would be stored and never match');
            },
            'license/rule-specificity' => static function (): void {
                sac_assert(LicenseRule::specificity('mysql-community-server') > LicenseRule::specificity('mysql-*'), 'a literal beats a prefix');
                sac_assert(LicenseRule::specificity('mysql-community-*') > LicenseRule::specificity('mysql-*'), 'a longer prefix is more specific');
            },
            'license/rule-matching' => static function (): void {
                sac_assert(LicenseRule::matchesPattern('*.el9', 'openssl-3.0.7-28.el9'), 'suffix glob');
                sac_assert(LicenseRule::matchesPattern('*el9*', '8.1.27-1.module+el9.3.0+90130+7e4e1ce0'), 'the modular form is reachable by a pattern that does not assume a leading dot');
                sac_assert(!LicenseRule::matchesPattern('*.el9', '8.1.27-1.module+el9.3.0+90130+7e4e1ce0'), 'and the naive dotted pattern does NOT reach it — this is the case that made the design');
                sac_assert(LicenseRule::matchesPattern('*el9*', "name\nel9"), 'a newline in the subject does not stop a wildcard');
                sac_assert(LicenseRule::matchesPattern('*a*a*a*a*', str_repeat('a', 5000) . 'b'), 'a run of stars collapses; a long subject still matches');
                sac_assert(!LicenseRule::matchesPattern('*a*a*a*a*b', str_repeat('a', 5000)), 'a long non-matching subject returns false promptly');
            },

            'license/seed-covers-both-distro-forms' => static function (): void {
                $rules = self::seedRulesForCheck();

                sac_assert(in_array('*.el9', array_column($rules, 'pattern'), true), 'the dotted form is seeded explicitly');
                sac_assert(in_array('*el9*', array_column($rules, 'pattern'), true), 'the modular form is seeded explicitly');

                $dotted = RuleMatcher::decide('openssl', ['3.0.7-28.el9'], $rules, [0]);
                sac_assert_eq($dotted['classification'] ?? null, 'free', 'a dotted el9 version is classified free');

                $modular = RuleMatcher::decide('php', ['8.1.27-1.module+el9.3.0+90130+7e4e1ce0'], $rules, [0]);
                sac_assert_eq($modular['classification'] ?? null, 'free', 'the modular +el9 form is classified free: the case a naive pattern misses');
            },

            'license/seed-leaves-uncertain-software-undecided' => static function (): void {
                $rules = self::seedRulesForCheck();

                $verdict = static function (string $name) use ($rules): ?string {
                    $row = RuleMatcher::decide($name, ['1.0'], $rules, [0]);
                    return $row === null ? null : (string) $row['classification'];
                };

                sac_assert_eq($verdict('mysql-community-server'), 'free', 'MySQL Community is free (same publisher, opposite answer)');
                sac_assert_eq($verdict('mysql-commercial-server'), 'needs_license', 'MySQL Commercial needs a licence');
                sac_assert_eq($verdict('java-17-openjdk'), 'free', 'OpenJDK builds are free');
                sac_assert_eq($verdict('Oracle Database 19c Enterprise Edition'), 'needs_license', 'Oracle Database Enterprise Edition needs a licence');
                sac_assert_eq($verdict('Oracle Database 21c Standard Edition'), 'needs_license', 'Oracle Database Standard Edition needs a licence');

                // Deliberately not seeded: both depend on facts a glob cannot know.
                sac_assert_eq($verdict('jdk-17.0.9'), null, 'Oracle JDK is left to the admin: its licence depends on the version');
                sac_assert_eq($verdict('Oracle Database 21c Express Edition'), null, 'Oracle Database XE is not seeded: a broad Oracle Database* rule would wrongly charge for it');
            },
        ];
    }

    /**
     * PURE. The shipped seed rows, turned into rule rows exactly as
     * {@see seedFromCsv()} writes them (entity 0, recursive). Used by the
     * offline checks so the seed DATA is testable without a database.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function seedRulesForCheck(): array
    {
        $rows  = self::seedRows();
        sac_assert($rows !== [], 'the shipped seed file has at least one row');

        $rules = [];
        $id    = 0;
        foreach ($rows as $row) {
            $error = self::validate($row);
            sac_assert_eq($error, null, 'every seed row is a valid rule (' . (string) ($row['pattern'] ?? '?') . ')');
            $rules[] = $row + ['id' => ++$id, 'entities_id' => 0, 'is_recursive' => 1];
        }

        return $rules;
    }

    /** Classes a rule may assign. There is no third class. */
    private const CLASSIFICATIONS = ['free', 'needs_license'];

    /** What a pattern is matched against. */
    private const MATCH_ON = ['name', 'version'];

    /** `glpi_plugin_sac_licenserules.pattern` is varchar(255). */
    private const PATTERN_MAX = 255;

    /** The table {@see seedFromCsv()} writes to. */
    private const TABLE = 'glpi_plugin_sac_licenserules';

    /** Columns of `install/data/license_rules.csv`, in order. */
    private const SEED_COLUMNS = ['match_on', 'pattern', 'classification', 'reason'];

    /** @var array<int,array{match_on:string,pattern:string,classification:string,reason:string}>|null */
    private static ?array $seedRows = null;

    /**
     * The shipped seed rows, in file order. Never written; re-parsed once per
     * request. A malformed row is skipped here, and the offline checks catch a
     * missing named pattern.
     *
     * @return array<int,array{match_on:string,pattern:string,classification:string,reason:string}>
     */
    public static function seedRows(): array
    {
        if (self::$seedRows !== null) {
            return self::$seedRows;
        }
        self::$seedRows = [];

        $path = dirname(__DIR__, 2) . '/install/data/license_rules.csv';
        if (!is_file($path)) {
            return self::$seedRows;
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return self::$seedRows;
        }

        $header = fgetcsv($fh, 0, ',', '"', '\\');
        $header = $header === false
            ? []
            : array_map(static fn ($h): string => strtolower(trim((string) $h)), $header);
        if ($header !== self::SEED_COLUMNS) {
            fclose($fh);
            return self::$seedRows;
        }

        while (($line = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($line === [null] || count($line) !== 4) {
                continue; // blank or malformed line
            }
            self::$seedRows[] = [
                'match_on'       => trim((string) $line[0]),
                'pattern'        => trim((string) $line[1]),
                'classification' => trim((string) $line[2]),
                'reason'         => trim((string) $line[3]),
            ];
        }
        fclose($fh);

        return self::$seedRows;
    }

    /**
     * Top up the baseline rules from the shipped CSV. Called on every install /
     * upgrade, so it must be idempotent:
     *  - only entity 0 is seeded, and `is_recursive = 1` so the baseline reaches
     *    every sub-entity (a non-recursive root rule would decide only software
     *    that sits in the root entity itself);
     *  - an existing `(entities_id, match_on, pattern)` row is left untouched,
     *    so an admin's edit is never overwritten;
     *  - a row that fails {@see validate()} is logged and skipped, not written.
     */
    public static function seedFromCsv(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        foreach (self::seedRows() as $row) {
            $error = self::validate($row);
            if ($error !== null) {
                \Toolbox::logInFile('sac', 'license seed skipped (' . $row['pattern'] . '): ' . $error . "\n");
                continue;
            }

            $exists = (int) ($DB->request([
                'COUNT' => 'cpt',
                'FROM'  => self::TABLE,
                'WHERE' => [
                    'entities_id' => 0,
                    'match_on'    => $row['match_on'],
                    'pattern'     => $row['pattern'],
                ],
            ])->current()['cpt'] ?? 0);
            if ($exists > 0) {
                continue;
            }

            $DB->insert(self::TABLE, [
                'entities_id'    => 0,
                'is_recursive'   => 1,
                'pattern'        => $row['pattern'],
                'match_on'       => $row['match_on'],
                'classification' => $row['classification'],
                'reason'         => $row['reason'],
                'date_creation'  => $now,
                'date_mod'       => $now,
            ]);
        }
    }

    /**
     * PURE. Returns an error message, or null when the rule may be written.
     *
     * @param array<string,mixed> $input keys: pattern, match_on, classification, reason
     */
    public static function validate(array $input): ?string
    {
        foreach (['pattern', 'match_on', 'classification', 'reason'] as $key) {
            if (!array_key_exists($key, $input)) {
                return $key . ' is required';
            }
            // A non-string must not reach a (string) cast: an array would become "Array".
            if (!is_string($input[$key])) {
                return $key . ' must be a string';
            }
        }

        $pattern        = $input['pattern'];
        $matchOn        = $input['match_on'];
        $classification = $input['classification'];
        $reason         = $input['reason'];

        if (!in_array($classification, self::CLASSIFICATIONS, true)) {
            return 'classification must be one of: ' . implode(', ', self::CLASSIFICATIONS);
        }
        if (!in_array($matchOn, self::MATCH_ON, true)) {
            return 'match_on must be one of: ' . implode(', ', self::MATCH_ON);
        }
        if (trim($pattern) === '') {
            return 'pattern must not be empty';
        }
        // The stored value is used verbatim by matchesPattern(); surrounding
        // whitespace would make the rule silently match nothing.
        if ($pattern !== trim($pattern)) {
            return 'pattern must not have leading or trailing whitespace';
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
     * PURE. Case-insensitive glob; `*` is the only wildcard. Runs of `*` are
     * collapsed first so `*a*a*a*` cannot backtrack polynomially; the `s` flag
     * lets a wildcard span a newline in the subject.
     */
    public static function matchesPattern(string $pattern, string $subject): bool
    {
        $collapsed = preg_replace('/\*+/', '*', $pattern) ?? $pattern;
        $regex     = '/^' . str_replace('\*', '.*', preg_quote($collapsed, '/')) . '$/isD';

        return preg_match($regex, $subject) === 1;
    }
}
