<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

Session::checkRight('config', READ);

Html::header(
  PluginAssetprefixesPrefix::getTypeName(Session::getPluralNumber()),
  $_SERVER['PHP_SELF'],
  'config',
  'pluginassetprefixesprefix'
);

Search::show('PluginAssetprefixesPrefix');

Html::footer();
