<?php
declare(strict_types=1);

function fb_visual_definition_json_value(array $definition): array {
    if (isset($definition['form']['settings']['translations']) && is_array($definition['form']['settings']['translations'])) {
        foreach ($definition['form']['settings']['translations'] as &$translation) {
            if (is_array($translation) && is_array($translation['fields'] ?? null)) $translation['fields'] = (object)$translation['fields'];
        }
        unset($translation);
    }
    return $definition;
}

function fb_visual_definition_json(array $definition): string {
    return fb_json_encode(fb_visual_definition_json_value($definition));
}

function fb_visual_draft_transport(array $draft): array {
    if (is_array($draft['definition'] ?? null)) $draft['definition'] = fb_visual_definition_json_value($draft['definition']);
    return $draft;
}

function fb_visual_definition_hash(array $definition): string {
    if (isset($definition['form']['fields']) && is_array($definition['form']['fields'])) {
        foreach ($definition['form']['fields'] as &$field) if (is_array($field)) unset($field['identity']);
        unset($field);
    }
    return hash('sha256', fb_json_encode($definition));
}

function fb_visual_canonical_definition(PDO $pdo, int $formId): array {
    $form = fb_get_form($pdo, $formId);
    if ($form === null) throw new InvalidArgumentException('Form not found.');
    $definition = fb_definition_decode(fb_export_form_definition($pdo, $formId, true));
    $identities = [];
    foreach (fb_get_fields($pdo, $formId) as $field) $identities['key:' . (string)$field['field_key']] = 'field:' . (int)$field['id'];
    foreach ($definition['form']['fields'] as &$field) $field['identity'] = $identities['key:' . (string)$field['key']];
    unset($field);
    $definition['form']['settings']['unsafe_code_enabled'] = (fb_form_settings($form)['unsafe_code_enabled'] ?? false) === true;
    return fb_definition_decode($definition);
}

function fb_visual_attach_field_identities(PDO $pdo, int $formId, array $definition): array {
    $identities = [];
    foreach (fb_get_fields($pdo, $formId) as $field) $identities['key:' . (string)$field['field_key']] = 'field:' . (int)$field['id'];
    $claimed = [];
    foreach ($definition['form']['fields'] as $field) if (isset($field['identity'])) $claimed[(string)$field['identity']] = true;
    foreach ($definition['form']['fields'] as &$field) {
        $identity = $identities['key:' . (string)$field['key']] ?? null;
        if (!isset($field['identity']) && $identity !== null && !isset($claimed[$identity])) {
            $field['identity'] = $identity;
            $claimed[$identity] = true;
        }
    }
    unset($field);
    return fb_definition_decode($definition);
}

function fb_visual_assert_field_identity_transition(PDO $pdo, int $formId, array $currentFields, array $nextFields): void {
    $currentByIdentity = [];
    foreach ($currentFields as $field) if (is_array($field) && isset($field['identity'])) $currentByIdentity[(string)$field['identity']] = $field;
    $seen = []; $renamedKeys = [];
    foreach ($nextFields as $field) {
        if (!is_array($field) || !isset($field['identity'])) continue;
        $identity = (string)$field['identity'];
        if (isset($seen[$identity]) || !isset($currentByIdentity[$identity])) throw new InvalidArgumentException('Invalid or duplicate field identity.');
        $seen[$identity] = true;
        if ((string)$currentByIdentity[$identity]['key'] !== (string)$field['key']) {
            $renamedKeys[] = (string)$currentByIdentity[$identity]['key'];
            $renamedKeys[] = (string)$field['key'];
        }
    }
    fb_assert_submission_field_key_transition($pdo, $formId, $currentFields, $nextFields, false);
    if ($renamedKeys !== []) {
        fb_assert_submission_history_field_json($pdo, $formId);
        if (fb_submission_history_uses_field_keys($pdo, $formId, $renamedKeys)) throw new InvalidArgumentException('One or more field keys are locked by submission history.');
    }
}

function fb_visual_definition_has_unsafe_code(array $definition): bool {
    $form = $definition['form'] ?? [];
    if (!is_array($form)) return false;
    if (($form['css'] ?? '') !== '' || ($form['js'] ?? '') !== '') return true;
    foreach (($form['fields'] ?? []) as $field) {
        if (is_array($field) && in_array($field['type'] ?? null, ['richtext', 'raw_html'], true) && ($field['settings']['html'] ?? '') !== '') return true;
    }
    return false;
}

