<?php

function plugin_assetprefixes_install() {
  $plugin = new Plugin();
  $plugin->getFromDBbyDir('assetprefixes');
  $version = $plugin->fields['version'];

  $migration = new Migration($version);

  PluginAssetprefixesPrefix::installBaseData($migration, $version);
  PluginAssetprefixesPrefixPattern::installBaseData($migration, $version);
  PluginAssetprefixesPrefixField::installBaseData($migration, $version);

  $migration->executeMigration();

  PluginAssetprefixesConfig::setDefaults();

  return true;
}

function plugin_assetprefixes_uninstall() {
  PluginAssetprefixesPrefixField::uninstall();
  PluginAssetprefixesPrefixPattern::uninstall();
  PluginAssetprefixesPrefix::uninstall();

  PluginAssetprefixesConfig::removeConfig();

  $pref = new DisplayPreference();
  $pref->deleteByCriteria(['itemtype' => ['LIKE', 'PluginAssetprefixes%']]);

  return true;
}
