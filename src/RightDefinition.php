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
        // Only add rights that don't already exist (guards the UNIQUE-key fatal).
        $missing = array_diff(self::names(), array_keys(ProfileRight::getAllPossibleRights()));
        if ($missing !== []) {
            ProfileRight::addProfileRights($missing);
        }
        // Seed the current (super-admin) profile off its core 'config' mask so the
        // installing admin can see SAC immediately.
        $pid = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($pid > 0) {
            foreach (self::names() as $r) {
                ProfileRight::updateProfileRights($pid, [$r => (READ | UPDATE | CREATE | PURGE)]);
            }
        }
    }

    public static function removeOnUninstall(): void
    {
        ProfileRight::deleteProfileRights(self::names());
    }
}