function fb_visual_protected_code_hash(array $definition): string {
    $form = $definition['form'] ?? [];
    $protectedFields = [];
    foreach (($form['fields'] ?? []) as $field) {
        if (!is_array($field) || !in_array($field['type'] ?? null, ['richtext', 'raw_html'], true)) continue;
        $protectedFields[] = ['key'=>(string)($field['key'] ?? ''),'type'=>(string)$field['type'],'html'=>(string)($field['settings']['html'] ?? '')];
    }
    usort($protectedFields, static fn(array $a, array $b): int => [$a['key'],$a['type']] <=> [$b['key'],$b['type']]);
    return hash('sha256', fb_json_encode([
        'enabled' => ($form['settings']['unsafe_code_enabled'] ?? false) === true,
        'css' => (string)($form['css'] ?? ''),
        'js' => (string)($form['js'] ?? ''),
        'fields' => $protectedFields,
    ]));
}

function fb_visual_safe_definition(array $definition): array {
    $definition['form']['css'] = '';
    $definition['form']['js'] = '';
    $definition['form']['settings']['unsafe_code_enabled'] = false;
    foreach ($definition['form']['fields'] as &$field) {
        if (in_array($field['type'] ?? null, ['richtext', 'raw_html'], true)) unset($field['settings']['html']);
    }
    unset($field);
    return $definition;
}

function fb_visual_merge_protected_code(array $definition, array $stored): array {
    $definition['form']['css'] = (string)($stored['form']['css'] ?? '');
    $definition['form']['js'] = (string)($stored['form']['js'] ?? '');
    $definition['form']['settings']['unsafe_code_enabled'] = (bool)($stored['form']['settings']['unsafe_code_enabled'] ?? false);
    $storedFields = [];
    foreach (($stored['form']['fields'] ?? []) as $field) if (is_array($field) && isset($field['key'])) $storedFields[(string)$field['key']] = $field;
    $incomingFields = [];
    foreach ($definition['form']['fields'] as $field) if (is_array($field) && isset($field['key'])) $incomingFields[(string)$field['key']] = $field;
    foreach ($storedFields as $key => $field) {
        if (!in_array($field['type'] ?? null, ['richtext', 'raw_html'], true)) continue;
        if (!isset($incomingFields[$key]) || ($incomingFields[$key]['type'] ?? null) !== ($field['type'] ?? null)) throw new InvalidArgumentException('Unsafe-code permission required to remove or change this field.');
    }
    foreach ($definition['form']['fields'] as &$field) {
        if (!in_array($field['type'] ?? null, ['richtext', 'raw_html'], true)) continue;
        $prior = $storedFields[(string)($field['key'] ?? '')] ?? null;
        if (!is_array($prior) || ($prior['type'] ?? null) !== ($field['type'] ?? null)) throw new InvalidArgumentException('Unsafe-code permission required for this field type.');
        $html = $prior['settings']['html'] ?? null;
        if (is_string($html) && $html !== '') $field['settings']['html'] = $html;
        else unset($field['settings']['html']);
    }
    unset($field);
    return $definition;
}

function fb_visual_definition_field_row(array $field): array {
    $settings = $field['settings'] ?? [];
    if (in_array($field['type'] ?? null, ['richtext', 'raw_html'], true)) unset($settings['html']);
    return [
        'type' => (string)$field['type'],
        'label' => (string)($field['label'] ?? ''),
        'field_key' => (string)$field['key'],
        'placeholder' => (string)($field['placeholder'] ?? ''),
        'help_text' => (string)($field['help'] ?? ''),
        'required' => !empty($field['required']) ? 1 : 0,
        'width' => (int)($field['width'] ?? 12),
        'sort_order' => (int)($field['order'] ?? 0),
        'is_hidden' => !empty($field['hidden']) ? 1 : 0,
        'options_json' => ($field['options'] ?? []) !== [] ? fb_json_encode($field['options']) : null,
        'validation_json' => ($field['validation'] ?? []) !== [] ? fb_json_encode($field['validation']) : null,
        'settings_json' => $settings !== [] ? fb_json_encode($settings) : null,
    ];
}

