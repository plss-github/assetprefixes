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

    // Ativos customizados (GLPI 11) dividem glpi_assets_assets entre TODAS as
    // definições — sem este filtro a checagem de unicidade enxergaria ativos de
    // outros tipos customizados.
    $where = ['OR' => $or] + PluginAssetprefixesPrefix::getSharedTableRestriction($itemtype);

    return countElementsInTable($table, $where) > 0;
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
      // GLPI 11: campo customizado nativo do ativo customizado. Asset::add()
      // chama prepareInputForAdd() -> handleCustomFieldsUpdate() DEPOIS do hook
      // pre_item_add (CommonDBTM::add — doHook na linha 1338, prepare na 1341),
      // então basta deixar o valor em input['custom_<system_name>'] que o
      // próprio core serializa na coluna JSON `custom_fields`.
      $system_name = self::getAssetFieldSystemName($encoded_field_name);
      if ($system_name !== null) {
        $item->input['custom_' . $system_name] = $value;
        continue;
      }

      // More Fields: gravado só em item_add (escrita adiada) pela API dele.
      if (self::getMorefieldsId($encoded_field_name) !== null) {
        continue;
      }

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

    [$native_fields, $custom_fields] = self::splitTargets(
      PluginAssetprefixesPrefixField::getApplicableFields($prefix_id, $pattern_id)
    );

    if (PluginAssetprefixesPrefix::isCustomAsset($itemtype) && !empty($native_fields)) {
      register_shutdown_function(
        [self::class, 'repairNativeFieldsDeferred'],
        $itemtype,
        $items_id,
        $native_fields,
        $value
      );
    }

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

  // -------------------------------------------------------------------------
  // Campos customizados nativos do GLPI 11 (ativos customizados)
  // -------------------------------------------------------------------------

  // "morefields:<id>" -> id da definição no More Fields; null para qualquer outro formato.
  private static function getMorefieldsId(string $encoded_field_name): ?int {
    $prefix = PluginAssetprefixesPrefixField::MOREFIELDS_PREFIX;
    if (strpos($encoded_field_name, $prefix) !== 0) {
      return null;
    }
    $id = (int)substr($encoded_field_name, strlen($prefix));
    return $id > 0 ? $id : null;
  }

  // Grava o valor emitido num campo do More Fields pela API pública dele.
  // Roda adiado (ver onItemAdd), depois de qualquer gravação feita pelo próprio
  // formulário do ativo.
  private static function writeMorefieldsField($item, int $field_id, string $value): void {
    $itemtype = get_class($item);
    $items_id = (int)$item->getID();

    if (!class_exists('\\GlpiPlugin\\Morefields\\Api')) {
      self::warnCustomFieldFailure('plugin More Fields não está ativo.', $itemtype, $items_id);
      return;
    }

    $error = \GlpiPlugin\Morefields\Api::setValue($itemtype, $items_id, $field_id, $value);
    if ($error !== null) {
      self::warnCustomFieldFailure('More Fields: ' . $error . '.', $itemtype, $items_id);
      return;
    }
    self::debugLog("campo #$field_id do More Fields gravado com \"$value\".", $itemtype, $items_id);
  }

  // "assetfield:<system_name>" -> "<system_name>"; null para qualquer outro
  // formato (campos do plugin Fields são "<containers_id>:<coluna>").
  private static function getAssetFieldSystemName(string $encoded_field_name): ?string {
    $prefix = PluginAssetprefixesPrefixField::ASSET_FIELD_PREFIX;
    if (strpos($encoded_field_name, $prefix) !== 0) {
      return null;
    }
    $system_name = substr($encoded_field_name, strlen($prefix));
    return $system_name !== '' ? $system_name : null;
  }

  // Escrita adiada (ver onItemAdd) de um campo customizado do GLPI 11.
  //
  // O valor mora na coluna JSON `custom_fields` de glpi_assets_assets, indexado
  // pelo ID da CustomFieldDefinition. Gravamos direto no JSON em vez de chamar
  // $item->update(['custom_<system_name>' => ...]) porque Asset::prepareInputFor*
  // roda handleReadonlyFieldUpdate(): se o campo estiver marcado como somente
  // leitura para o perfil ativo (o caso natural de um campo preenchido
  // automaticamente), o core descarta o valor do input. Quando a injeção feita
  // em onPreItemAdd() sobreviveu, o valor já está correto e nada é gravado.
  private static function writeAssetCustomField($item, string $system_name, string $value): void {
    global $DB;

    $itemtype = get_class($item);
    $items_id = (int)$item->getID();
    $encoded  = PluginAssetprefixesPrefixField::ASSET_FIELD_PREFIX . $system_name;

    $field = PluginAssetprefixesPrefixField::getCustomAssetFieldDefinition($itemtype, $encoded);
    if ($field === null) {
      self::warnCustomFieldFailure("campo customizado \"$system_name\" não existe na definição deste ativo (foi removido?).", $itemtype, $items_id);
      return;
    }
    if (!in_array($field['type'], PluginAssetprefixesPrefixField::ASSET_FIELD_SAFE_TYPES, true)) {
      self::warnCustomFieldFailure(sprintf(
        __('campo "%1$s" é do tipo "%2$s", incompatível (use texto/texto longo).', 'assetprefixes'),
        $system_name,
        $field['type']
      ), $itemtype, $items_id);
      return;
    }

    $table = getTableForItemType($itemtype);
    if (!$table || !$DB->fieldExists($table, 'custom_fields')) {
      self::warnCustomFieldFailure("tabela \"$table\" não tem a coluna custom_fields.", $itemtype, $items_id);
      return;
    }

    $iter = $DB->request(['SELECT' => 'custom_fields', 'FROM' => $table, 'WHERE' => ['id' => $items_id], 'LIMIT' => 1]);
    if (!count($iter)) {
      self::warnCustomFieldFailure("ativo #$items_id não encontrado para gravar \"$system_name\".", $itemtype, $items_id);
      return;
    }

    $custom_fields = json_decode((string)($iter->current()['custom_fields'] ?? ''), true);
    if (!is_array($custom_fields)) {
      $custom_fields = [];
    }

    // Já gravado pela injeção em onPreItemAdd() — nada a fazer.
    if (array_key_exists($field['id'], $custom_fields) && (string)$custom_fields[$field['id']] === $value) {
      self::debugLog("campo customizado \"$system_name\" já continha \"$value\" (injeção no input funcionou).", $itemtype, $items_id);
      return;
    }

    $custom_fields[$field['id']] = $value;
    if ($DB->update($table, ['custom_fields' => json_encode($custom_fields)], ['id' => $items_id])) {
      self::debugLog("campo customizado \"$system_name\" gravado no JSON custom_fields (valor \"$value\").", $itemtype, $items_id);
      return;
    }

    self::warnCustomFieldFailure("falha ao gravar \"$system_name\" na coluna custom_fields do ativo #$items_id.", $itemtype, $items_id);
  }

  // Ativos customizados permitem marcar campos do core como somente leitura por
  // perfil (AssetDefinition > campos); nesse caso Asset::prepareInputForAdd()
  // descarta o valor que injetamos no input. Esta repescagem adiada devolve o
  // valor emitido aos campos nativos que ficaram vazios/divergentes.
  public static function repairNativeFieldsDeferred(string $itemtype, int $items_id, array $native_fields, string $value): void {
    global $DB;

    $table = getTableForItemType($itemtype);
    if (!$table || empty($native_fields)) {
      return;
    }

    $iter = $DB->request(['FROM' => $table, 'WHERE' => ['id' => $items_id], 'LIMIT' => 1]);
    if (!count($iter)) {
      return;
    }
    $row = $iter->current();

    $to_write = [];
    foreach ($native_fields as $field_name) {
      if (array_key_exists($field_name, $row) && (string)$row[$field_name] !== $value) {
        $to_write[$field_name] = $value;
      }
    }
    if (empty($to_write)) {
      return;
    }

    $DB->update($table, $to_write, ['id' => $items_id]);
    self::debugLog(sprintf(
      'campos nativos [%s] regravados após a criação (o core descartou o valor injetado — campo somente leitura?).',
      implode(', ', array_keys($to_write))
    ), $itemtype, $items_id);
  }

  // Integração best-effort com o plugin Fields (glpi-project/fields), única fonte de
  // "campos customizados" para os itemtypes nativos suportados por este plugin.
  // field_name customizado é gravado como "<glpi_plugin_fields_containers.id>:<coluna>".
  private static function writeCustomField($item, string $encoded_field_name, string $value): void {
    global $DB;

    $itemtype = get_class($item);
    $items_id = (int)$item->getID();

    $system_name = self::getAssetFieldSystemName($encoded_field_name);
    if ($system_name !== null) {
      self::writeAssetCustomField($item, $system_name, $value);
      return;
    }

    $morefields_id = self::getMorefieldsId($encoded_field_name);
    if ($morefields_id !== null) {
      self::writeMorefieldsField($item, $morefields_id, $value);
      return;
    }

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
