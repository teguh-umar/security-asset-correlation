<?php

namespace GlpiPlugin\Sac\License;

use GlpiPlugin\Sac\Config;

/**
 * Blast-radius preview for a proposed licence rule: exactly which installed
 * software the pattern would cover, and how many matching rows sit outside the
 * approver's entity scope.
 *
 * The spec requires the generalisation step to show "exactly which other
 * packages the proposed pattern would cover, before it is approved". A preview
 * built from the review queue would under-report: a new rule also decides
 * software that never queued (already licensed, or below the publisher gate).
 * So the input is every installed candidate, not the queue.
 *
 * {@see cover()} is PURE and is what the offline checks exercise.
 * {@see forPattern()} is the database read the page calls; it reuses
 * {@see LicenseScan::candidateSql()} so "installed" means exactly what the scan
 * means (non-deleted Computer, referenced version, not an ignored category).
 */
final class RulePreview
{
    /** @return array<string,callable():void> */
    public static function selfChecks(): array
    {
        return [
            'license/preview-covers-more-than-the-queue' => static function (): void {
                // The preview has no queue input on purpose: a pattern can cover
                // software that never queued, and the approver must see it.
                $installed = [
                    ['name' => 'postgresql-client', 'entities_id' => 0, 'versions' => ['15.4-1.el9']],
                    ['name' => 'postgresql-server', 'entities_id' => 0, 'versions' => ['15.4-1.el9']],
                    ['name' => 'nginx', 'entities_id' => 0, 'versions' => ['1.20.1']],
                ];
                $out = RulePreview::cover($installed, 'postgresql-*', 'name', null);
                sac_assert_eq($out['visible'], ['postgresql-client', 'postgresql-server'], 'installed software the pattern covers is named, queued or not');
                sac_assert_eq($out['hidden_count'], 0, 'nothing is hidden when the approver sees every entity');
            },

            // Review Focus 4: an entity-restricted approver must not be shown
            // another entity's software, but must be told that it is there.
            'license/preview-hides-rows-outside-scope-but-counts-them' => static function (): void {
                $installed = [
                    ['name' => 'acme-db', 'entities_id' => 5, 'versions' => ['1.0']],
                    ['name' => 'acme-db', 'entities_id' => 7, 'versions' => ['1.0']],
                    ['name' => 'acme-web', 'entities_id' => 7, 'versions' => ['2.0']],
                    ['name' => 'zabbix-agent', 'entities_id' => 5, 'versions' => ['6.0']],
                ];
                $out = RulePreview::cover($installed, 'acme-*', 'name', [5]);
                sac_assert_eq($out['visible'], ['acme-db'], 'only the row inside the approver\'s entities is named');
                sac_assert(!in_array('acme-web', $out['visible'], true), 'an out-of-scope name is never disclosed');
                sac_assert_eq($out['hidden_count'], 2, 'both out-of-scope rows are counted: acme-db and acme-web in entity 7');
            },

            'license/preview-version-rule-requires-every-installed-version' => static function (): void {
                // Same rule as RuleMatcher: a partially matching version rule
                // leaves the software undecided, so it must not be previewed as
                // covered.
                $installed = [
                    ['name' => 'openssl', 'entities_id' => 0, 'versions' => ['3.0.7-28.el9']],
                    ['name' => 'php', 'entities_id' => 0, 'versions' => ['8.1.27-1.module+el9.3.0', '8.2.0-upstream']],
                    ['name' => 'no-version', 'entities_id' => 0, 'versions' => []],
                ];
                $out = RulePreview::cover($installed, '*el9*', 'version', null);
                sac_assert_eq($out['visible'], ['openssl'], 'a version rule covers a software only when every installed version matches');
                sac_assert_eq($out['hidden_count'], 0, 'a partial or missing version match is not counted as covered');
            },

            'license/preview-suggests-a-generalisation' => static function (): void {
                sac_assert_eq(RulePreview::suggestPattern('acme-db'), 'acme-*', 'the last segment is widened');
                sac_assert_eq(RulePreview::suggestPattern('mysql-community-server'), 'mysql-community-*', 'only the last segment is widened');
                sac_assert_eq(RulePreview::suggestPattern('htop'), 'htop', 'a single token has no safe generalisation: keep it exact');
            },
        ];
    }

