<?php
declare(strict_types=1);

function fb_visual_definition_hash(array $definition): string {
    return hash('sha256', fb_json_encode($definition));
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

function fb_visual_draft_response(array $row, array $currentDefinition, bool $allowUnsafeCode): array {
    $definition = fb_definition_decode((string)$row['definition_json']);
    $protected = !$allowUnsafeCode && fb_visual_definition_has_unsafe_code($definition);
    return [
        'definition' => $protected ? fb_visual_safe_definition($definition) : $definition,
        'revision' => (int)$row['revision'],
        'published_sha256' => (string)$row['published_sha256'],
        'published_changed' => !hash_equals((string)$row['published_sha256'], fb_visual_definition_hash($currentDefinition)),
        'unsafe_content_protected' => $protected,
        'updated_at' => (string)$row['updated_at'],
    ];
}

function fb_visual_load_draft(PDO $pdo, int $formId, ?int $actorId, bool $allowUnsafeCode): array {
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM fb_forms WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $lock->execute([$formId]);
        if ($lock->fetchColumn() === false) throw new InvalidArgumentException('Form not found.');
        $current = fb_definition_decode(fb_export_form_definition($pdo, $formId, true));
        $select = $pdo->prepare('SELECT * FROM fb_builder_drafts WHERE form_id = ? FOR UPDATE');
        $select->execute([$formId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $json = fb_json_encode($current);
            $hash = fb_visual_definition_hash($current);
            $pdo->prepare('INSERT INTO fb_builder_drafts (form_id,definition_json,revision,published_sha256,created_by,updated_by) VALUES (?,?,1,?,?,?)')
                ->execute([$formId, $json, $hash, $actorId, $actorId]);
            $select->execute([$formId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($row)) throw new RuntimeException('Unable to initialize Visual Builder draft.');
        $response = fb_visual_draft_response($row, $current, $allowUnsafeCode);
        $pdo->commit();
        return $response;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function fb_visual_save_draft(PDO $pdo, int $formId, array|string $input, int $expectedRevision, ?int $actorId, bool $allowUnsafeCode): array {
    if ($expectedRevision < 1) throw new InvalidArgumentException('Invalid draft revision.');
    $definition = fb_definition_decode($input);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM fb_forms WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $lock->execute([$formId]);
        if ($lock->fetchColumn() === false) throw new InvalidArgumentException('Form not found.');
        $select = $pdo->prepare('SELECT * FROM fb_builder_drafts WHERE form_id = ? FOR UPDATE');
        $select->execute([$formId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new LogicException('Draft must be loaded before it can be saved.');
        if ((int)$row['revision'] !== $expectedRevision) throw new UnexpectedValueException('Draft changed in another session.');
        $stored = fb_definition_decode((string)$row['definition_json']);
        if (!$allowUnsafeCode) $definition = fb_visual_merge_protected_code($definition, $stored);
        $definition = fb_definition_decode($definition);
        $nextRevision = $expectedRevision + 1;
        $update = $pdo->prepare('UPDATE fb_builder_drafts SET definition_json = ?, revision = ?, updated_by = ?, updated_at = NOW() WHERE form_id = ? AND revision = ?');
        $update->execute([fb_json_encode($definition), $nextRevision, $actorId, $formId, $expectedRevision]);
        if ($update->rowCount() !== 1) throw new UnexpectedValueException('Draft changed in another session.');
        $select->execute([$formId]);
        $saved = $select->fetch(PDO::FETCH_ASSOC);
        $current = fb_definition_decode(fb_export_form_definition($pdo, $formId, true));
        if (!is_array($saved)) throw new RuntimeException('Unable to reload Visual Builder draft.');
        $response = fb_visual_draft_response($saved, $current, $allowUnsafeCode);
        $pdo->commit();
        return $response;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
