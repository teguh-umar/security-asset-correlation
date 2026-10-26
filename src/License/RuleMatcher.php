<?php

namespace GlpiPlugin\Sac\License;

/**
 * Chooses the one licence classification rule that decides a software item.
 *
 * PURE: no `$DB`, no GLPI runtime. Glob matching and scoring are delegated to
 * {@see LicenseRule}. Resolution order: the nearer entity wins first, then the
 * more specific pattern; ties fall to the lowest rule id.
 */
final class RuleMatcher
{
    /** @return array<string,callable():void> */
    public static function selfChecks(): array
    {
        return [
            'license/matcher-two-axes' => static function (): void {
                // Nearer entity wins first, then specificity (spec: orchestrator input, 2026-10-26).
                $parentExact = ['id' => 1, 'entities_id' => 0, 'pattern' => 'acme-db', 'match_on' => 'name', 'classification' => 'needs_license'];
                $childBroad  = ['id' => 2, 'entities_id' => 5, 'pattern' => 'acme-*',  'match_on' => 'name', 'classification' => 'free'];
                $win = RuleMatcher::decide('acme-db', ['1.0'], [$parentExact, $childBroad], [5, 0]);
                sac_assert_eq($win['id'], 2, 'the nearer entity wins even with a looser pattern: a local rule is a deliberate override');

                // Within one entity, specificity decides.
                $broad = ['id' => 3, 'entities_id' => 5, 'pattern' => 'acme-*', 'match_on' => 'name', 'classification' => 'free'];
                $exact = ['id' => 4, 'entities_id' => 5, 'pattern' => 'acme-db', 'match_on' => 'name', 'classification' => 'needs_license'];
                sac_assert_eq(RuleMatcher::decide('acme-db', ['1.0'], [$broad, $exact], [5, 0])['id'], 4, 'same entity: the more specific pattern wins');
            },
            'license/matcher-mixed-versions' => static function (): void {
                // Review Focus 3: a free version must not make the whole software free.
                $freeVer = ['id' => 5, 'entities_id' => 0, 'pattern' => '*el9*', 'match_on' => 'version', 'classification' => 'free'];
                sac_assert(RuleMatcher::decide('php', ['8.1.27-1.module+el9.3.0', '8.2.0-upstream'], [$freeVer], [0]) === null, 'one unmatched installed version leaves the software undecided, never free');
            },
        ];
    }

    /**
     * PURE. Returns the deciding rule row with a `matched_on` key (`'name'`, or
     * the first installed version when a `version` rule matched them all), or
     * null when no rule decides.
     *
     * Only rules whose entity appears in `$entityAncestry` are candidates.
     * `$entityAncestry` lists the software's entity first, then its ancestors,
     * nearest first. Among candidates: nearer entity, then higher specificity,
     * then lower id.
     *
     * @param string[]                       $versionNames   every installed version of the software
     * @param array<int,array<string,mixed>> $rules          licenserules rows
     * @param int[]                          $entityAncestry entity ids, nearest first
     * @return array<string,mixed>|null
     */
    public static function decide(string $softwareName, array $versionNames, array $rules, array $entityAncestry): ?array
    {
        $distance = [];
        foreach (array_values($entityAncestry) as $index => $entityId) {
            $distance[(int) $entityId] ??= $index;
        }

        $candidates = [];
        foreach ($rules as $rule) {
            $entityId = (int) $rule['entities_id'];
            if (!isset($distance[$entityId])) {
                continue; // a rule from an entity the software does not sit under
            }
            $matchedOn = self::matchedOn($rule, $softwareName, $versionNames);
            if ($matchedOn === null) {
                continue;
            }
            $candidates[] = [
                'rule'        => $rule,
                'matched_on'  => $matchedOn,
                'distance'    => $distance[$entityId],
                'specificity' => LicenseRule::specificity((string) $rule['pattern']),
                'id'          => (int) $rule['id'],
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int =>
            [$a['distance'], -$a['specificity'], $a['id']] <=> [$b['distance'], -$b['specificity'], $b['id']]);

        $best = $candidates[0];
        $best['rule']['matched_on'] = $best['matched_on'];

        return $best['rule'];
    }

    /**
     * PURE. What the rule matched on, or null when it does not decide. A `version`
     * rule decides only when it matches every installed version; one unmatched
     * version leaves the software undecided rather than free.
     *
     * @param array<string,mixed> $rule
     * @param string[]            $versionNames
     */
    private static function matchedOn(array $rule, string $softwareName, array $versionNames): ?string
    {
        $pattern = (string) $rule['pattern'];

        if ($rule['match_on'] === 'name') {
            return LicenseRule::matchesPattern($pattern, $softwareName) ? 'name' : null;
        }

        if ($rule['match_on'] === 'version') {
            if ($versionNames === []) {
                return null;
            }
            foreach ($versionNames as $version) {
                if (!LicenseRule::matchesPattern($pattern, $version)) {
                    return null;
                }
            }

            return (string) $versionNames[array_key_first($versionNames)];
        }

        return null;
    }
}
