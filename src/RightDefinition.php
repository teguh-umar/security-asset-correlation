<?php

namespace GlpiPlugin\Sac;

use ProfileRight;

final class RightDefinition
{
    public const FINDING = 'plugin_sac_finding';
    public const CONFIG  = 'plugin_sac_config';

    /** right name => allowed mask (spec §7). */
    public const ALL = [
        self::FINDING => ALLSTANDARDRIGHT, // 31 — finding + reconcile data (R/U/C/D/P)
        self::CONFIG  => READ | UPDATE,    // 3  — SAC config keys
    ];

    public static function names(): array
    {
        return [self::FINDING, self::CONFIG];
    }

    /**
     * Column labels for one right, for Profile::displayRightsChoiceMatrix().
     *
     * @return array<int,string>
     */
    public static function rightsMatrix(string $name): array
    {
        return match ($name) {
            self::FINDING => [
                READ   => __('Read'),
                UPDATE => __('Update'),
                CREATE => __('Create'),
                DELETE => __('Delete'),
                PURGE  => __('Delete permanently'),
            ],
            self::CONFIG => [
                READ   => __('Read'),
                UPDATE => __('Update'),
            ],
            default => [],
        };
    }

    public static function addOnInstall(): void
    {
        // Only ever seed rights that don't exist yet (hub §4): a CLI install has no
        // $_SESSION so it can't key off the "installing profile", and a web
        // reinstall/upgrade must never re-seed a right an admin already tuned by
        // hand. Once $toAdd is empty, this is a true no-op — every run after the
        // first leaves the profile matrix exactly as the admin left it.
        $toAdd = array_diff(self::names(), array_keys(ProfileRight::getAllPossibleRights()));
        if ($toAdd === []) {
            return;
        }
        ProfileRight::addProfileRights($toAdd);

        // Full access for Super-Admin (works even without a session, e.g. a fresh
        // `plugin:install --force`), filtered through $toAdd like every other
        // profile below — the easy branch to forget.
        $fullAccess = array_intersect_key(self::ALL, array_flip($toAdd));

        global $DB;
        foreach ($DB->request(['FROM' => \Profile::getTable()]) as $profile) {
            $pid = (int) $profile['id'];
            if ($profile['name'] === 'Super-Admin') {
                ProfileRight::updateProfileRights($pid, $fullAccess);
                continue;
            }
            // Everyone else inherits SAC access from their existing core 'config'
            // mask, so a read-only profile gets read-only SAC and a no-access
            // profile gets none.
            $configRight = (int) ProfileRight::getProfileRight($pid, 'config');
            $seed = [];
            foreach ($toAdd as $right) {
                $seed[$right] = self::ALL[$right] & $configRight;
            }
            ProfileRight::updateProfileRights($pid, $seed);
        }
    }

    public static function removeOnUninstall(): void
    {
        ProfileRight::deleteProfileRights(self::names());
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB / ProfileRight. */
    public static function selfChecks(): array
    {
        return [
            'db/rightdefinition-preserves-tuned-matrix' => static function (): void {
                global $DB;
                $row = $DB->request(['FROM' => \Profile::getTable(), 'LIMIT' => 1])->current();
                sac_assert($row !== null, 'at least one profile exists to test against');
                $pid = (int) $row['id'];

                $original = (int) ProfileRight::getProfileRight($pid, self::FINDING);
                try {
                    // Simulate an admin narrowing the matrix by hand.
                    ProfileRight::updateProfileRights($pid, [self::FINDING => READ]);
                    sac_assert_eq(
                        (int) ProfileRight::getProfileRight($pid, self::FINDING),
                        READ,
                        'right narrowed to READ before re-install'
                    );

                    // Rights already exist -> addOnInstall() must be a no-op.
                    self::addOnInstall();

                    sac_assert_eq(
                        (int) ProfileRight::getProfileRight($pid, self::FINDING),
                        READ,
                        'narrowed right survives a re-install (no clobber)'
                    );
                } finally {
                    ProfileRight::updateProfileRights($pid, [self::FINDING => $original]);
                }
            },
        ];
    }
}
