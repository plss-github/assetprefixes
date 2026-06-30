<?php

function plugin_assetprefixes_install() {
  $plugin = new Plugin();
  $plugin->getFromDBbyDir('assetprefixes');
  $version = $plugin->fields['version'];

  $migration = new Migration($version);

  PluginAssetprefixesPrefixRule::installBaseData($migration);
  PluginAssetprefixesPrefixEntry::installBaseData($migration);

  $migration->executeMigration();
  return true;
}

function plugin_assetprefixes_uninstall() {
  PluginAssetprefixesPrefixEntry::uninstall();
  PluginAssetprefixesPrefixRule::uninstall();

  $pref = new DisplayPreference();
  $pref->deleteByCriteria(['itemtype' => ['LIKE', 'PluginAssetprefixes%']]);

  return true;
}
