<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

$rule = new PluginAssetprefixesPrefixRule();

if (isset($_POST['add'])) {
  $rule->check(-1, CREATE, $_POST);
  if ($new_id = $rule->add($_POST)) {
    Html::redirect($rule->getFormURL() . "?id=$new_id");
  }
  Html::back();
} elseif (isset($_POST['update'])) {
  $rule->check($_POST['id'], UPDATE);
  $rule->update($_POST);
  Html::back();
} elseif (isset($_POST['purge'])) {
  $rule->check($_POST['id'], PURGE);
  $rule->delete($_POST, 1);
  $rule->redirectToList();
}

Html::header(
  PluginAssetprefixesPrefixRule::getTypeName(Session::getPluralNumber()),
  $_SERVER['PHP_SELF'],
  'config',
  'pluginassetprefixesprefixrule'
);

$rule->display(['id' => $_GET['id'] ?? -1]);

Html::footer();