function fb_visual_render_preview(array $definition): string {
    $definition = fb_definition_decode($definition);
    $form = $definition['form'];
    $settings = array_merge(fb_default_settings(), fb_definition_safe_settings($form['settings'] ?? []));
    [$localizedForm, $settings] = fb_localized_form(['title'=>$form['title'],'description'=>$form['description']], $settings);
    $byParent = [];
    foreach ($form['fields'] as $field) $byParent[$field['parent'] ?? ''][] = $field;
    $sort = static function (array &$fields): void {
        usort($fields, static fn(array $a, array $b): int => [$a['order'] ?? 0, $a['key']] <=> [$b['order'] ?? 0, $b['key']]);
    };
    foreach ($byParent as &$children) $sort($children);
    unset($children);
    $slug = (string)$form['slug'];
    $instance = 'draft';
    ob_start(); ?>
<div class="fb-wrap" data-fbv-draft-preview<?= ($accentStyle = fb_accent_style($settings)) !== '' ? ' style="' . fb_h($accentStyle) . '"' : '' ?>>
  <div class="fb-title"><?= fb_h((string)$localizedForm['title']) ?></div>
  <?php if ((string)$localizedForm['description'] !== ''): ?><div class="fb-desc"><?= nl2br(fb_h((string)$localizedForm['description'])) ?></div><?php endif; ?>
  <form novalidate aria-label="Draft form preview">
    <div class="fb-rows">
    <?php foreach ($byParent[''] ?? [] as $rowIndex => $row):
        if (($row['type'] ?? null) !== 'row') continue;
        $columns = array_values(array_filter($byParent[$row['key']] ?? [], static fn(array $field): bool => ($field['type'] ?? null) === 'col'));
        $span = intdiv(12, min(4, max(1, count($columns))));
        $colClass = ['12'=>'','6'=>' c6','4'=>' c4','3'=>' c3'][(string)$span] ?? ''; ?>
      <div class="fb-row" data-fbv-row="<?= fb_h((string)$row['key']) ?>">
        <button class="fbv-draft-row-marker" type="button" data-fbv-structure-action="row" aria-label="Open layout controls for Row <?= $rowIndex + 1 ?>">Row <?= $rowIndex + 1 ?><small><?= count($columns) ?> column<?= count($columns) === 1 ? '' : 's' ?></small></button>
        <?php if ($columns === []): ?><div class="fbv-draft-empty-row" aria-hidden="true">Add columns from the Layout panel</div><?php endif; ?>
        <?php foreach ($columns as $columnIndex => $column):
          $columnFields = array_values(array_filter($byParent[$column['key']] ?? [], static fn(array $field): bool => !in_array($field['type'] ?? null, ['row', 'col'], true))); ?>
        <div class="fb-col<?= $colClass ?>" data-fbv-col="<?= fb_h((string)$column['key']) ?>" data-fbv-empty="<?= $columnFields === [] ? '1' : '0' ?>">
          <button class="fbv-draft-column-marker" type="button" data-fbv-structure-action="column" aria-label="Open layout controls for Row <?= $rowIndex + 1 ?>, Column <?= $columnIndex + 1 ?>">Column <?= $columnIndex + 1 ?></button>
          <?php if ($columnFields === []): ?><button class="fbv-draft-empty-column" type="button" data-fbv-structure-action="empty-column" aria-label="Add a field to Row <?= $rowIndex + 1 ?>, Column <?= $columnIndex + 1 ?>">Empty column</button><?php endif; ?>
          <?php foreach ($columnFields as $field) {
              $rowData = fb_localized_field(fb_visual_definition_field_row($field), $settings);
              echo fb_render_field_html($rowData, $slug, $instance, false, $settings);
          } ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <button type="button" class="fb-submit" tabindex="-1"><?= fb_h((string)$settings['submit_label']) ?></button>
  </form>
</div>
    <?php
    return (string)ob_get_clean();
}

function fb_visual_draft_response(array $row, array $currentDefinition, bool $allowUnsafeCode): array {
    $definition = fb_definition_decode((string)$row['definition_json']);
    $protected = !$allowUnsafeCode && fb_visual_definition_has_unsafe_code($definition);
    $currentHash = fb_visual_definition_hash($currentDefinition);
    return [
        'definition' => $protected ? fb_visual_safe_definition($definition) : $definition,
        'preview_html' => fb_visual_render_preview($definition),
        'revision' => (int)$row['revision'],
        'published_sha256' => (string)$row['published_sha256'],
        'published_changed' => !hash_equals((string)$row['published_sha256'], $currentHash),
        'has_unpublished_changes' => !hash_equals(fb_visual_definition_hash($definition), $currentHash),
        'unsafe_content_protected' => $protected,
        'updated_at' => (string)$row['updated_at'],
    ];
}

