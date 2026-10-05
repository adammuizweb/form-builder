<?php
// /plugins/form-builder/admin/global-settings.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

require_once __DIR__ . '/_ui.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) { echo '<p>Database not available.</p>'; return; }
[$uid] = adiwira_require_permission($pdo, 'plugin.form-builder.workspace.access', false);

fb_assert_schema($pdo);
$canGlobalSettings = user_can($pdo, $uid, 'plugin.form-builder.global-settings.manage');
$canDefinitions = user_can($pdo, $uid, 'plugin.form-builder.definitions.manage');
if (!$canGlobalSettings && !$canDefinitions) {
    http_response_code(403);
    echo '<div class="fba-empty">Access denied.</div>';
    return;
}

$csrf = function_exists('csrf_token') ? csrf_token() : '';
$flash = '';
$flashOk = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfInput = $_POST['csrf_token'] ?? '';
    $actionInput = $_POST['fb_action'] ?? '';
    if (!is_string($csrfInput) || !function_exists('csrf_check') || !csrf_check($csrfInput)) {
        $flash = 'Invalid CSRF token.';
        $flashOk = false;
    } elseif (!is_string($actionInput)) {
        $flash = 'Invalid settings action.';
        $flashOk = false;
    } elseif ($actionInput === 'save_recaptcha') {
        if (!$canGlobalSettings) {
            $flash = 'Access denied.';
            $flashOk = false;
        } else {
            $siteKeyInput = $_POST['sitekey'] ?? '';
            $secretInput = $_POST['secret'] ?? '';
            if (!is_string($siteKeyInput) || !is_string($secretInput) || strlen($siteKeyInput) > 255 || strlen($secretInput) > 255) {
                $flash = 'Invalid reCAPTCHA key.';
                $flashOk = false;
            } else {
                $keys = ['sitekey'=>'','secret'=>''];
                $recaptchaLock = null;
                try {
                    $recaptchaLock = fb_acquire_recaptcha_config_lock($pdo);
                    $keys = fb_recaptcha_keys($pdo, true);
                    $clearKeys = !empty($_POST['clear_keys']);
                    $siteKey = $clearKeys ? '' : trim($siteKeyInput);
                    $secret = $clearKeys ? '' : ($secretInput !== '' ? trim($secretInput) : $keys['secret']);
                    if (($siteKey === '') !== ($secret === '')) {
                        throw new InvalidArgumentException('Site key and secret key must either both be configured or both be empty.');
                    }
                    if ($siteKey !== '' && !hash_equals($keys['sitekey'], $siteKey) && $secretInput === '') {
                        throw new InvalidArgumentException('Enter the matching secret key when changing the site key.');
                    }
                    if ($clearKeys && fb_recaptcha_active_form_count($pdo) > 0) {
                        throw new InvalidArgumentException('Disable reCAPTCHA on every active form before clearing the global keys.');
                    }
                    try {
                        $pdo->beginTransaction();
                        if (!settings_set($pdo, FB_RECAPTCHA_SITEKEY_KEY, $siteKey, 1)
                            || !settings_set($pdo, FB_RECAPTCHA_SECRET_KEY, $secret, 1)) throw new RuntimeException('Unable to save reCAPTCHA settings.');
                        $pdo->commit();
                        fb_js_redirect('?page=admin/tools/form-builder/settings&saved=recaptcha');
                        return;
                    } catch (Throwable $error) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        if (isset($GLOBALS['__jy_settings_autoload_cache']) && is_array($GLOBALS['__jy_settings_autoload_cache'])) {
                            $GLOBALS['__jy_settings_autoload_cache'][FB_RECAPTCHA_SITEKEY_KEY] = $keys['sitekey'];
                            $GLOBALS['__jy_settings_autoload_cache'][FB_RECAPTCHA_SECRET_KEY] = $keys['secret'];
                        }
                        error_log('[form-builder] unable to save reCAPTCHA settings: ' . $error->getMessage());
                        $flash = 'Unable to save reCAPTCHA settings.';
                        $flashOk = false;
                    }
                } catch (InvalidArgumentException|UnexpectedValueException $error) {
                    $flash = $error->getMessage();
                    $flashOk = false;
                } finally {
                    if ($recaptchaLock !== null) fb_release_recaptcha_config_lock($pdo, $recaptchaLock);
                }
            }
        }
    } elseif ($actionInput === 'import_definition') {
        if (!$canDefinitions) {
            $flash = 'Access denied.';
            $flashOk = false;
        } else {
            $definitionInput = $_POST['definition_json'] ?? '';
            try {
                if (!is_string($definitionInput)) throw new InvalidArgumentException('Definition JSON must be text.');
                $result = fb_upsert_form_definition($pdo, $definitionInput, $uid, user_can($pdo, $uid, 'plugin.form-builder.unsafe-code.manage'));
                fb_js_redirect(fb_url(['view'=>'settings','id'=>$result['form_id'],'saved'=>1]));
                return;
            } catch (InvalidArgumentException|JsonException $error) {
                $flash = 'Import failed: ' . $error->getMessage();
                $flashOk = false;
            } catch (Throwable $error) {
                error_log('[form-builder] definition import failed: ' . $error->getMessage());
                $flash = 'Import failed due to a server error.';
                $flashOk = false;
            }
        }
    } else {
        $flash = 'Invalid settings action.';
        $flashOk = false;
    }
}

