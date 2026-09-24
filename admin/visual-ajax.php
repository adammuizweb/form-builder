<?php
// /plugins/form-builder/admin/visual-ajax.php - Visual Builder draft endpoint.
declare(strict_types=1);

$publicRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
require_once $publicRoot . '/app/bootstrap_core.php';

function fb_visual_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!($pdo instanceof PDO)) fb_visual_json(['ok' => false, 'error' => 'Database unavailable'], 500);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') fb_visual_json(['ok' => false, 'error' => 'POST required'], 405);
$uid = function_exists('current_user_id') ? current_user_id() : 0;
if ($uid <= 0) fb_visual_json(['ok' => false, 'error' => 'Login required'], 401);
if (!function_exists('user_can') || !user_can($pdo, $uid, 'plugin.form-builder.workspace.access')) fb_visual_json(['ok' => false, 'error' => 'Access denied'], 403);
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) fb_visual_json(['ok' => false, 'error' => 'Invalid CSRF'], 403);

try {
    fb_assert_schema($pdo);
    $formId = (int)($_POST['form_id'] ?? 0);
    $form = $formId > 0 ? fb_get_form($pdo, $formId) : null;
    if ($form === null || ($form['deleted_at'] ?? null) !== null) fb_visual_json(['ok' => false, 'error' => 'Form not found'], 404);
    if (!fb_can_access_form($pdo, $form, $uid)) fb_visual_json(['ok' => false, 'error' => 'Access denied'], 403);
    $allowUnsafeCode = user_can($pdo, $uid, 'plugin.form-builder.unsafe-code.manage');
    $action = (string)($_POST['fb_action'] ?? '');
    if ($action === 'load') {
        fb_visual_json(['ok' => true, 'draft' => fb_visual_load_draft($pdo, $formId, $uid, $allowUnsafeCode)]);
    }
    if ($action === 'save') {
        $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($revision === false) fb_visual_json(['ok' => false, 'error' => 'Invalid draft revision'], 422);
        $definition = $_POST['definition'] ?? null;
        if (!is_string($definition)) fb_visual_json(['ok' => false, 'error' => 'Invalid form definition'], 422);
        fb_visual_json(['ok' => true, 'draft' => fb_visual_save_draft($pdo, $formId, $definition, $revision, $uid, $allowUnsafeCode)]);
    }
    fb_visual_json(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (UnexpectedValueException $error) {
    fb_visual_json(['ok' => false, 'error' => $error->getMessage(), 'conflict' => true], 409);
} catch (LogicException|JsonException $error) {
    fb_visual_json(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('Form Builder visual draft error: ' . $error->getMessage());
    fb_visual_json(['ok' => false, 'error' => 'Unable to update the Visual Builder draft'], 500);
}
