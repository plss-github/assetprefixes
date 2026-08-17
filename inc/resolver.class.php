<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

// Interpretação do pattern, emissão de contador e aplicação nos ativos criados.
class PluginAssetprefixesResolver {

  // Divide o pattern em prefixo/máscara-de-zeros/sufixo (spec §5.1)
  static function parsePattern(string $pattern): array {
    if (preg_match('/^(.*?)(0+)(.*)$/', $pattern, $m)) {
      return ['before' => $m[1], 'padding' => strlen($m[2]), 'after' => $m[3]];
    }
    // Sem máscara: prefixo fixo + contador sem padding (spec §5.1, comportamento documentado)
    return ['before' => $pattern, 'padding' => 0, 'after' => ''];
  }

  // Próxima emissão = contador (último número já usado) + 1.
  // `counter_current` é o único campo editável — ver PrefixPattern::showForPrefix().
  static function computeNext(int $counter): int {
    return $counter + 1;
  }

  static function format(string $pattern, int $number): string {
    $p = self::parsePattern($pattern);
    if ($p['padding'] === 0) {
      return $p['before'] . $number . $p['after'];
    }
    return $p['before'] . str_pad((string)$number, $p['padding'], '0', STR_PAD_LEFT) . $p['after'];
  }

  // Emissão atômica: SELECT ... FOR UPDATE + advance do contador (spec §5.3),
  // agora sobre um PrefixPattern (subtipo dentro de uma família)
  static function issue(int $pattern_id): ?string {
    global $DB;

    $table = PluginAssetprefixesPrefixPattern::getTable();

    $DB->beginTransaction();
    try {
      $result = $DB->doQuery(
        "SELECT `pattern`, `counter_current` FROM `$table` WHERE `id` = " . (int)$pattern_id . " FOR UPDATE"
      );
      if (!$result || $DB->numrows($result) === 0) {
        $DB->rollBack();
        return null;
      }
      $row  = $DB->fetchAssoc($result);
      $next = self::computeNext((int)$row['counter_current']);

      $DB->update($table, ['counter_current' => $next], ['id' => (int)$pattern_id]);
      $DB->commit();

      return self::format($row['pattern'], $next);
    } catch (\Throwable $e) {
      $DB->rollBack();
      throw $e;
    }
  }

  // Existe algum ativo do itemtype com $value em qualquer um dos campos nativos?
  private static function valueCollides(string $itemtype, array $native_fields, string $value): bool {
    global $DB;

    $table = getTableForItemType($itemtype);
    if (!$table) {
      return false;
    }

    $or = [];
    foreach ($native_fields as $col) {
      $or[] = [$col => $value];
    }
    if (empty($or)) {
      return false;
    }

    return countElementsInTable($table, ['OR' => $or]) > 0;
  }

  // Família aplicável: itemtype + entidade (respeitando recursividade) (spec §6)
  static function findApplicableFamily(string $itemtype, int $entities_id): ?array {
    global $DB;

    $dbu         = new DbUtils();
    $entity_crit = $dbu->getEntitiesRestrictCriteria(PluginAssetprefixesPrefix::getTable(), 'entities_id', $entities_id, true);
    $where       = array_merge(['itemtype' => $itemtype, 'is_active' => 1], $entity_crit);

    $iter = $DB->request(['FROM' => PluginAssetprefixesPrefix::getTable(), 'WHERE' => $where, 'LIMIT' => 1]);
    return count($iter) ? $iter->current() : null;
  }

  // -------------------------------------------------------------------------
  // Hooks
  // -------------------------------------------------------------------------

