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
      return;
    }

    $subtype_field = PluginAssetprefixesPrefix::getSubtypeField($itemtype);
    $subtype_id    = ($subtype_field && !empty($item->input[$subtype_field]))
      ? (int)$item->input[$subtype_field]
      : null;

    $pattern = PluginAssetprefixesPrefixPattern::findApplicablePattern((int)$family['id'], $subtype_id);
    if (!$pattern) {
      return;
    }

    $applicable    = PluginAssetprefixesPrefixField::getApplicableFields((int)$family['id'], (int)$pattern['id']);
    $native_fields = [];
    foreach ($applicable as $field) {
      if ($field['field_type'] === 'native' && $field['field_name'] !== '') {
        $native_fields[] = $field['field_name'];
      }
    }

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

    // Repassado para onItemAdd() via propriedade dinâmica do item (mesmo objeto em ambos os hooks)
    $item->_assetprefixes_value      = $value;
    $item->_assetprefixes_prefix_id  = (int)$family['id'];
    $item->_assetprefixes_pattern_id = (int)$pattern['id'];
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

    foreach (PluginAssetprefixesPrefixField::getApplicableFields($prefix_id, $pattern_id) as $field) {
      if ($field['field_type'] === 'custom' && $field['field_name'] !== '') {
        register_shutdown_function(
          [self::class, 'writeCustomFieldDeferred'],
          $itemtype,
          $items_id,
          $field['field_name'],
          $value
        );
      }
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
  private static function warnCustomFieldFailure(string $reason): void {
    $message = __('Assetprefixes: campo customizado não gravado — ', 'assetprefixes') . $reason;
    Session::addMessageAfterRedirect($message, false, WARNING);
    Toolbox::logInFile('assetprefixes', $message);
  }

  // Integração best-effort com o plugin Fields (glpi-project/fields), única fonte de
  // "campos customizados" para os itemtypes nativos suportados por este plugin.
  // field_name customizado é gravado como "<glpi_plugin_fields_containers.id>:<coluna>".
  private static function writeCustomField($item, string $encoded_field_name, string $value): void {
    global $DB;

    [$containers_id, $column] = array_pad(explode(':', $encoded_field_name, 2), 2, null);
    if (!$containers_id || !$column) {
      self::warnCustomFieldFailure("valor de configuração inválido ($encoded_field_name).");
      return;
    }
    if (!$DB->tableExists('glpi_plugin_fields_containers')) {
      self::warnCustomFieldFailure('plugin Fields não parece estar instalado (tabela glpi_plugin_fields_containers não existe).');
      return;
    }

    try {
      $iter = $DB->request([
        'FROM'  => 'glpi_plugin_fields_containers',
        'WHERE' => ['id' => (int)$containers_id],
        'LIMIT' => 1,
      ]);
      if (!count($iter)) {
        self::warnCustomFieldFailure("bloco de campos #$containers_id não encontrado (foi removido?).");
        return;
      }
      if (!class_exists('PluginFieldsContainer')) {
        self::warnCustomFieldFailure('plugin Fields não está ativo (classe PluginFieldsContainer não existe).');
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
        ));
        return;
      }

      $itemtype  = get_class($item);
      $classname = PluginFieldsContainer::getClassname($itemtype, $container['name']);
      if (!class_exists($classname)) {
        self::warnCustomFieldFailure("classe gerada \"$classname\" (bloco \"{$container['name']}\") não encontrada.");
        return;
      }

      $obj    = new $classname();
      $exists = $obj->getFromDBByCrit(['items_id' => $item->getID(), 'itemtype' => $itemtype]);
      $ok     = $exists
        ? $obj->update(['id' => $obj->getID(), $column => $value])
        : $obj->add(['items_id' => $item->getID(), 'itemtype' => $itemtype, $column => $value]);

      if (!$ok) {
        self::warnCustomFieldFailure(sprintf(
          __('%1$s() falhou na classe "%2$s" (coluna "%3$s").', 'assetprefixes'),
          $exists ? 'update' : 'add',
          $classname,
          $column
        ));
      }
    } catch (\Throwable $e) {
      self::warnCustomFieldFailure('exceção — ' . $e->getMessage());
    }
  }
}