$keys = $canGlobalSettings ? fb_recaptcha_keys($pdo) : ['sitekey'=>'','secret'=>''];
$secretConfigured = $keys['secret'] !== '';
fb_admin_css();
?>
<div class="fba">
  <div class="fba-head">
    <h1>Form Builder Settings</h1>
    <div class="fba-actions">
      <a class="fba-btn" href="?page=admin/tools/form-builder">&larr; Forms</a>
    </div>
  </div>

  <?php if (($_GET['saved'] ?? '') === 'recaptcha'): ?><div class="fba-flash ok">Global reCAPTCHA settings saved.</div><?php endif; ?>
  <?php if ($flash !== ''): ?><div class="fba-flash <?= $flashOk ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash, ENT_QUOTES) ?></div><?php endif; ?>

  <?php if ($canGlobalSettings): ?>
  <div class="fba-card">
    <div class="fba-sec">Global reCAPTCHA</div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="fb_action" value="save_recaptcha">
      <div class="fba-row2">
        <div class="fba-field">
          <label>Site key</label>
          <input type="text" name="sitekey" maxlength="255" value="<?= htmlspecialchars($keys['sitekey'], ENT_QUOTES) ?>" autocomplete="off">
          <div class="fba-hint">Public key used by the Google reCAPTCHA v2 checkbox rendered on enabled forms.</div>
        </div>
        <div class="fba-field">
          <label>Secret key</label>
          <input type="password" name="secret" maxlength="255" value="" autocomplete="new-password" placeholder="<?= $secretConfigured ? 'Configured - leave blank to keep' : 'Not configured' ?>">
          <div class="fba-hint">The stored secret is never displayed. Enter a new value only to replace it. When changing the site key, enter its matching secret in the same save.</div>
        </div>
      </div>
      <?php if ($secretConfigured): ?><label class="fba-check" style="margin-bottom:1rem"><input type="checkbox" name="clear_keys" value="1"> Clear both global keys</label><div class="fba-hint" style="margin:-.7rem 0 1rem">Available only after reCAPTCHA is disabled on every active form. Draft, archived, and restored forms must pass the key check before they can become active.</div><?php endif; ?>
      <p class="fba-hint">Keys apply globally. After both keys are configured, enable reCAPTCHA separately under each form's Form settings &gt; Submission Behavior.</p>
      <button class="fba-btn primary" type="submit">Save reCAPTCHA settings</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($canDefinitions): ?>
  <div class="fba-card">
    <div class="fba-sec">Definition Import</div>
    <p class="fba-hint">Import is a global form-management action. A schema-1 definition creates a form or atomically updates the existing form with the same slug. Submissions, uploads, secrets, and access rules are not imported.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="fb_action" value="import_definition">
      <div class="fba-field">
        <label>Versioned definition JSON</label>
        <textarea name="definition_json" rows="16" maxlength="524288" required spellcheck="false"></textarea>
      </div>
      <button class="fba-btn primary" type="submit">Atomic upsert by slug</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="fba-card">
    <div class="fba-sec">Workspace Controls</div>
    <dl class="fba-doc-list">
      <div><dt>New Form</dt><dd>Creates an empty draft and opens Visual Builder. A draft does not accept public submissions until it is published or activated.</dd></div>
      <div><dt>Settings</dt><dd>Opens this global page for shared service keys, definition import, and operational documentation. Per-form behavior remains under each row's Form settings action.</dd></div>
      <div><dt>More menu (<span class="fba-mono">fba-more-menu</span>)</dt><dd>The three-dot row control contains submissions, builders, Form settings, definition export, duplication, archive/reactivation, and Bin actions according to permission and lifecycle state.</dd></div>
      <div><dt>Portal state (<span class="fba-mono">fba-portal</span>)</dt><dd>While the row menu is open, JavaScript temporarily moves it under the document body and positions it against the trigger. This prevents table overflow or transformed dashboard containers from clipping the menu. Closing it restores the menu to its original row.</dd></div>
      <div><dt>Column menu</dt><dd>Shows or hides optional table columns. The choice is stored only in the current browser and does not affect other administrators.</dd></div>
    </dl>
  </div>

  <div class="fba-card">
    <div class="fba-sec">Form Lifecycle</div>
    <dl class="fba-doc-list">
      <div><dt>Draft</dt><dd>Editable and retained, but unavailable to visitors and unable to accept submissions.</dd></div>
      <div><dt>Active</dt><dd>Publicly rendered wherever its shortcode or Theme Section is used and able to accept submissions.</dd></div>
      <div><dt>Archived</dt><dd>Hidden from the Current list and public rendering while preserving fields, submissions, drafts, and uploads. Use the Archived tab to reactivate it safely as a draft.</dd></div>
      <div><dt>Bin</dt><dd>A separate soft-deletion state. Restore recovers the form; permanent deletion removes its fields, submissions, Visual Builder draft, and private uploads.</dd></div>
    </dl>
  </div>
</div>
