<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

Session::checkRight('config', READ);

Html::header(
  PluginAssetprefixesPrefixRule::getTypeName(Session::getPluralNumber()),
  $_SERVER['PHP_SELF'],
  'config',
  'pluginassetprefixesprefixrule'
);

Search::show('PluginAssetprefixesPrefixRule');

Html::footer();
