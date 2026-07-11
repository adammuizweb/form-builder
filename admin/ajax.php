<?php
// /plugins/form-builder/admin/ajax.php — builder AJAX endpoint (/fb-builder/)
declare(strict_types=1);

$publicRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
require_once $publicRoot . '/app/bootstrap_core.php';
require_once __DIR__ . '/_canvas.php';

function fb_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!($pdo instanceof PDO)) fb_json(['ok' => false, 'error' => 'DB unavailable'], 500);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') fb_json(['ok' => false, 'error' => 'POST required'], 405);

$uid = function_exists('current_user_id') ? current_user_id() : 0;
if ($uid <= 0) fb_json(['ok' => false, 'error' => 'Login required'], 401);
if (function_exists('csrf_check') && !csrf_check((string)($_POST['csrf_token'] ?? ''))) fb_json(['ok' => false, 'error' => 'Invalid CSRF'], 403);

fb_ensure_schema($pdo);
$formId = (int)($_POST['form_id'] ?? 0);
$form = $formId > 0 ? fb_get_form($pdo, $formId) : null;
if ($form === null) fb_json(['ok' => false, 'error' => 'Form not found'], 404);
$role = function_exists('current_user_role') ? current_user_role($pdo) : null;
if (!fb_can_access_form($pdo, $form, $uid, $role)) fb_json(['ok' => false, 'error' => 'Access denied'], 403);

$action = (string)($_POST['fb_action'] ?? '');
$types = fb_field_types();

$touch = static function () use ($pdo, $formId): void {
    $pdo->prepare('UPDATE `fb_forms` SET updated_at = NOW() WHERE id = ?')->execute([$formId]);
};

