<?php

namespace GlpiPlugin\Sac;

use CommonGLPI;
use Profile as CoreProfile;
use ProfileRight;
use Session;

class Profile extends CommonGLPI
{
    public static $rightname = 'profile';

    // NON-static — matches core \Profile.
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof CoreProfile) {
            return self::createTabEntry(__('Security Assessment', 'sac'));
        }
        return '';
    }

    // STATIC — matches core \Profile.
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof CoreProfile)) {
            return false;
        }
        $pid    = (int) $item->getID();
        $rights = ProfileRight::getProfileRights($pid, RightDefinition::names());

        echo "<table class='tab_cadre_fixe'>";
        foreach (RightDefinition::names() as $r) {
            $val = (int) ($rights[$r] ?? 0);
            echo "<tr class='tab_bg_2'><td>" . htmlescape($r) . "</td><td>";
            \Profile::dropdownRights(
                \Profile::getRightsFor($r) ?: [READ => __('Read'), UPDATE => __('Update')],
                $r,
                $val
            );
            echo "</td></tr>";
        }
        echo "</table>";
        return true;
    }
}
