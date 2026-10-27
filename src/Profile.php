<?php

namespace GlpiPlugin\Sac;

use CommonGLPI;
use Html;

/**
 * "Security-Asset Correlation" rights tab on the core Setup → Profiles form.
 *
 * Ported from riskregister/src/Profile.php (VM-verified). Plugin rights only
 * appear in Setup → Profiles when a plugin contributes a Profile tab; we
 * register this class with
 *   Plugin::registerClass(self::class, ['addtabon' => ['Profile']])
 * (see plugin_init_sac()). GLPI then calls the two hooks below against each
 * core \Profile item.
 *
 * Persistence: the tab renders a real `<form method='post'>` posting to core's
 * own front/profile.form.php (\Profile::getFormURL()) with `update` + `id` + the
 * matrix fields. \Profile::prepareInputForUpdate() loops over every right in
 * ProfileRight::getAllPossibleRights() (which includes plugin_sac_finding /
 * plugin_sac_config after install) and \Profile::post_updateItem() hands them to
 * ProfileRight::updateProfileRights(). No custom handler, no manual CSRF —
 * Html::closeForm() emits the token and core validates it.
 *
 * Extends core \Profile so displayRightsChoiceMatrix() and the rights-loaded
 * ->fields array are available unchanged; $rightname stays 'profile', so the
 * tab is visible/editable exactly when core profile administration is.
 */
class Profile extends \Profile
{
    public static function getTypeName($nb = 0)
    {
        return __('Security-Asset Correlation', 'sac');
    }

    /**
     * Non-static: core \Profile::getTabNameForItem() is an instance method and
     * PHP forbids re-declaring an inherited instance method as static. GLPI's
     * tab dispatcher calls this on an instance anyway.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0 && $withtemplate === 0) {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, 'ti ti-shield-check');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0) {
            (new self())->showRightsForm((int) $item->getID());
        }
        return true;
    }

    /** Render the SAC rights matrix for one profile. */
    public function showRightsForm(int $profiles_id): void
    {
        // A fresh core \Profile instance: post_getFromDB() merges every
        // profilerights row into ->fields, which displayRightsChoiceMatrix()
        // reads to pre-check the boxes.
        $profile = new \Profile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }

        $canedit = self::canUpdate();

        $rights = [];
        foreach (RightDefinition::names() as $name) {
            $rights[] = [
                'itemtype' => '',
                'rights'   => RightDefinition::rightsMatrix($name),
                'label'    => self::labelFor($name),
                'field'    => $name,
            ];
        }

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlescape(\Profile::getFormURL()) . "' data-track-changes='true'>";
        }

        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);

        if ($canedit) {
            echo "<div class='center mt-2'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }

    private static function labelFor(string $name): string
    {
        return match ($name) {
            RightDefinition::FINDING => __('Findings & reconciliation', 'sac'),
            RightDefinition::CONFIG  => __('Configuration', 'sac'),
            default                  => $name,
        };
    }
}
