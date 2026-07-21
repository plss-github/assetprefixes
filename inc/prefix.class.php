<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

// "Família" de prefixo: agrupa por tipo de ativo + entidade. O padrão/contador
// em si é definido por subtipo na aba "Padrões por subtipo" (PrefixPattern).
class PluginAssetprefixesPrefix extends CommonDBTM {
  static $rightname = 'config';

  static function getTypeName($nb = 0) {
    return _n('Família de prefixo', 'Famílias de prefixo', $nb, 'assetprefixes');
  }

  static function getIcon() {
    return 'ti ti-hash';
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

  // Classe GLPI do subtipo (ex: ComputerType) para o itemtype
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

  // Campo do ativo que contém o ID do subtipo
  static function getSubtypeField(string $itemtype): ?string {
    return [
      'Computer'         => 'computertypes_id',
      'Monitor'          => 'monitortypes_id',
      'NetworkEquipment' => 'networkequipmenttypes_id',
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
    ][$itemtype] ?? __('Subtipo', 'assetprefixes');
  }

  // Dropdown de subtipo para um itemtype já conhecido (usado pela aba de padrões).
  // $form_id: quando o campo precisa ficar associado a um <form> fora da árvore
  // DOM onde ele está (ex: dentro de uma célula de tabela — ver PrefixPattern::showForPrefix).
  static function showSubtypeDropdown(string $itemtype, int $value = 0, ?string $form_id = null): void {
    $subtype_class = $itemtype ? self::getSubtypeForItemtype($itemtype) : null;
    $specific_tags = $form_id ? ['form' => $form_id] : [];
    if ($subtype_class && class_exists($subtype_class)) {
      Dropdown::show($subtype_class, [
        'name'                => 'subtype_id',
        'value'               => $value,
        'display_emptychoice' => true,
        'emptylabel'          => __('Global (todos os subtipos)', 'assetprefixes'),
        'specific_tags'       => $specific_tags,
      ]);
    } else {
      echo "<em class='text-muted'>" . __('Sem subtipo disponível — prefixo global', 'assetprefixes') . "</em>";
      echo Html::hidden('subtype_id', array_merge(['value' => 0], $specific_tags));
    }
  }

  // Multi-select de subtipos (0 = global) para criar vários padrões de uma vez.
  // $exclude_ids: subtipos que já têm padrão configurado nesta família (0 = global já usado).
  static function showSubtypeMultiselect(string $itemtype, array $exclude_ids = []): void {
    global $DB;

    // Sempre inicializado: sem isto, um itemtype sem subtipos cadastrados (ou
    // sem classe de subtipo) deixaria $options indefinido → showFromArray(null)
    // estoura em GLPI 11. Opção 0 = padrão global (fallback da família).
    $options       = [0 => __('Global (todos os subtipos)', 'assetprefixes')];
    $subtype_class = $itemtype ? self::getSubtypeForItemtype($itemtype) : null;

    if ($subtype_class && class_exists($subtype_class)) {
      $iter = $DB->request(['FROM' => $subtype_class::getTable(), 'ORDERBY' => ['name ASC']]);
      foreach ($iter as $row) {
        $options[$row['id']] = $row['name'];
      }
    }

    // Remove subtipos que já têm um padrão nesta família (NULL = global → chave 0).
    foreach ($exclude_ids as $excluded) {
      unset($options[$excluded === null ? 0 : (int)$excluded]);
    }

    Dropdown::showFromArray('subtype_id', $options, [
      'multiple' => true,
      'values'   => [],
      'width'    => '100%',
    ]);
  }

  // -------------------------------------------------------------------------
  // Instalação
  // -------------------------------------------------------------------------

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
    $table              = self::getTable();

    if (!$DB->tableExists($table)) {
      $DB->doQuery("CREATE TABLE `$table` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `name` varchar(255) COLLATE {$default_collation} NOT NULL DEFAULT '',
        `itemtype` varchar(100) COLLATE {$default_collation} NOT NULL DEFAULT '',
        `is_active` tinyint(1) NOT NULL DEFAULT '1',
        `is_recursive` tinyint(1) NOT NULL DEFAULT '1',
        `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `date_creation` timestamp NULL DEFAULT NULL,
        `date_mod` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `itemtype` (`itemtype`),
        KEY `is_active` (`is_active`),
        KEY `entities_id` (`entities_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    } elseif ($DB->fieldExists($table, 'pattern')) {
      // Migração do modelo antigo (pattern/counter/subtipo direto na família)
      // para a nova tabela de padrões por subtipo (PrefixPattern).
      PluginAssetprefixesPrefixPattern::installBaseData($migration, $version);

      $rows = $DB->request(['FROM' => $table]);
      foreach ($rows as $row) {
        $counter_current = (int)$row['counter_current'] > 0
          ? (int)$row['counter_current']
          : max(0, (int)$row['counter_start'] - 1);
        $DB->insert(PluginAssetprefixesPrefixPattern::getTable(), [
          'plugin_assetprefixes_prefixes_id' => $row['id'],
          'subtype_id'                       => $row['subtype_id'],
          'pattern'                          => $row['pattern'],
          'counter_current'                  => $counter_current,
        ]);
      }

      $migration->dropField($table, 'subtype_field');
      $migration->dropField($table, 'subtype_id');
      $migration->dropField($table, 'pattern');
      $migration->dropField($table, 'counter_start');
      $migration->dropField($table, 'counter_current');
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
    $DB->delete(PluginAssetprefixesPrefixField::getTable(), [
      'plugin_assetprefixes_prefixes_id' => $this->fields['id'],
    ]);
    $DB->delete(PluginAssetprefixesPrefixPattern::getTable(), [
      'plugin_assetprefixes_prefixes_id' => $this->fields['id'],
    ]);
  }

  // -------------------------------------------------------------------------
  // Validação
  // -------------------------------------------------------------------------

  // Uma família ativa por itemtype + entidade
  private function findConflictingPrefix(array $input): ?array {
    global $DB;

    $itemtype    = $input['itemtype'] ?? ($this->fields['itemtype'] ?? '');
    $entities_id = $input['entities_id'] ?? ($this->fields['entities_id'] ?? 0);

    $where = [
      'itemtype'    => $itemtype,
      'entities_id' => $entities_id,
    ];

    if (!$this->isNewItem()) {
      $where[] = ['NOT' => ['id' => $this->fields['id']]];
    }

    $iter = $DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'LIMIT' => 1]);
    return count($iter) ? $iter->current() : null;
  }