function fb_visual_load_draft(PDO $pdo, int $formId, ?int $actorId, bool $allowUnsafeCode): array {
    $mutationLock = fb_acquire_form_mutation_lock($pdo, $formId);
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM fb_forms WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $lock->execute([$formId]);
        if ($lock->fetchColumn() === false) throw new InvalidArgumentException('Form not found.');
        $current = fb_visual_canonical_definition($pdo, $formId);
        $select = $pdo->prepare('SELECT * FROM fb_builder_drafts WHERE form_id = ? FOR UPDATE');
        $select->execute([$formId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $json = fb_visual_definition_json($current);
            $hash = fb_visual_definition_hash($current);
            $pdo->prepare('INSERT INTO fb_builder_drafts (form_id,definition_json,revision,published_sha256,created_by,updated_by) VALUES (?,?,1,?,?,?)')
                ->execute([$formId, $json, $hash, $actorId, $actorId]);
            $select->execute([$formId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($row)) throw new RuntimeException('Unable to initialize Visual Builder draft.');
        $draftDefinition = fb_visual_attach_field_identities($pdo, $formId, fb_definition_decode((string)$row['definition_json']));
        $row['definition_json'] = fb_visual_definition_json($draftDefinition);
        $response = fb_visual_draft_response($row, $current, $allowUnsafeCode);
        $pdo->commit();
        return $response;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        fb_release_form_mutation_lock($pdo, $mutationLock);
    }
}

function fb_visual_save_draft(PDO $pdo, int $formId, array|string $input, int $expectedRevision, ?int $actorId, bool $allowUnsafeCode): array {
    if ($expectedRevision < 1) throw new InvalidArgumentException('Invalid draft revision.');
    $definition = fb_definition_decode($input);
    $mutationLock = fb_acquire_form_mutation_lock($pdo, $formId);
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM fb_forms WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $lock->execute([$formId]);
        if ($lock->fetchColumn() === false) throw new InvalidArgumentException('Form not found.');
        $select = $pdo->prepare('SELECT * FROM fb_builder_drafts WHERE form_id = ? FOR UPDATE');
        $select->execute([$formId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new LogicException('Draft must be loaded before it can be saved.');
        if ((int)$row['revision'] !== $expectedRevision) throw new UnexpectedValueException('Draft changed in another session.');
        $stored = fb_visual_attach_field_identities($pdo, $formId, fb_definition_decode((string)$row['definition_json']));
        if (!$allowUnsafeCode) $definition = fb_visual_merge_protected_code($definition, $stored);
        $definition = fb_definition_decode($definition);
        fb_visual_assert_field_identity_transition($pdo, $formId, $stored['form']['fields'], $definition['form']['fields']);
        $nextRevision = $expectedRevision + 1;
        $update = $pdo->prepare('UPDATE fb_builder_drafts SET definition_json = ?, revision = ?, updated_by = ?, updated_at = NOW() WHERE form_id = ? AND revision = ?');
        $update->execute([fb_visual_definition_json($definition), $nextRevision, $actorId, $formId, $expectedRevision]);
        if ($update->rowCount() !== 1) throw new UnexpectedValueException('Draft changed in another session.');
        $select->execute([$formId]);
        $saved = $select->fetch(PDO::FETCH_ASSOC);
        $current = fb_visual_canonical_definition($pdo, $formId);
        if (!is_array($saved)) throw new RuntimeException('Unable to reload Visual Builder draft.');
        $response = fb_visual_draft_response($saved, $current, $allowUnsafeCode);
        $pdo->commit();
        return $response;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        fb_release_form_mutation_lock($pdo, $mutationLock);
    }
}

function fb_visual_replace_canonical(PDO $pdo, int $formId, array $definition): void {
    $definition = fb_definition_decode($definition);
    $form = $definition['form'];
    $form['status'] = 'active';
    $conflict = $pdo->prepare('SELECT id FROM fb_forms WHERE slug = ? AND id <> ? LIMIT 1 FOR UPDATE');
    $conflict->execute([$form['slug'], $formId]);
    if ($conflict->fetchColumn() !== false) throw new InvalidArgumentException('The draft slug is already in use.');
    $currentFields = fb_get_fields($pdo, $formId);
    $currentDefinitionFields = fb_visual_canonical_definition($pdo, $formId)['form']['fields'];
    fb_visual_assert_field_identity_transition($pdo, $formId, $currentDefinitionFields, $form['fields']);
    fb_assert_capacity_configuration($pdo, $formId, fb_flat_fields($currentFields), $form['fields']);
    $settings = array_merge(fb_default_settings(), fb_definition_safe_settings($form['settings'] ?? []));
    $pdo->prepare('UPDATE fb_forms SET slug=?,title=?,description=?,status=?,settings_json=?,css=?,js=?,updated_at=NOW() WHERE id=?')
        ->execute([$form['slug'],trim($form['title']),trim($form['description']) ?: null,'active',fb_json_encode($settings),$form['css'] !== '' ? $form['css'] : null,$form['js'] !== '' ? $form['js'] : null,$formId]);
    $currentByKey = [];
    $currentByIdentity = [];
    foreach ($currentFields as $currentField) {
        $currentByKey['key:' . (string)$currentField['field_key']] = $currentField;
        $currentByIdentity['field:' . (int)$currentField['id']] = $currentField;
    }
    $identityOwnedIds = [];
    foreach ($form['fields'] as $field) if (isset($field['identity'], $currentByIdentity[(string)$field['identity']])) $identityOwnedIds[(int)$currentByIdentity[(string)$field['identity']]['id']] = true;
    $ids = []; $pending = $form['fields'];
    $insert = $pdo->prepare('INSERT INTO fb_fields (form_id,parent_id,type,label,field_key,placeholder,help_text,required,width,sort_order,is_hidden,options_json,validation_json,settings_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $update = $pdo->prepare('UPDATE fb_fields SET parent_id=?,type=?,label=?,field_key=?,placeholder=?,help_text=?,required=?,width=?,sort_order=?,is_hidden=?,options_json=?,validation_json=?,settings_json=? WHERE id=? AND form_id=? AND deleted_at IS NULL');
    $keptIds = []; $renames = [];
    while ($pending !== []) {
        $progress = false;
        foreach ($pending as $index => $field) {
            $parent = $field['parent'] ?? null;
            if ($parent !== null && !isset($ids[$parent])) continue;
            $values = [$parent === null ? 0 : $ids[$parent],$field['type'],$field['label'],$field['key'],($field['placeholder'] ?? '') ?: null,($field['help'] ?? '') ?: null,!empty($field['required']) ? 1 : 0,$field['width'] ?? 12,$field['order'] ?? 0,!empty($field['hidden']) ? 1 : 0,($field['options'] ?? []) !== [] ? fb_json_encode($field['options']) : null,($field['validation'] ?? []) !== [] ? fb_json_encode($field['validation']) : null,($field['settings'] ?? []) !== [] ? fb_json_encode($field['settings']) : null];
            $currentField = isset($field['identity']) ? ($currentByIdentity[(string)$field['identity']] ?? null) : ($currentByKey['key:' . (string)$field['key']] ?? null);
            if (!isset($field['identity']) && is_array($currentField) && isset($identityOwnedIds[(int)$currentField['id']])) $currentField = null;
            if (is_array($currentField)) {
                $fieldId = (int)$currentField['id'];
                $update->execute(array_merge($values, [$fieldId, $formId]));
                if ($update->rowCount() > 1) throw new UnexpectedValueException('Field changed before it could be published.');
                if ((string)$currentField['field_key'] !== (string)$field['key']) $renames[] = [(string)$currentField['field_key'], (string)$field['key']];
            } else {
                $insert->execute([$formId,$values[0],$values[1],$values[2],$values[3],$values[4],$values[5],$values[6],$values[7],$values[8],$values[9],$values[10],$values[11],$values[12]]);
                $fieldId = (int)$pdo->lastInsertId();
            }
            $ids[$field['key']] = $fieldId;
            $keptIds[$fieldId] = true;
            unset($pending[$index]);
            $progress = true;
        }
        if (!$progress) throw new InvalidArgumentException('Cyclic field layout.');
    }
    if ($renames !== []) fb_cascade_trashed_field_key_reference_map($pdo, $formId, $renames);
    $trash = $pdo->prepare('UPDATE fb_fields SET deleted_at=NOW() WHERE id=? AND form_id=? AND deleted_at IS NULL');
    foreach ($currentFields as $currentField) if (!isset($keptIds[(int)$currentField['id']])) $trash->execute([(int)$currentField['id'], $formId]);
}

function fb_visual_publish_draft(PDO $pdo, int $formId, int $expectedRevision, ?int $actorId, bool $allowUnsafeCode): array {
    if ($expectedRevision < 1) throw new InvalidArgumentException('Invalid draft revision.');
    $mutationLock = fb_acquire_form_mutation_lock($pdo, $formId);
    $recaptchaLock = null;
    try {
        $pdo->beginTransaction();
        $formLock = $pdo->prepare('SELECT status FROM fb_forms WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $formLock->execute([$formId]);
        $formStatus = $formLock->fetchColumn();
        if ($formStatus === false) throw new InvalidArgumentException('Form not found.');
        if ($formStatus === 'archived') throw new UnexpectedValueException('Reactivate this form as a draft before publishing it.');
        $select = $pdo->prepare('SELECT * FROM fb_builder_drafts WHERE form_id = ? FOR UPDATE');
        $select->execute([$formId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new LogicException('Draft must be loaded before it can be published.');
        if ((int)$row['revision'] !== $expectedRevision) throw new UnexpectedValueException('Draft changed in another session.');
        $current = fb_visual_canonical_definition($pdo, $formId);
        if (!hash_equals((string)$row['published_sha256'], fb_visual_definition_hash($current))) throw new UnexpectedValueException('Classic Builder changed this form. Reload its version before publishing.');
        $definition = fb_definition_decode((string)$row['definition_json']);
        if (!$allowUnsafeCode && !hash_equals(fb_visual_protected_code_hash($current), fb_visual_protected_code_hash($definition))) throw new DomainException('Unsafe-code permission is required to publish protected content changes.');
        $definition['form']['status'] = 'active';
        $draftSettings = array_merge(fb_default_settings(), fb_definition_safe_settings($definition['form']['settings'] ?? []));
        if ($draftSettings['recaptcha'] === '1') {
            $recaptchaLock = fb_acquire_recaptcha_config_lock($pdo);
            if (!fb_recaptcha_configured($pdo, true)) throw new UnexpectedValueException('Configure both global reCAPTCHA keys before publishing this form.');
        }
        fb_visual_replace_canonical($pdo, $formId, $definition);
        $canonical = fb_visual_canonical_definition($pdo, $formId);
        $hash = fb_visual_definition_hash($canonical);
        $nextRevision = $expectedRevision + 1;
        $pdo->prepare('UPDATE fb_builder_drafts SET definition_json=?,revision=?,published_sha256=?,updated_by=?,updated_at=NOW() WHERE form_id=? AND revision=?')
            ->execute([fb_visual_definition_json($canonical),$nextRevision,$hash,$actorId,$formId,$expectedRevision]);
        $select->execute([$formId]);
        $published = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($published)) throw new RuntimeException('Unable to reload the published draft.');
        $response = fb_visual_draft_response($published, $canonical, $allowUnsafeCode);
        $pdo->commit();
        return $response;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        if ($recaptchaLock !== null) fb_release_recaptcha_config_lock($pdo, $recaptchaLock);
        fb_release_form_mutation_lock($pdo, $mutationLock);
    }
}

function fb_visual_reset_draft(PDO $pdo, int $formId, int $expectedRevision, ?int $actorId, bool $allowUnsafeCode): array {
    if ($expectedRevision < 1) throw new InvalidArgumentException('Invalid draft revision.');
    $mutationLock = fb_acquire_form_mutation_lock($pdo, $formId);
    try {
        $pdo->beginTransaction();
        $formLock = $pdo->prepare('SELECT id FROM fb_forms WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $formLock->execute([$formId]);
        if ($formLock->fetchColumn() === false) throw new InvalidArgumentException('Form not found.');
        $select = $pdo->prepare('SELECT * FROM fb_builder_drafts WHERE form_id = ? FOR UPDATE');
        $select->execute([$formId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)$row['revision'] !== $expectedRevision) throw new UnexpectedValueException('Draft changed in another session.');
        $current = fb_visual_canonical_definition($pdo, $formId);
        $hash = fb_visual_definition_hash($current);
        $nextRevision = $expectedRevision + 1;
        $pdo->prepare('UPDATE fb_builder_drafts SET definition_json=?,revision=?,published_sha256=?,updated_by=?,updated_at=NOW() WHERE form_id=? AND revision=?')
            ->execute([fb_visual_definition_json($current),$nextRevision,$hash,$actorId,$formId,$expectedRevision]);
        $select->execute([$formId]);
        $reset = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($reset)) throw new RuntimeException('Unable to reload the reset draft.');
        $response = fb_visual_draft_response($reset, $current, $allowUnsafeCode);
        $pdo->commit();
        return $response;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        fb_release_form_mutation_lock($pdo, $mutationLock);
    }
}
