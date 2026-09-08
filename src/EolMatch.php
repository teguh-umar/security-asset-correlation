<?php

namespace GlpiPlugin\Sac;

final class EolMatch
{
    /** Map: endoflife product slug => callable(string $osName): ?string */
    private const CYCLE_RULES = [
        'windows-server' => [self::class, 'cycleWindowsServer'],
        'windows'        => [self::class, 'cycleWindowsClient'],
    ];

    public static function cycleFromVersion(string $product, string $osName): ?string
    {
        $product = strtolower(trim($product));
        $name    = strtolower($osName);

        if (isset(self::CYCLE_RULES[$product])) {
            return (self::CYCLE_RULES[$product])($name);
        }

        // Ubuntu: YY.MM (keep two dotted groups)
        if ($product === 'ubuntu' && preg_match('/(\d{2}\.\d{2})/', $name, $m)) {
            return $m[1];
        }

        // Generic: first integer run = major version (rhel, debian, centos, sles, …)
        if (preg_match('/(?:release\s+)?(\d+)(?:\.\d+)*/', $name, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function cycleWindowsServer(string $name): ?string
    {
        if (!preg_match('/server\s+(\d{4})(\s*r2)?/', $name, $m)) {
            return null;
        }
        return $m[1] . (isset($m[2]) && trim($m[2]) !== '' ? '-R2' : '');
    }

    private static function cycleWindowsClient(string $name): ?string
    {
        if (preg_match('/windows\s+(\d{1,2})/', $name, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function fuzzyProduct(string $osName, array $productSlugs): ?string
    {
        $name = strtolower($osName);

        // Explicit aliases first — GLPI OS names rarely contain the raw slug.
        $aliases = [
            'red hat enterprise linux' => 'rhel',
            'rhel'                     => 'rhel',
            'ubuntu'                   => 'ubuntu',
            'debian'                   => 'debian',
            'centos'                   => 'centos',
            'suse'                     => 'sles',
            'windows server'           => 'windows-server',
            'esxi'                     => 'vmware-esxi',
            'vmware esxi'              => 'vmware-esxi',
        ];
        foreach ($aliases as $needle => $slug) {
            if (str_contains($name, $needle) && in_array($slug, $productSlugs, true)) {
                return $slug;
            }
        }

        // Fallback: a slug whose words all appear in the name, exactly one match.
        $hits = [];
        foreach ($productSlugs as $slug) {
            $words = explode('-', $slug);
            $all = true;
            foreach ($words as $w) {
                if ($w !== '' && !str_contains($name, $w)) { $all = false; break; }
            }
            if ($all) { $hits[] = $slug; }
        }
        return count($hits) === 1 ? $hits[0] : null;
    }

    public static function selfChecks(): array
    {
        return [
            'eolmatch/cycle-rhel' => static function (): void {
                sac_assert_eq(self::cycleFromVersion('rhel', 'Red Hat Enterprise Linux 8.6'), '8', 'rhel 8.6 -> 8');
                sac_assert_eq(self::cycleFromVersion('rhel', 'Red Hat Enterprise Linux release 7.9'), '7', 'rhel 7.9 -> 7');
            },
            'eolmatch/cycle-ubuntu' => static function (): void {
                sac_assert_eq(self::cycleFromVersion('ubuntu', 'Ubuntu 20.04.6 LTS'), '20.04', 'ubuntu 20.04.x -> 20.04');
                sac_assert_eq(self::cycleFromVersion('ubuntu', 'Ubuntu 22.04 LTS'), '22.04', 'ubuntu 22.04');
            },
            'eolmatch/cycle-windows-server' => static function (): void {
                sac_assert_eq(self::cycleFromVersion('windows-server', 'Microsoft Windows Server 2019 Standard'), '2019', 'win server 2019');
                sac_assert_eq(self::cycleFromVersion('windows-server', 'Windows Server 2012 R2'), '2012-R2', 'win server 2012 R2');
            },
            'eolmatch/cycle-unknown' => static function (): void {
                sac_assert_eq(self::cycleFromVersion('rhel', 'Some Linux'), null, 'no digits -> null');
            },
            'eolmatch/fuzzy' => static function (): void {
                $slugs = ['rhel', 'ubuntu', 'windows-server', 'debian', 'centos'];
                sac_assert_eq(self::fuzzyProduct('Red Hat Enterprise Linux 8.6', $slugs), 'rhel', 'RHEL -> rhel');
                sac_assert_eq(self::fuzzyProduct('Ubuntu 20.04.6 LTS', $slugs), 'ubuntu', 'Ubuntu -> ubuntu');
                sac_assert_eq(self::fuzzyProduct('Microsoft Windows Server 2019', $slugs), 'windows-server', 'win -> windows-server');
                sac_assert_eq(self::fuzzyProduct('TotallyUnknown OS', $slugs), null, 'no hit -> null');
            },
        ];
    }
}
