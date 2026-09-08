<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

Html::header(__('Security Assessment', 'sac'), '', 'sac', 'overview');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/overview.html.twig', ['cards' => []]);

Html::footer();
