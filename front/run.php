<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

Html::header(__('Security Assessment', 'sac'), '', 'sac', 'runs');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/reconcile.html.twig', ['rows' => []]);

Html::footer();
