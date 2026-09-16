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

        // Full access, unfiltered by $toAdd, every run — "Super-Admin selalu
        // penuh": whoever can administer core config must always be able to
        // configure this plugin too, even on a `--force` reinstall after an
        // admin narrowed everyone else's matrix. Identified by capability
        // (holds UPDATE on core 'config'), never by profile name/id — names
        // are renameable/localised and id=4 is only the default-install value.
        $fullAccess = self::ALL;

        global $DB;
        foreach ($DB->request(['FROM' => \Profile::getTable()]) as $profile) {
            $pid          = (int) $profile['id'];
            $configRight  = (int) ProfileRight::getProfileRight($pid, 'config');

            if (($configRight & UPDATE) !== 0) {
                ProfileRight::updateProfileRights($pid, $fullAccess);
                continue;
            }

            // Everyone else inherits SAC access from their existing core
            // 'config' mask (so a read-only profile gets read-only SAC), and
            // only for rights that don't already exist.
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
                // Must be a non-super-admin profile: addOnInstall() now always
                // resets a config-UPDATE profile to full access regardless of
                // $toAdd, so testing "narrowed value survives" against one
                // would fail for the right reason but the wrong test.
                global $DB;
                $pid = null;
                foreach ($DB->request(['FROM' => \Profile::getTable()]) as $row) {
                    $configRight = (int) ProfileRight::getProfileRight((int) $row['id'], 'config');
                    if (($configRight & UPDATE) === 0) {
                        $pid = (int) $row['id'];
                        break;
                    }
                }
                sac_assert($pid !== null, 'at least one non-super-admin profile exists to test against');

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

            'db/rightdefinition-super-admin-always-full' => static function (): void {
                // A profile with UPDATE on core 'config' must always get the
                // plugin's full mask, even if it was previously narrowed and
                // even though the rights already exist (not filtered by $toAdd).
                global $DB;
                $pid = null;
                foreach ($DB->request(['FROM' => \Profile::getTable()]) as $row) {
                    $configRight = (int) ProfileRight::getProfileRight((int) $row['id'], 'config');
                    if (($configRight & UPDATE) !== 0) {
                        $pid = (int) $row['id'];
                        break;
                    }
                }
                sac_assert($pid !== null, 'at least one profile holds UPDATE on core config to test against');

                $original = (int) ProfileRight::getProfileRight($pid, self::FINDING);
                try {
                    ProfileRight::updateProfileRights($pid, [self::FINDING => READ]);
                    sac_assert_eq(
                        (int) ProfileRight::getProfileRight($pid, self::FINDING),
                        READ,
                        'right narrowed to READ before re-install'
                    );

                    self::addOnInstall();

                    sac_assert_eq(
                        (int) ProfileRight::getProfileRight($pid, self::FINDING),
                        self::ALL[self::FINDING],
                        'super-admin (config UPDATE) always gets the full mask back'
                    );
                } finally {
                    ProfileRight::updateProfileRights($pid, [self::FINDING => $original]);
                }
            },
        ];
    }
}
