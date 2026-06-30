<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

class PluginAssetprefixesPrefixRule extends CommonDBTM {
  static $rightname = 'config';

  static function getTypeName($nb = 0) {
    return _n('Regra de prefixo', 'Regras de prefixo', $nb, 'assetprefixes');
  }

  static function getIcon() {
    return 'ti ti-tag';
  }

  // -------------------------------------------------------------------------
  // Tipos de ativo suportados e seus metadados de subtipo
  // -------------------------------------------------------------------------

  static function getSupportedItemtypes(): array {
    return [
      'Computer'         => _n('Computer', 'Computers', 1),
      'Monitor'          => _n('Monitor', 'Monitors', 1),
      'NetworkEquipment' => __('Network device'),
      'Peripheral'       => _n('Peripheral', 'Peripherals', 1),
      'Phone'            => _n('Phone', 'Phones', 1),
      'Printer'          => _n('Printer', 'Printers', 1),
    ];
  }

  // Retorna a classe GLPI do subtipo (ex: ComputerType) para o itemtype
  static function getSubtypeForItemtype(string $itemtype): ?string {
    return [
      'Computer'         => 'ComputerType',
      'Monitor'          => 'MonitorType',
      'NetworkEquipment' => 'NetworkEquipmentType',
      'Peripheral'       => 'PeripheralType',
      'Phone'            => 'PhoneType',
      'Printer'          => 'PrinterType',
    ][$itemtype] ?? null;
  }

  // Retorna o campo do ativo que contém o ID do subtipo
  static function getSubtypeField(string $itemtype): ?string {
    return [
      'Computer'         => 'computertypes_id',
      'Monitor'          => 'monitortypes_id',
      'NetworkEquipment' => 'networktypes_id',
      'Peripheral'       => 'peripheraltypes_id',
      'Phone'            => 'phonetypes_id',
      'Printer'          => 'printertypes_id',
    ][$itemtype] ?? null;
  }

  static function getSubtypeLabel(string $itemtype): string {
    return [
      'Computer'         => __('Tipo de computador', 'assetprefixes'),
      'Monitor'          => __('Tipo de monitor', 'assetprefixes'),
      'NetworkEquipment' => __('Tipo de equipamento', 'assetprefixes'),
      'Peripheral'       => __('Tipo de periférico', 'assetprefixes'),
      'Phone'            => __('Tipo de telefone', 'assetprefixes'),
      'Printer'          => __('Tipo de impressora', 'assetprefixes'),
    ][$itemtype] ?? __('Tipo', 'assetprefixes');
  }

  // -------------------------------------------------------------------------
  // Instalação
  // -------------------------------------------------------------------------

