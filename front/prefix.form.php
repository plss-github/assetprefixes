<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

$prefix = new PluginAssetprefixesPrefix();

if (isset($_POST['add'])) {
  $prefix->check(-1, CREATE, $_POST);
  if ($new_id = $prefix->add($_POST)) {
    Html::redirect($prefix->getFormURL() . "?id=$new_id");
  }
  Html::back();
} elseif (isset($_POST['update'])) {
  $prefix->check($_POST['id'], UPDATE);
  $prefix->update($_POST);
  Html::back();
} elseif (isset($_POST['purge'])) {
  $prefix->check($_POST['id'], PURGE);
  $prefix->delete($_POST, 1);
  $prefix->redirectToList();
}

Html::header(
  PluginAssetprefixesPrefix::getTypeName(Session::getPluralNumber()),
  $_SERVER['PHP_SELF'],
  'config',
  'pluginassetprefixesprefix'
);

$prefix->display(['id' => $_GET['id'] ?? -1]);

Html::footer();