$renumber = static function (int $parent) use ($pdo, $formId): void {
    $st = $pdo->prepare('SELECT id FROM `fb_fields` WHERE form_id = ? AND parent_id = ? ORDER BY sort_order, id');
    $st->execute([$formId, $parent]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $u = $pdo->prepare('UPDATE `fb_fields` SET sort_order = ? WHERE id = ?');
    foreach ($ids as $i => $id) $u->execute([($i + 1) * 10, (int)$id]);
};

$node = static function (int $id) use ($pdo, $formId): ?array {
    $st = $pdo->prepare('SELECT * FROM `fb_fields` WHERE id = ? AND form_id = ? LIMIT 1');
    $st->execute([$id, $formId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : null;
};

try {
    switch ($action) {
        case 'canvas':
            fb_json(['ok' => true, 'html' => fb_render_canvas($form, fb_get_tree($pdo, $formId))]);

        case 'add_row': {
            $cols = max(1, min(4, (int)($_POST['cols'] ?? 2)));
            $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM `fb_fields` WHERE form_id = {$formId} AND parent_id = 0")->fetchColumn();
            $pdo->prepare("INSERT INTO `fb_fields` (form_id, type, label, field_key, parent_id, sort_order) VALUES (?, 'row', '', ?, 0, ?)")
                ->execute([$formId, 'row_' . bin2hex(random_bytes(4)), $maxSort + 10]);
            $rowId = (int)$pdo->lastInsertId();
            for ($c = 1; $c <= $cols; $c++) {
                $pdo->prepare("INSERT INTO `fb_fields` (form_id, type, label, field_key, parent_id, sort_order) VALUES (?, 'col', '', ?, ?, ?)")
                    ->execute([$formId, 'col_' . bin2hex(random_bytes(4)), $rowId, $c * 10]);
            }
            $touch();
            fb_json(['ok' => true, 'html' => fb_render_canvas($form, fb_get_tree($pdo, $formId))]);
        }

        case 'set_cols': {
            $rowId = (int)($_POST['row_id'] ?? 0);
            $want = max(1, min(4, (int)($_POST['cols'] ?? 1)));
            $row = $node($rowId);
            if ($row === null || $row['type'] !== 'row') fb_json(['ok' => false, 'error' => 'Row not found'], 404);
            $st = $pdo->prepare("SELECT * FROM `fb_fields` WHERE form_id = ? AND parent_id = ? AND type = 'col' ORDER BY sort_order, id");
            $st->execute([$formId, $rowId]);
            $cols = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $have = count($cols);
            if ($want > $have) {
                $maxSort = $have ? (int)end($cols)['sort_order'] : 0;
                for ($c = $have + 1; $c <= $want; $c++) {
                    $pdo->prepare("INSERT INTO `fb_fields` (form_id, type, label, field_key, parent_id, sort_order) VALUES (?, 'col', '', ?, ?, ?)")
                        ->execute([$formId, 'col_' . bin2hex(random_bytes(4)), $rowId, $maxSort + ($c - $have) * 10]);
                }
            } elseif ($want < $have && $have > 0) {
                $keep = array_slice($cols, 0, $want);
                $drop = array_slice($cols, $want);
                $firstId = (int)$keep[0]['id'];
                $maxInFirst = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM `fb_fields` WHERE form_id = {$formId} AND parent_id = {$firstId}")->fetchColumn();
                foreach ($drop as $d) {
                    // move fields of dropped columns into the first kept column
                    $pdo->prepare('UPDATE `fb_fields` SET parent_id = ?, sort_order = sort_order + ? WHERE parent_id = ?')
                        ->execute([$firstId, $maxInFirst + 100, (int)$d['id']]);
                    $pdo->prepare('DELETE FROM `fb_fields` WHERE id = ?')->execute([(int)$d['id']]);
                }
                $renumber($firstId);
            }
            $touch();
            fb_json(['ok' => true, 'html' => fb_render_canvas($form, fb_get_tree($pdo, $formId))]);
        }

        case 'add_field': {
            $type = (string)($_POST['type'] ?? '');
            if (!isset($types[$type]) || !empty($types[$type]['container'])) fb_json(['ok' => false, 'error' => 'Invalid type'], 422);
            $colId = (int)($_POST['col_id'] ?? 0);
            $col = $node($colId);
            if ($col === null || $col['type'] !== 'col') fb_json(['ok' => false, 'error' => 'Column not found'], 404);
            $index = max(0, (int)($_POST['index'] ?? 999));
            $base = fb_normalize_key($types[$type]['label']);
            $key = $base; $i = 2;
            while (true) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM `fb_fields` WHERE form_id = ? AND field_key = ?');
                $st->execute([$formId, $key]);
                if ((int)$st->fetchColumn() === 0) break;
                $key = $base . '_' . $i++;
            }
            $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM `fb_fields` WHERE form_id = {$formId} AND parent_id = {$colId}")->fetchColumn();
            $pdo->prepare('INSERT INTO `fb_fields` (form_id, type, label, field_key, parent_id, sort_order) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$formId, $type, $types[$type]['label'], $key, $colId, $maxSort + 10]);
            $newId = (int)$pdo->lastInsertId();
            if ($index < 999) {
                // reposition
                $st = $pdo->prepare('SELECT id FROM `fb_fields` WHERE form_id = ? AND parent_id = ? AND id != ? ORDER BY sort_order, id');
                $st->execute([$formId, $colId, $newId]);
                $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
                array_splice($ids, min($index, count($ids)), 0, [$newId]);
                $u = $pdo->prepare('UPDATE `fb_fields` SET sort_order = ? WHERE id = ?');
                foreach ($ids as $p => $id) $u->execute([($p + 1) * 10, (int)$id]);
            }
            $touch();
            fb_json(['ok' => true, 'id' => $newId, 'html' => fb_render_canvas($form, fb_get_tree($pdo, $formId))]);
        }

        case 'move': {
            $id = (int)($_POST['id'] ?? 0);
            $parent = max(0, (int)($_POST['parent'] ?? 0));
            $index = max(0, (int)($_POST['index'] ?? 0));
            $n = $node($id);
            if ($n === null) fb_json(['ok' => false, 'error' => 'Node not found'], 404);
            if ($parent > 0) {
                $p = $node($parent);
                if ($p === null) fb_json(['ok' => false, 'error' => 'Parent not found'], 404);
                // fields go into cols; rows stay at root; cols stay in their row
                if ($n['type'] === 'row' || $p['type'] !== 'col' || $n['type'] === 'col') fb_json(['ok' => false, 'error' => 'Invalid move'], 422);
            } elseif ($n['type'] !== 'row') {
                fb_json(['ok' => false, 'error' => 'Only rows move at root'], 422);
            }
            $oldParent = (int)$n['parent_id'];
            $pdo->prepare('UPDATE `fb_fields` SET parent_id = ? WHERE id = ?')->execute([$parent, $id]);
            $st = $pdo->prepare('SELECT id FROM `fb_fields` WHERE form_id = ? AND parent_id = ? AND id != ? ORDER BY sort_order, id');
            $st->execute([$formId, $parent, $id]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            array_splice($ids, min($index, count($ids)), 0, [$id]);
            $u = $pdo->prepare('UPDATE `fb_fields` SET sort_order = ? WHERE id = ?');
            foreach ($ids as $p => $nid) $u->execute([($p + 1) * 10, (int)$nid]);
            if ($oldParent !== $parent) $renumber($oldParent);
            $touch();
            fb_json(['ok' => true, 'html' => fb_render_canvas($form, fb_get_tree($pdo, $formId))]);
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            $n = $node($id);
            if ($n === null) fb_json(['ok' => false, 'error' => 'Node not found'], 404);
            $deleteSubtree = static function (int $rootId) use ($pdo, $formId, &$deleteSubtree): void {
                $st = $pdo->prepare('SELECT id, type FROM `fb_fields` WHERE form_id = ? AND parent_id = ?');
                $st->execute([$formId, $rootId]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ch) {
                    if (in_array($ch['type'], ['row', 'col'], true)) $deleteSubtree((int)$ch['id']);
                    $pdo->prepare('DELETE FROM `fb_fields` WHERE id = ?')->execute([(int)$ch['id']]);
                }
            };
            if (in_array($n['type'], ['row', 'col'], true)) $deleteSubtree($id);
            $pdo->prepare('DELETE FROM `fb_fields` WHERE id = ? AND form_id = ?')->execute([$id, $formId]);
            $touch();
            fb_json(['ok' => true, 'html' => fb_render_canvas($form, fb_get_tree($pdo, $formId))]);
        }

        case 'field_form': {
            $id = (int)($_POST['id'] ?? 0);
            $n = $node($id);
            if ($n === null || !empty($types[$n['type']]['container'])) fb_json(['ok' => false, 'error' => 'Field not found'], 404);
            fb_json(['ok' => true, 'html' => fb_render_field_form($n)]);
        }

        case 'save_field': {
            $id = (int)($_POST['field_id'] ?? 0);
            $n = $node($id);
            if ($n === null) fb_json(['ok' => false, 'error' => 'Field not found'], 404);
            $type = (string)($_POST['type'] ?? 'text');
            if (!isset($types[$type]) || !empty($types[$type]['container'])) $type = 'text';
            $label = trim((string)($_POST['label'] ?? ''));
            $key = fb_normalize_key((string)($_POST['field_key'] ?? '') !== '' ? (string)$_POST['field_key'] : $label);
            $base = $key; $i = 2;
            while (true) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM `fb_fields` WHERE form_id = ? AND field_key = ? AND id != ?');
                $st->execute([$formId, $key, $id]);
                if ((int)$st->fetchColumn() === 0) break;
                $key = $base . '_' . $i++;
            }
            $options = [];
            if (!empty($types[$type]['options'])) {
                foreach (preg_split('/\r?\n/', (string)($_POST['options'] ?? '')) as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $parts = array_map('trim', explode('|', $line));
                    $val = fb_normalize_key($parts[0] ?? '');
                    if ($val === '') continue;
                    $options[] = ['value' => $val, 'label' => ($parts[1] ?? '') !== '' ? $parts[1] : ($parts[0] ?? $val), 'price' => (int)preg_replace('/[^\d\-]/', '', (string)($parts[2] ?? '0'))];
                }
            }
            $validation = [];
            if ($type === 'number') {
                if (trim((string)($_POST['v_min'] ?? '')) !== '') $validation['min'] = trim((string)$_POST['v_min']);
                if (trim((string)($_POST['v_max'] ?? '')) !== '') $validation['max'] = trim((string)$_POST['v_max']);
            } elseif (in_array($type, ['text', 'tel', 'textarea'], true)) {
                if ((int)($_POST['v_maxlength'] ?? 0) > 0) $validation['maxlength'] = (int)$_POST['v_maxlength'];
                if (trim((string)($_POST['v_pattern'] ?? '')) !== '') $validation['pattern'] = trim((string)$_POST['v_pattern']);
            } elseif (in_array($type, ['file', 'image'], true)) {
                $validation['max_bytes'] = max(1, (int)($_POST['v_maxmb'] ?? 5)) * 1024 * 1024;
                $exts = array_values(array_filter(array_map(static fn($e) => strtolower(trim($e, " .")), explode(',', (string)($_POST['v_exts'] ?? '')))));
                if ($exts) $validation['exts'] = $exts;
            }
            $pdo->prepare('UPDATE `fb_fields` SET type = ?, label = ?, field_key = ?, placeholder = ?, help_text = ?, required = ?, is_hidden = ?, options_json = ?, validation_json = ? WHERE id = ? AND form_id = ?')
                ->execute([$type, $label, $key, trim((string)($_POST['placeholder'] ?? '')) ?: null, trim((string)($_POST['help_text'] ?? '')) ?: null,
                    !empty($_POST['required']) ? 1 : 0, !empty($_POST['is_hidden']) ? 1 : 0,
                    $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                    $validation ? json_encode($validation, JSON_UNESCAPED_UNICODE) : null, $id, $formId]);
            $touch();
            fb_json(['ok' => true, 'html' => fb_render_canvas($form, fb_get_tree($pdo, $formId))]);
        }

        default:
            fb_json(['ok' => false, 'error' => 'Unknown action'], 422);
    }
} catch (Throwable $e) {
    fb_json(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}