    /**
     * PURE. The pattern offered on the generalisation panel: the software name
     * with its last dash-segment replaced by `*` (`acme-db` -> `acme-*`).
     *
     * Deliberately conservative: a single-token name has no safe broadening, so
     * it is offered unchanged and the operator must type a pattern. The preview
     * is what makes a broader choice safe, not this suggestion.
     */
    public static function suggestPattern(string $name): string
    {
        $pos = strrpos($name, '-');
        if ($pos === false || $pos === 0) {
            return $name;
        }

        return substr($name, 0, $pos + 1) . '*';
    }

    /**
     * PURE. Which installed software a proposed pattern would cover.
     *
     * @param array<int,array{name:string,entities_id:int,versions?:array<int,string>}> $installed one row per (software, entity)
     * @param array<int,int>|null $visibleEntities null = the approver sees every entity
     * @return array{visible:array<int,string>,hidden_count:int} sorted unique names in scope,
     *         and the number of matching rows outside it (names deliberately withheld)
     */
    public static function cover(array $installed, string $pattern, string $matchOn, ?array $visibleEntities): array
    {
        $inScope = null;
        if ($visibleEntities !== null) {
            $inScope = [];
            foreach ($visibleEntities as $entityId) {
                $inScope[(int) $entityId] = true;
            }
        }

        $visible = [];
        $hidden  = 0;

        foreach ($installed as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || !self::rowMatches($row, $pattern, $matchOn)) {
                continue;
            }

            $entityId = (int) ($row['entities_id'] ?? 0);
            if ($inScope === null || isset($inScope[$entityId])) {
                $visible[$name] = $name;
            } else {
                // Counted, not named: Review Focus 4. The approver learns that
                // their change reaches further without learning what lives
                // there.
                $hidden++;
            }
        }

        $names = array_values($visible);
        usort($names, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return ['visible' => $names, 'hidden_count' => $hidden];
    }

    /**
     * The installed software a pattern would cover, read from the database.
     *
     * @param array<int,int>|null $visibleEntities null = no entity restriction
     * @return array{visible:array<int,string>,hidden_count:int}
     */
    public static function forPattern(string $pattern, string $matchOn, ?array $visibleEntities): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $quoted = [];
        foreach (Config::ignoreCategories() as $category) {
            $category = (string) $category;
            if ($category !== '') {
                $quoted[] = $DB->quote($category);
            }
        }

        $res = $DB->doQuery(LicenseScan::candidateSql($quoted));
        if (!$res) {
            return ['visible' => [], 'hidden_count' => 0];
        }

        // One row per (software, entity), versions collected the same way
        // LicenseScan does: blank version names are dropped because they would
        // undecide a software whose every real version matched.
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $key = (int) $row['id'] . ':' . (int) $row['entities_id'];
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'name'        => (string) ($row['name'] ?? ''),
                    'entities_id' => (int) $row['entities_id'],
                    'versions'    => [],
                ];
            }
            $version = (string) ($row['version_name'] ?? '');
            if ($version !== '') {
                $rows[$key]['versions'][$version] = $version;
            }
        }

        $installed = [];
        foreach ($rows as $r) {
            $r['versions'] = array_values($r['versions']);
            $installed[]   = $r;
        }

        return self::cover($installed, $pattern, $matchOn, $visibleEntities);
    }

    /**
     * PURE. Mirrors {@see RuleMatcher}: a version rule covers a software only
     * when it has at least one installed version and every version matches.
     *
     * @param array{name?:string,versions?:array<int,string>} $row
     */
    private static function rowMatches(array $row, string $pattern, string $matchOn): bool
    {
        if ($matchOn !== 'version') {
            return LicenseRule::matchesPattern($pattern, (string) ($row['name'] ?? ''));
        }

        $versions = $row['versions'] ?? [];
        if ($versions === []) {
            return false;
        }
        foreach ($versions as $version) {
            if (!LicenseRule::matchesPattern($pattern, (string) $version)) {
                return false;
            }
        }

        return true;
    }
}
