<?php

namespace GlpiPlugin\Sac;

use GlpiPlugin\Sac\Eol\EolProducts;

/**
 * Matches a GLPI OS name + version string against the shipped seed
 * (`install/data/eol_products.csv`, {@see EolProducts}) to a candidate
 * `endoflife.date` product + cycle. Pure — no `$DB`, safe offline.
 */
final class EolMatch
{
    /**
     * @return array{skip:true}|array{product:string,cycle:string}|null
     *         `['skip' => true]` for a non-OS `name_pattern` hit (e.g.
     *         "Kubernetes"); `['product' => ..., 'cycle' => ...]` on a
     *         resolved match; `null` when no pattern matches, or every
     *         matching pattern's cycle rule fails to extract a cycle from its
     *         source string.
     */
    public static function resolve(string $osName, string $verName): ?array
    {
        $name = strtolower($osName);

        foreach (EolProducts::rules() as $rule) {
            if (!str_contains($name, $rule['pattern'])) {
                continue;
            }
            if ($rule['product'] === '_skip') {
                return ['skip' => true];
            }
            $source = $rule['cycle_source'] === 'name' ? $osName : $verName;
            $cycle  = self::cycleFrom($rule['cycle_rule'], $source);
            if ($cycle !== null) {
                return ['product' => $rule['product'], 'cycle' => $cycle];
            }
            // This pattern matched but its source string yielded no cycle
            // (e.g. a `cycle_source=version` rule with no version dropdown
            // set) — keep scanning in case a later, less-specific pattern
            // also matches and does resolve.
        }

        return null;
    }

    public static function cycleFrom(string $rule, string $source): ?string
    {
        $s = strtolower(trim($source));
        if ($s === '') {
            return null;
        }

        return match ($rule) {
            'major'     => self::cycleMajor($s),
            'yy.mm'     => self::cycleYyMm($s),
            'xy'        => self::cycleXy($s),
            'winsrv'    => self::cycleWindowsServer($s),
            'winclient' => self::cycleWindowsClient($s),
            'exact'     => trim($source),
            default     => null,
        };
    }

    /** First integer run = major version: "9.7" -> "9", "release 7.9" -> "7". */
    private static function cycleMajor(string $s): ?string
    {
        return preg_match('/(?:release\s+)?(\d+)(?:\.\d+)*/', $s, $m) ? $m[1] : null;
    }

    /** Two dotted groups, YY.MM width: "22.04.3 lts" -> "22.04". */
    private static function cycleYyMm(string $s): ?string
    {
        return preg_match('/(\d{2}\.\d{2})/', $s, $m) ? $m[1] : null;
    }

    /** Two dotted groups, any width: "7.0.3" -> "7.0", "8.0 update 1" -> "8.0". */
    private static function cycleXy(string $s): ?string
    {
        return preg_match('/(\d+\.\d+)/', $s, $m) ? $m[1] : null;
    }

    private static function cycleWindowsServer(string $s): ?string
    {
        if (!preg_match('/server\s+(\d{4})(\s*r2)?/', $s, $m)) {
            return null;
        }
        return $m[1] . (isset($m[2]) && trim($m[2]) !== '' ? '-r2' : '');
    }

    private static function cycleWindowsClient(string $s): ?string
    {
        return preg_match('/windows\s+(\d{1,2})/', $s, $m) ? $m[1] : null;
    }

    public static function selfChecks(): array
    {
        return [
            'eolmatch/cycle-rules' => static function (): void {
                sac_assert_eq(self::cycleFrom('major', 'Red Hat Enterprise Linux 8.6'), '8', 'major: rhel 8.6 -> 8');
                sac_assert_eq(self::cycleFrom('major', 'Red Hat Enterprise Linux release 7.9'), '7', 'major: release 7.9 -> 7');
                sac_assert_eq(self::cycleFrom('yy.mm', 'Ubuntu 20.04.6 LTS'), '20.04', 'yy.mm: 20.04.x -> 20.04');
                sac_assert_eq(self::cycleFrom('yy.mm', 'Ubuntu 22.04 LTS'), '22.04', 'yy.mm: 22.04');
                sac_assert_eq(self::cycleFrom('xy', '7.0.3'), '7.0', 'xy: 7.0.3 -> 7.0');
                sac_assert_eq(self::cycleFrom('xy', '8.0 Update 1'), '8.0', 'xy: 8.0 update 1 -> 8.0');
                sac_assert_eq(self::cycleFrom('winsrv', 'Microsoft Windows Server 2019 Standard'), '2019', 'winsrv: 2019');
                sac_assert_eq(self::cycleFrom('winsrv', 'Windows Server 2012 R2'), '2012-r2', 'winsrv: 2012 R2 (lowercase r2 matches the live endoflife.date cycle string)');
                sac_assert_eq(self::cycleFrom('winclient', 'Windows 10 Enterprise'), '10', 'winclient: 10');
                sac_assert_eq(self::cycleFrom('exact', ' 2024.1 '), '2024.1', 'exact: verbatim, trimmed');
                sac_assert_eq(self::cycleFrom('major', 'Some Linux'), null, 'major: no digits -> null');
                sac_assert_eq(self::cycleFrom('major', ''), null, 'blank source -> null');
            },
            'eolmatch/resolve-precedence' => static function (): void {
                sac_assert_eq(self::resolve('Red Hat Enterprise Linux', '8.6')['product'], 'rhel', 'RHEL resolves via version source');
                sac_assert_eq(self::resolve('Oracle', '9.7')['product'], 'oracle-linux', '"Oracle" alone matches the Oracle Linux fallback pattern');
                sac_assert_eq(self::resolve('Ubuntu', '22.04.3 LTS')['cycle'], '22.04', 'Ubuntu cycle from version source');
                sac_assert_eq(
                    self::resolve('Microsoft Windows Server 2019 Datacenter', ''),
                    ['product' => 'windows-server', 'cycle' => '2019'],
                    'Windows Server resolves from the OS name even with no version dimension'
                );
            },
            'eolmatch/resolve-skip-and-miss' => static function (): void {
                sac_assert_eq(self::resolve('Kubernetes', '1.29'), ['skip' => true], 'Kubernetes is a _skip pattern');
                sac_assert_eq(self::resolve('TotallyUnknown OS', '1.0'), null, 'no pattern matches -> null');
            },
        ];
    }
}
