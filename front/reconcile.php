<?php

use GlpiPlugin\Sac\EntityScope;
use GlpiPlugin\Sac\Reconcile;

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

// Mapping an OS, resolving or re-opening a row changes shared reference data,
// so it is reserved for a Super-Admin on top of the UPDATE right.
$canAct = Session::haveRight('plugin_sac_finding', UPDATE) && EntityScope::canEditReference();

if (isset($_POST['map']) || isset($_POST['resolve']) || isset($_POST['reopen'])) {
    Session::checkRight('plugin_sac_finding', UPDATE);
    if (!$canAct) {
        Session::addMessageAfterRedirect(
            __('Reconcile actions apply to every entity and are reserved for a Super-Admin', 'sac'),
            false,
            ERROR
        );
        Html::back();
    }
}

if (isset($_POST['map'])) {
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
    Reconcile::resolve((int) $_POST['resolve']);
    Session::addMessageAfterRedirect(__('Reconcile signal resolved', 'sac'));
    Html::back();
}

if (isset($_POST['reopen'])) {
    Reconcile::reopen((int) $_POST['reopen']);
    Session::addMessageAfterRedirect(__('Reconcile signal re-opened', 'sac'));
    Html::back();
}

$includeResolved = (bool) ($_GET['resolved'] ?? false);

Html::header(__('Security Assessment', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'reconcile');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'reconcile');

$base = Html::getPrefixedUrl('/plugins/sac/front/reconcile.php');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/reconcile.html.twig', [
    'action'           => $base,
    'rows'             => Reconcile::listForDisplay($includeResolved),
    'include_resolved' => $includeResolved,
    'toggle_url'       => $base . ($includeResolved ? '' : '?resolved=1'),
    'can_act'          => $canAct,
    'sees_all'         => EntityScope::seesAll(),
]);

Html::footer();
