<?php

use GlpiPlugin\Sac\Config;

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_config', READ);

if (isset($_POST['update'])) {
    Session::checkRight('plugin_sac_config', UPDATE);
    $keys = array_keys(Config::DEFAULTS);
    $save = [];
    foreach ($keys as $k) {
        if (array_key_exists($k, $_POST)) {
            $save[$k] = is_array($_POST[$k]) ? json_encode(array_values($_POST[$k])) : $_POST[$k];
        }
    }
    \Config::setConfigurationValues(Config::CONTEXT, $save);
    Session::addMessageAfterRedirect(__('Updated', 'sac'));
    Html::back();
}

Html::header(__('Security Assessment', 'sac'), '', 'sac', 'setup');

$values = \Config::getConfigurationValues(Config::CONTEXT);
$ctx = [
    'action'   => Html::getPrefixedUrl('/plugins/sac/front/config.php'),
    'values'   => $values,
    'defaults' => Config::DEFAULTS,
];
\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/config.html.twig', $ctx);

Html::footer();