  private function validateInput(array $input): bool {
    $itemtype = $input['itemtype'] ?? ($this->fields['itemtype'] ?? '');
    if (!array_key_exists($itemtype, self::getSupportedItemtypes())) {
      Session::addMessageAfterRedirect(
        __('Tipo de ativo não suportado.', 'assetprefixes'),
        false,
        ERROR
      );
      return false;
    }

    if ($this->findConflictingPrefix($input)) {
      Session::addMessageAfterRedirect(
        __('Já existe uma família de prefixo configurada para este tipo de ativo e entidade.', 'assetprefixes'),
        false,
        ERROR
      );
      return false;
    }

    return true;
  }

  public function prepareInputForAdd($input) {
    if (!$this->validateInput($input)) {
      return false;
    }
    return $input;
  }

  public function prepareInputForUpdate($input) {
    if (!$this->validateInput($input)) {
      return false;
    }
    return $input;
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
      'field'         => 'is_active',
      'name'          => __('Active'),
      'datatype'      => 'bool',
      'massiveaction' => true,
    ];

    $tab = array_merge($tab, Entity::getSearchOptionsToAdd());

    return $tab;
  }

  static function getSpecificValueToDisplay($field, $values, array $options = []) {
    if (!is_array($values)) {
      $values = [$field => $values];
    }
    if ($field === 'itemtype') {
      $types = self::getSupportedItemtypes();
      return $types[$values[$field]] ?? $values[$field];
    }
    return parent::getSpecificValueToDisplay($field, $values, $options);
  }

  static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {
    if ($field === 'itemtype') {
      $options['value'] = $values[$field] ?? $values;
      return Dropdown::showItemTypes($name, array_keys(self::getSupportedItemtypes()), $options + ['display' => false]);
    }
    return parent::getSpecificValueToSelect($field, $name, $values, $options);
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
    echo "<td>"; Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1); echo "</td>";
    echo "</tr>";

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Entity') . "</td>";
    echo "<td>";
    Entity::dropdown(['value' => $this->fields['entities_id'] ?? $_SESSION['glpiactive_entity']]);
    echo "</td>";
    echo "<td>" . __('Sub-entities') . "</td>";
    echo "<td>";
    // showYesNo (não showCheckbox): um checkbox desmarcado não é enviado no POST,
    // então nunca daria pra DESLIGAR a recursividade pela tela. Padrão = sim em item novo.
    Dropdown::showYesNo('is_recursive', $is_new ? 1 : (int)$this->fields['is_recursive']);
    echo "</td>";
    echo "</tr>";

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Tipo de ativo', 'assetprefixes') . "</td>";
    echo "<td>";
    if ($is_new) {
      Dropdown::showItemTypes('itemtype', array_keys(self::getSupportedItemtypes()), [
        'value'               => $this->fields['itemtype'] ?? '',
        'display_emptychoice' => true,
      ]);
    } else {
      // Travado após a criação: mudar o itemtype orfanaria os padrões/campos
      // (que referenciam subtipos do itemtype original).
      echo htmlspecialchars(self::getSupportedItemtypes()[$this->fields['itemtype']] ?? $this->fields['itemtype']);
    }
    echo "</td>";
    echo "<td colspan='2'></td>";
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
      $pattern_count = countElementsInTable(
        PluginAssetprefixesPrefixPattern::getTable(),
        ['plugin_assetprefixes_prefixes_id' => $item->getID()]
      );
      $field_count = countElementsInTable(
        PluginAssetprefixesPrefixField::getTable(),
        ['plugin_assetprefixes_prefixes_id' => $item->getID()]
      );
      // 4º arg (ícone) usado pelo GLPI 11; GLPI 10 ignora args extras.
      return [
        1 => CommonGLPI::createTabEntry(__('Padrões por subtipo', 'assetprefixes'), $pattern_count, static::class, 'ti ti-list-numbers'),
        2 => CommonGLPI::createTabEntry(__('Campos alvo', 'assetprefixes'), $field_count, static::class, 'ti ti-forms'),
      ];
    }
    return '';
  }

  public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
    if ($item->getType() === static::class) {
      if ($tabnum == 2) {
        PluginAssetprefixesPrefixField::showForPrefix($item);
      } else {
        PluginAssetprefixesPrefixPattern::showForPrefix($item);
      }
    }
    return true;
  }
}