  static function installBaseData(Migration $migration) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `name` varchar(255) COLLATE {$default_collation} NOT NULL DEFAULT '',
        `itemtype` varchar(100) COLLATE {$default_collation} NOT NULL DEFAULT '',
        `global_index` int NOT NULL DEFAULT '1',
        `is_active` tinyint(1) NOT NULL DEFAULT '0',
        `date_creation` timestamp NULL DEFAULT NULL,
        `date_mod` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `itemtype` (`itemtype`),
        KEY `is_active` (`is_active`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::getTable());
    return true;
  }

  // -------------------------------------------------------------------------
  // Permissões
  // -------------------------------------------------------------------------

  static function canCreate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canView(): bool   { return Session::haveRight(self::$rightname, READ); }
  static function canUpdate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canPurge(): bool  { return Session::haveRight(self::$rightname, UPDATE); }

  public function cleanDBonPurge() {
    global $DB;
    $DB->delete(PluginAssetprefixesPrefixEntry::getTable(), [
      'plugin_assetprefixes_prefixrules_id' => $this->fields['id'],
    ]);
  }

  // -------------------------------------------------------------------------
  // Search
  // -------------------------------------------------------------------------

  public function rawSearchOptions() {
    $tab = [];
    $tab[] = ['id' => 'common', 'name' => __('Characteristics')];
    $tab[] = [
      'id'            => '1',
      'table'         => $this->getTable(),
      'field'         => 'name',
      'name'          => __('Name'),
      'datatype'      => 'itemlink',
      'massiveaction' => false,
    ];
    $tab[] = [
      'id'            => '2',
      'table'         => $this->getTable(),
      'field'         => 'itemtype',
      'name'          => __('Tipo de ativo', 'assetprefixes'),
      'datatype'      => 'specific',
      'massiveaction' => false,
    ];
    $tab[] = [
      'id'            => '3',
      'table'         => $this->getTable(),
      'field'         => 'global_index',
      'name'          => __('Índice global', 'assetprefixes'),
      'datatype'      => 'number',
      'massiveaction' => false,
    ];
    $tab[] = [
      'id'            => '4',
      'table'         => $this->getTable(),
      'field'         => 'is_active',
      'name'          => __('Active'),
      'datatype'      => 'bool',
      'massiveaction' => true,
    ];
    return $tab;
  }

  // -------------------------------------------------------------------------
  // Formulário
  // -------------------------------------------------------------------------

  function showForm($ID, array $options = []) {
    $this->initForm($ID, $options);
    $this->showFormHeader($options);

    $is_new = $this->isNewItem();

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Name') . "</td>";
    echo "<td>" . Html::input('name', ['value' => $this->fields['name']]) . "</td>";
    echo "<td>" . __('Active') . "</td>";
    echo "<td>"; Dropdown::showYesNo('is_active', $this->fields['is_active']); echo "</td>";
    echo "</tr>";

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Tipo de ativo', 'assetprefixes') . "</td>";
    echo "<td>";
    Dropdown::showFromArray('itemtype', self::getSupportedItemtypes(), [
      'value' => $this->fields['itemtype'] ?? '',
      'display_emptychoice' => !$is_new,
    ]);
    echo "</td>";
    echo "<td>" . __('Índice global', 'assetprefixes') . "</td>";
    echo "<td>";
    echo Html::input('global_index', [
      'value' => (int)($this->fields['global_index'] ?? 1),
      'type'  => 'number',
      'min'   => '0',
      'style' => 'width:100px',
    ]);
    echo "&nbsp;<small class='text-muted'>" . __('Ponto de partida da numeração global', 'assetprefixes') . "</small>";
    echo "</td>";
    echo "</tr>";

    $this->showFormButtons($options);
    return true;
  }

  function defineTabs($options = []) {
    $ong = [];
    $this->addDefaultFormTab($ong);
    $this->addStandardTab(__CLASS__, $ong, $options);
    return $ong;
  }

  public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
    if ($item->getType() === static::class && !$item->isNewItem()) {
      $count = countElementsInTable(
        PluginAssetprefixesPrefixEntry::getTable(),
        ['plugin_assetprefixes_prefixrules_id' => $item->getID()]
      );
      return [1 => CommonGLPI::createTabEntry(
        __('Prefixos', 'assetprefixes'),
        $count,
        static::class,
        'ti ti-tags'
      )];
    }
    return '';
  }

  public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
    if ($item->getType() === static::class && $tabnum == 1) {
      PluginAssetprefixesPrefixEntry::showForRule($item);
    }
    return true;
  }

  // -------------------------------------------------------------------------
  // Lógica de aplicação de prefixo
  // -------------------------------------------------------------------------

  static function applyPrefix(CommonDBTM $item): void {
    global $DB;

    $itemtype = get_class($item);

    // Busca a regra ativa para este tipo de ativo
    $rule_iter = $DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => ['itemtype' => $itemtype, 'is_active' => 1],
      'LIMIT' => 1,
    ]);

    if (!count($rule_iter)) return;
    $rule = $rule_iter->current();

    // Descobre o ID do subtipo do ativo (ex: computertypes_id)
    $subtype_field = self::getSubtypeField($itemtype);
    $subtype_id    = ($subtype_field && isset($item->fields[$subtype_field]))
      ? (int)$item->fields[$subtype_field]
      : 0;

    // Busca entradas da regra, priorizando match exato de subtipo
    $entries = PluginAssetprefixesPrefixEntry::getEntriesForRule($rule['id']);
    if (empty($entries)) return;

    $entry = self::findMatchingEntry($entries, $subtype_id);
    if (!$entry) return;

    // Monta a tag
    $tag = $entry['prefix'];
    if ((int)$entry['per_type_index'] > 0) {
      $number = PluginAssetprefixesPrefixEntry::consumeIndex(
        $entry['id'],
        (int)$entry['per_type_index'],
        (int)$entry['current_count']
      );
      $tag .= str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    // Aplica ao nome do ativo via DB direto (evita loop de hooks)
    $original_name = trim($item->fields['name'] ?? '');
    $new_name      = $original_name !== '' ? $tag . $original_name : $tag;

    $DB->update($item::getTable(), ['name' => $new_name], ['id' => $item->getID()]);
    $item->fields['name'] = $new_name;
  }

  // Busca a entrada que melhor corresponde ao subtipo do ativo:
  // 1. Match exato de subtipo, 2. Catch-all (filter_items_id = 0)
  private static function findMatchingEntry(array $entries, int $subtype_id): ?array {
    $fallback = null;
    foreach ($entries as $e) {
      if ((int)$e['filter_items_id'] === $subtype_id && $subtype_id > 0) return $e;
      if ((int)$e['filter_items_id'] === 0) $fallback = $e;
    }
    return $fallback;
  }
}