  // pre_item_add: resolve o prefixo, emite o número e injeta nos campos nativos do $item->input.
  // Os campos customizados (que só existem após o insert) são tratados em onItemAdd().
  static function onPreItemAdd($item): void {
    $itemtype    = get_class($item);
    $entities_id = (int)($item->input['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);

    $family = self::findApplicableFamily($itemtype, $entities_id);
    if (!$family) {
      self::debugLog("nenhuma família ativa para $itemtype na entidade #$entities_id — nada a fazer.", $itemtype);
      return;
    }

    $subtype_field = PluginAssetprefixesPrefix::getSubtypeField($itemtype);
    $subtype_id    = ($subtype_field && !empty($item->input[$subtype_field]))
      ? (int)$item->input[$subtype_field]
      : null;

    $pattern = PluginAssetprefixesPrefixPattern::findApplicablePattern((int)$family['id'], $subtype_id);
    if (!$pattern) {
      self::debugLog(sprintf(
        'família #%d encontrada, mas nenhum padrão casa com o subtipo #%s (nem padrão global).',
        (int)$family['id'],
        $subtype_id === null ? 'nenhum' : $subtype_id
      ), $itemtype);
      return;
    }

    $applicable = PluginAssetprefixesPrefixField::getApplicableFields((int)$family['id'], (int)$pattern['id']);
    [$native_fields, $custom_fields] = self::splitTargets($applicable);

    $value = self::issue((int)$pattern['id']);
    if ($value === null) {
      return;
    }

    // Validação de unicidade opcional (spec §10 / Config): se o valor gerado já
    // existe em algum dos campos nativos alvo de um ativo do mesmo itemtype,
    // avança o contador até achar um livre (gaps são aceitos — spec §10).
    // Só cobre campos nativos; campos customizados não são checados aqui.
    if (!empty($native_fields) && PluginAssetprefixesConfig::checkUniquenessEnabled()) {
      $tries = 0;
      while (self::valueCollides($itemtype, $native_fields, $value) && $tries < 1000) {
        $value = self::issue((int)$pattern['id']);
        if ($value === null) {
          return;
        }
        $tries++;
      }
    }

    foreach ($native_fields as $field_name) {
      $item->input[$field_name] = $value;
    }

    // Campos customizados: o plugin Fields monta o que vai gravar em
    // PluginFieldsContainer::preItem() -> populateData(), lendo direto de
    // $item->input[<coluna>] e SEM filtrar campos read-only. Injetar aqui faz o
    // próprio Fields persistir nosso valor (com histórico). Se o hook dele rodar
    // ANTES do nosso, a injeção chega tarde — e aí a escrita adiada de onItemAdd()
    // cobre o caso. As duas pontas juntas tornam o resultado independente da ordem
    // de carga dos plugins, que o GLPI não garante.
    self::injectCustomFieldsIntoInput($item, $custom_fields, $value);

    // Repassado para onItemAdd() via propriedade dinâmica do item (mesmo objeto em ambos os hooks)
    $item->_assetprefixes_value      = $value;
    $item->_assetprefixes_prefix_id  = (int)$family['id'];
    $item->_assetprefixes_pattern_id = (int)$pattern['id'];

    self::debugLog(sprintf(
      'valor "%s" emitido (família #%d, padrão #%d) para %s; nativos: [%s]; customizados: [%s]',
      $value,
      (int)$family['id'],
      (int)$pattern['id'],
      $itemtype,
      implode(', ', $native_fields),
      implode(', ', $custom_fields)
    ), $itemtype);
  }

  // Separa os alvos configurados por origem, preservando a ordem de cadastro.
  private static function splitTargets(array $applicable): array {
    $native = [];
    $custom = [];
    foreach ($applicable as $field) {
      if (($field['field_name'] ?? '') === '') {
        continue;
      }
      if ($field['field_type'] === 'custom') {
        $custom[] = $field['field_name'];
      } else {
        $native[] = $field['field_name'];
      }
    }
    return [$native, $custom];
  }

  // Ver chamada em onPreItemAdd(): deixa o valor no input com o nome da coluna do
  // Fields, que é exatamente onde populateData() vai procurar.
  private static function injectCustomFieldsIntoInput($item, array $custom_fields, string $value): void {
    global $DB;

    if (empty($custom_fields)) {
      return;
    }

    // Uma coluna customizada homônima de uma coluna nativa do ativo seria copiada
    // para o INSERT do próprio ativo pelo GLPI, sobrescrevendo o campo nativo. Nesse
    // caso não injetamos: só a escrita adiada atua.
    $table       = getTableForItemType(get_class($item));
    $own_columns = $table ? $DB->listFields($table) : [];

    foreach ($custom_fields as $encoded_field_name) {
      [, $column] = array_pad(explode(':', $encoded_field_name, 2), 2, null);
      if ($column === null || $column === '' || isset($own_columns[$column])) {
        continue;
      }
      $item->input[$column] = $value;
    }
  }

  // item_add: grava o valor emitido nos campos customizados, agora que o item já existe.
  //
  // O plugin Fields tem seu PRÓPRIO hook item_add, que grava os valores vindos
  // do formulário de criação do ativo (tipicamente vazios, já que o campo é
  // somente-leitura e nunca aparece preenchido nesse formulário). Se esse hook
  // rodar DEPOIS do nosso, ele sobrescreve o valor que acabamos de gravar de
  // volta pro vazio — confirmado via histórico do item (log mostra "Mudança de
  // <valor> para [vazio]" logo após a criação). Não há como garantir a ordem
  // relativa entre hooks item_add de plugins diferentes, então adiamos nossa
  // escrita via register_shutdown_function(): ela só roda depois que TODO o
  // processamento síncrono da requisição (incluindo o hook do Fields) já
  // terminou, garantindo que nosso valor seja o último a ser gravado.
  static function onItemAdd($item): void {
    if (empty($item->_assetprefixes_value) || empty($item->_assetprefixes_prefix_id)) {
      return;
    }

    $value      = $item->_assetprefixes_value;
    $itemtype   = get_class($item);
    $items_id   = $item->getID();
    $prefix_id  = (int)$item->_assetprefixes_prefix_id;
    $pattern_id = (int)($item->_assetprefixes_pattern_id ?? 0);

    [, $custom_fields] = self::splitTargets(
      PluginAssetprefixesPrefixField::getApplicableFields($prefix_id, $pattern_id)
    );

    foreach ($custom_fields as $encoded_field_name) {
      register_shutdown_function(
        [self::class, 'writeCustomFieldDeferred'],
        $itemtype,
        $items_id,
        $encoded_field_name,
        $value
      );
    }
  }

  // Executado ao final da requisição (ver onItemAdd) — recarrega o item e
  // grava o campo customizado por último, depois de qualquer outro plugin.
  public static function writeCustomFieldDeferred(string $itemtype, int $items_id, string $encoded_field_name, string $value): void {
    $item = new $itemtype();
    if ($item->getFromDB($items_id)) {
      self::writeCustomField($item, $encoded_field_name, $value);
    }
  }

  // Tipos de campo customizado (plugin Fields) compatíveis com receber uma
  // string gerada — os demais (dropdown, glpi_item, number, date, yesno, ...)
  // têm formato de valor próprio e não podem simplesmente receber o padrão.
  private const CUSTOM_FIELD_SAFE_TYPES = ['text', 'textarea', 'richtext'];

  // Log + aviso visível na sessão — usado em todo ponto de saída de
  // writeCustomField() pra tornar diagnosticável qualquer falha silenciosa
  // dessa integração best-effort com o plugin Fields.
  //
  // Event::log() grava em glpi_events, que é lido pela interface em
  // Administração > Registros: é o único canal de diagnóstico disponível quando
  // não se tem acesso ao filesystem (files/_log/assetprefixes.log) do ambiente.
  private static function warnCustomFieldFailure(string $reason, string $itemtype = '', int $items_id = 0): void {
    $message = __('Assetprefixes: campo customizado não gravado — ', 'assetprefixes') . $reason;
    Session::addMessageAfterRedirect($message, false, WARNING);
    Toolbox::logInFile('assetprefixes', $message);
    // Nível 1 = sempre registrado, independente do "Nível de log" do GLPI.
    self::eventLog($message, 1, $itemtype, $items_id);
  }

  // Rastro de execução do fluxo inteiro, ligado sob demanda em
  // Configurar > Geral > Asset Prefixes. Serve pra descobrir, sem shell, em que
  // ponto a resolução parou num ambiente onde o plugin "não preencheu".
  private static function debugLog(string $message, string $itemtype = '', int $items_id = 0): void {
    if (!PluginAssetprefixesConfig::debugLogEnabled()) {
      return;
    }
    $message = 'Assetprefixes: ' . $message;
    Toolbox::logInFile('assetprefixes', $message);
    self::eventLog($message, 4, $itemtype, $items_id);
  }

  private static function eventLog(string $message, int $level, string $itemtype, int $items_id): void {
    if (!class_exists('Event')) {
      return;
    }
    try {
      Event::log($items_id, $itemtype ?: 'PluginAssetprefixesPrefix', $level, 'plugins', $message);
    } catch (\Throwable $e) {
      // Diagnóstico nunca pode derrubar a criação do ativo.
    }
  }

  // Integração best-effort com o plugin Fields (glpi-project/fields), única fonte de
  // "campos customizados" para os itemtypes nativos suportados por este plugin.
  // field_name customizado é gravado como "<glpi_plugin_fields_containers.id>:<coluna>".
  private static function writeCustomField($item, string $encoded_field_name, string $value): void {
    global $DB;

    $itemtype = get_class($item);
    $items_id = (int)$item->getID();

    [$containers_id, $column] = array_pad(explode(':', $encoded_field_name, 2), 2, null);
    if (!$containers_id || !$column) {
      self::warnCustomFieldFailure("valor de configuração inválido ($encoded_field_name).", $itemtype, $items_id);
      return;
    }
    if (!$DB->tableExists('glpi_plugin_fields_containers')) {
      self::warnCustomFieldFailure('plugin Fields não parece estar instalado (tabela glpi_plugin_fields_containers não existe).', $itemtype, $items_id);
      return;
    }

    try {
      $iter = $DB->request([
        'FROM'  => 'glpi_plugin_fields_containers',
        'WHERE' => ['id' => (int)$containers_id],
        'LIMIT' => 1,
      ]);
      if (!count($iter)) {
        self::warnCustomFieldFailure("bloco de campos #$containers_id não encontrado (foi removido?).", $itemtype, $items_id);
        return;
      }
      if (!class_exists('PluginFieldsContainer')) {
        self::warnCustomFieldFailure('plugin Fields não está ativo (classe PluginFieldsContainer não existe).', $itemtype, $items_id);
        return;
      }
      $container = $iter->current();

      // Confere o tipo real do campo — protege contra configurações antigas
      // (feitas antes desta checagem existir) que apontam pra um tipo incompatível.
      $field_type = null;
      if ($DB->tableExists('glpi_plugin_fields_fields')) {
        $field_iter = $DB->request([
          'FROM'  => 'glpi_plugin_fields_fields',
          'WHERE' => ['plugin_fields_containers_id' => (int)$containers_id, 'name' => $column],
          'LIMIT' => 1,
        ]);
        if (count($field_iter)) {
          $field_type = $field_iter->current()['type'];
        }
      }
      if ($field_type !== null && !in_array($field_type, self::CUSTOM_FIELD_SAFE_TYPES, true)) {
        self::warnCustomFieldFailure(sprintf(
          __('campo "%1$s" é do tipo "%2$s", incompatível (use texto/texto longo/rich text).', 'assetprefixes'),
          $column,
          $field_type
        ), $itemtype, $items_id);
        return;
      }

      $classname = PluginFieldsContainer::getClassname($itemtype, $container['name']);

      // Caminho preferencial: a classe gerada pelo Fields, que dispara o histórico
      // do bloco e o forward de entidade. Ela vive em files/_plugins/fields/inc e
      // depende do autoloader do Fields — se não estiver carregável, ou se o
      // add()/update() recusar o input, caímos no SQL direto: mesmo valor gravado,
      // só sem histórico. Melhor um valor gravado sem histórico do que um campo vazio.
      $reason = "classe gerada \"$classname\" (bloco \"{$container['name']}\") não encontrada";
      if (class_exists($classname)) {
        $obj    = new $classname();
        $exists = $obj->getFromDBByCrit(['items_id' => $items_id, 'itemtype' => $itemtype]);
        $ok     = $exists
          ? $obj->update(['id' => $obj->getID(), $column => $value])
          : $obj->add(['items_id' => $items_id, 'itemtype' => $itemtype, $column => $value]);

        if ($ok) {
          self::debugLog("campo customizado \"$column\" gravado via $classname (valor \"$value\").", $itemtype, $items_id);
          return;
        }
        $reason = sprintf('%1$s() falhou na classe "%2$s"', $exists ? 'update' : 'add', $classname);
      }

      if (self::writeCustomFieldRaw($itemtype, $items_id, $classname, (int)$containers_id, $column, $value)) {
        self::debugLog("campo customizado \"$column\" gravado por SQL direto (valor \"$value\"; motivo do fallback: $reason).", $itemtype, $items_id);
        return;
      }

      self::warnCustomFieldFailure("$reason; a escrita direta na tabela também falhou (bloco #$containers_id, coluna \"$column\").", $itemtype, $items_id);
    } catch (\Throwable $e) {
      self::warnCustomFieldFailure('exceção — ' . $e->getMessage(), $itemtype, $items_id);
    }
  }

  // Fallback do writeCustomField(): grava direto na tabela do bloco, sem passar
  // pela classe gerada. getTableForItemType() resolve o nome da tabela a partir do
  // nome da classe mesmo quando ela não está carregada — é a mesma função que o
  // próprio Fields usa pra montar suas search options.
  private static function writeCustomFieldRaw(
    string $itemtype,
    int $items_id,
    string $classname,
    int $containers_id,
    string $column,
    string $value
  ): bool {
    global $DB;

    $table = getTableForItemType($classname);
    if (!$table || !$DB->tableExists($table) || !$DB->fieldExists($table, $column)) {
      return false;
    }

    $existing = $DB->request([
      'SELECT' => 'id',
      'FROM'   => $table,
      'WHERE'  => ['items_id' => $items_id, 'itemtype' => $itemtype],
      'LIMIT'  => 1,
    ]);
    if (count($existing)) {
      return (bool)$DB->update($table, [$column => $value], ['id' => (int)$existing->current()['id']]);
    }

    $input = ['items_id' => $items_id, 'itemtype' => $itemtype, $column => $value];
    if ($DB->fieldExists($table, 'plugin_fields_containers_id')) {
      $input['plugin_fields_containers_id'] = $containers_id;
    }
    // entities_id/is_recursive só existem na tabela do bloco quando o itemtype é
    // entity-assign / recursivo (ver templates/container.class.tpl do Fields).
    $asset = new $itemtype();
    if ($asset->getFromDB($items_id)) {
      if ($DB->fieldExists($table, 'entities_id')) {
        $input['entities_id'] = (int)($asset->fields['entities_id'] ?? 0);
      }
      if ($DB->fieldExists($table, 'is_recursive')) {
        $input['is_recursive'] = (int)($asset->fields['is_recursive'] ?? 0);
      }
    }

    return (bool)$DB->insert($table, $input);
  }
}
