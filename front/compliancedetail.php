<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

$cid      = (int) ($_GET['computers_id'] ?? 0);
$computer = new Computer();

// can($cid, READ) enforces the viewer's own entity scope on top of the
// plugin right: in this instance a sub-entity is a different company, so a
// user must not read another entity's rule list by guessing an id in the
// URL. getFromDB() alone would not stop that.
if ($cid <= 0 || !$computer->getFromDB($cid) || !$computer->can($cid, READ)) {
    Html::displayRightError();
}

$rules = \GlpiPlugin\Sac\Compliance\RuleStore::allFor($cid);

$openCount     = 0;
$resolvedCount = 0;
foreach ($rules as $r) {
    if ((string) $r['state'] === 'resolved') {
        $resolvedCount++;
    } else {
        $openCount++;
    }
}

Html::header(__('Security-Asset Correlation', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'compliance');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'compliance');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/compliancedetail.html.twig', [
    'computer_name'  => (string) $computer->fields['name'],
    'computer_url'   => Computer::getFormURLWithID($cid),
    'rules'          => $rules,
    'open_count'     => $openCount,
    'resolved_count' => $resolvedCount,
    'back_url'       => Html::getPrefixedUrl('/plugins/sac/front/compliance.php'),
]);

Html::footer();
