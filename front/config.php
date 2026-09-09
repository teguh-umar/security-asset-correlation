<?php

use GlpiPlugin\Sac\Config;

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_config', READ);

if (isset($_POST['update'])) {
    Session::checkRight('plugin_sac_config', UPDATE);

    $save = [];
    foreach (Config::FIELD_TYPES as $k => $type) {
        if (!array_key_exists($k, $_POST)) {
            continue;
        }
        $v = $_POST[$k];

        switch ($type) {
            case 'bool':
                $save[$k] = ($v === '1' || $v === 1 || $v === true) ? '1' : '0';
                break;

            case 'int':
                if (!ctype_digit((string) $v)) {
                    Session::addMessageAfterRedirect(
                        sprintf(__('Invalid value for %s — expected a non-negative integer', 'sac'), $k),
                        false,
                        ERROR
                    );
                    break;
                }
                $save[$k] = (string) (int) $v;
                break;

            case 'entity':
                if (!ctype_digit((string) $v)) {
                    Session::addMessageAfterRedirect(
                        sprintf(__('Invalid value for %s — expected an entity id', 'sac'), $k),
                        false,
                        ERROR
                    );
                    break;
                }
                $save[$k] = (string) (int) $v;
                break;

            case 'json':
                $decoded = json_decode((string) $v, true);
                if (!is_array($decoded)) {
                    Session::addMessageAfterRedirect(
                        sprintf(__('Invalid value for %s — expected a JSON array', 'sac'), $k),
                        false,
                        ERROR
                    );
                    break;
                }
                $save[$k] = json_encode(array_values($decoded));
                break;

            default: // 'text'
                $save[$k] = is_array($v) ? json_encode(array_values($v)) : (string) $v;
        }
    }

    if ($save !== []) {
        \Config::setConfigurationValues(Config::CONTEXT, $save);
        Session::addMessageAfterRedirect(__('Updated', 'sac'));
    }
    Html::back();
}

Html::header(__('Security Assessment', 'sac'), '', 'sac', 'setup');

$values = \Config::getConfigurationValues(Config::CONTEXT);

// Framework-generated markup (the one legit |raw in the template).
$entityCurrent  = (int) ($values['finding_entity'] ?? Config::DEFAULTS['finding_entity']);
$entityDropdown = \Entity::dropdown([
    'name'    => 'finding_entity',
    'value'   => $entityCurrent,
    'entity'  => $_SESSION['glpiactive_entity'] ?? 0,
    'display' => false,
]);

$ctx = [
    'action'          => Html::getPrefixedUrl('/plugins/sac/front/config.php'),
    'values'          => $values,
    'defaults'        => Config::DEFAULTS,
    'types'           => Config::FIELD_TYPES,
    'entity_dropdown' => $entityDropdown,
];
\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/config.html.twig', $ctx);

Html::footer();
