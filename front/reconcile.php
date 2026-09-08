<?php

use GlpiPlugin\Sac\Reconcile;

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

if (isset($_POST['map'])) {
    Session::checkRight('plugin_sac_finding', UPDATE);
    $product = trim((string) ($_POST['product'] ?? ''));
    $cycle   = trim((string) ($_POST['cycle'] ?? ''));
    if ($product === '' || $cycle === '') {
        Session::addMessageAfterRedirect(__('Product and cycle are required', 'sac'), false, ERROR);
        Html::back();
    }
    Reconcile::resolveEolMapping(
        (int) $_POST['map'],
        $product,
        $cycle
    );
    Session::addMessageAfterRedirect(__('EOL mapping saved; signal resolved', 'sac'));
    Html::back();
}

if (isset($_POST['resolve'])) {
    Session::checkRight('plugin_sac_finding', UPDATE);
    Reconcile::resolve((int) $_POST['resolve']);
    Session::addMessageAfterRedirect(__('Reconcile signal resolved', 'sac'));
    Html::back();
}

Html::header(__('Security Assessment', 'sac'), '', 'sac', 'reconcile');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/reconcile.html.twig', [
    'action' => Html::getPrefixedUrl('/plugins/sac/front/reconcile.php'),
    'rows'   => Reconcile::listForDisplay(),
]);

Html::footer();
