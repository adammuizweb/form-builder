<?php
// /plugins/form-builder/public/render.php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

function fb_h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Render a single (non-container) field. Returns '' for hidden/unknown types.
function fb_render_field_html(array $f, string $slug, string $instance, bool $unsafeCode, array $publicSettings, array $capacityUsage = []): string {
    $types = fb_field_types();
    $type = (string)$f['type'];
    $meta = $types[$type] ?? null;
    if ($meta === null || !empty($meta['container']) || !empty($f['is_hidden'])) return '';
    $key = (string)$f['field_key'];
    $label = (string)$f['label'];
    $req = !empty($f['required']);
    $valid = fb_field_validation($f);
    $uploadPolicy = !empty($meta['file']) ? fb_upload_policy($f) : null;
    $maxBytes = (int)($uploadPolicy['max_bytes'] ?? 5 * 1024 * 1024);
    $id = 'fb-' . $slug . '-' . $instance . '-' . $key;

    $fsA = fb_field_settings($f);
    $wrapCls = '';
    if (($fsA['align'] ?? '') === 'center') $wrapCls .= ' fb-al-c';
    elseif (($fsA['align'] ?? '') === 'right') $wrapCls .= ' fb-al-r';
    if (($fsA['valign'] ?? '') === 'middle') $wrapCls .= ' fb-v-m';
    elseif (($fsA['valign'] ?? '') === 'bottom') $wrapCls .= ' fb-v-b';

    ob_start(); ?>
    <div class="fb-field<?= $wrapCls ?>" data-key="<?= fb_h($key) ?>">
      <?php if (!empty($meta['display'])): ?>
        <?php if ($type === 'heading'):
          $fs = fb_field_settings($f);
          $lvl = in_array(($fs['level'] ?? ''), FB_HEADING_LEVELS, true) ? $fs['level'] : 'h2'; ?>
        <<?= $lvl ?> class="fb-heading fb-<?= $lvl ?>"><?= fb_h($label) ?></<?= $lvl ?>>
        <?php elseif ($type === 'paragraph'): ?><div class="fb-paragraph"><?= nl2br(fb_h($label)) ?></div>
        <?php elseif ($type === 'richtext'):
          $fs = fb_field_settings($f); ?>
        <div class="fb-richtext"><?= $unsafeCode ? (string)($fs['html'] ?? '') : (function_exists('cms_sanitize_restricted_html') ? cms_sanitize_restricted_html((string)($fs['html'] ?? '')) : strip_tags((string)($fs['html'] ?? ''), '<p><br><strong><em><ul><ol><li><a>')) ?></div>
        <?php elseif ($type === 'raw_html'):
          $fs = fb_field_settings($f); ?>
        <div class="fb-rawhtml"><?= $unsafeCode ? (string)($fs['html'] ?? '') : '' ?></div>
        <?php elseif ($type === 'image_block'):
          $fs = fb_field_settings($f);
          $url = (string)($fs['url'] ?? '');
          if ($url !== ''):
            $w = (string)($fs['width'] ?? '');
            $style = in_array($w, ['25', '50', '75'], true) ? ' style="max-width:' . $w . '%"' : ''; ?>
        <figure class="fb-image"<?= $style ?>>
          <img src="<?= fb_h($url) ?>" alt="<?= fb_h((string)($fs['alt'] ?? '')) ?>" loading="lazy">
          <?php if (trim((string)($fs['caption'] ?? '')) !== ''): ?><figcaption><?= fb_h((string)$fs['caption']) ?></figcaption><?php endif; ?>
        </figure>
          <?php endif; ?>
        <?php elseif ($type === 'total'): ?>
        <div class="fb-total">
          <span class="lbl"><?= fb_h($label !== '' ? $label : 'Total') ?></span>
          <span class="amt" data-fb-total><?= fb_format_currency(0, (string)$publicSettings['currency_code']) ?></span>
        </div>
        <?php else: ?><hr class="fb-divider"><?php endif; ?>
      <?php elseif (!empty($meta['file'])): ?>
        <label class="fb-label"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></label>
        <?php
          $descriptionHtml = '';
          try { $descriptionHtml = fb_sanitize_upload_description((string)$uploadPolicy['description_html']); } catch (InvalidArgumentException) {}
          $maxFiles = (int)$uploadPolicy['max_files'];
          $inputName = $key . ($maxFiles > 1 ? '[]' : '');
        ?>
        <?php if ($descriptionHtml !== ''): ?><div class="fb-upload-description"><?= $descriptionHtml ?></div><?php endif; ?>
        <input type="hidden" name="fb_upload_count[<?= fb_h($key) ?>]" value="0" data-fb-upload-count>
        <div class="fb-drop" data-max="<?= $maxBytes ?>" data-max-files="<?= $maxFiles ?>" data-image="<?= !empty($meta['image']) ? '1' : '0' ?>" data-preview-mode="<?= fb_h((string)$uploadPolicy['preview_mode']) ?>">
          <input type="file" name="<?= fb_h($inputName) ?>" accept="<?= fb_h(implode(',', array_map(static fn(string $ext): string => '.' . $ext, $uploadPolicy['exts']))) ?>" <?= $maxFiles > 1 ? 'multiple' : '' ?> <?= $req ? 'required' : '' ?>>
          <div class="up-ic" aria-hidden="true"><?= !empty($meta['image']) ? '&#128444;' : '&#8682;' ?></div>
          <div class="up-t"><?= fb_h(fb_message($publicSettings, 'dropzone_prompt')) ?></div>
          <div class="up-s"><?= fb_h(fb_message($publicSettings, 'max_size', ['size'=>round($maxBytes / 1048576, 1)])) ?><?= $maxFiles > 1 ? ' · ' . fb_h(fb_message($publicSettings, 'max_files', ['max'=>$maxFiles])) : '' ?></div>
          <div class="up-items" aria-live="polite"></div>
          <div class="up-error" data-fb-upload-error role="alert"></div>
        </div>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php elseif ($type === 'country'): ?>
        <label class="fb-label" for="<?= fb_h($id) ?>"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></label>
        <select id="<?= fb_h($id) ?>" name="<?= fb_h($key) ?>" data-fb-country <?= $req ? 'required' : '' ?>>
          <option value=""><?= fb_h($f['placeholder'] ?: fb_message($publicSettings, 'country_placeholder')) ?></option>
          <?php foreach (fb_country_catalog() as $countryCode => $country): ?>
          <option value="<?= fb_h($countryCode) ?>" data-dial="<?= fb_h($country['dial']) ?>"><?= fb_h($country['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php elseif ($type === 'intl_phone'):
        $countryField = (string)($fsA['country_field'] ?? ''); ?>
        <label class="fb-label" for="<?= fb_h($id) ?>"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></label>
        <div class="fb-intl-phone" data-fb-phone data-country-field="<?= fb_h($countryField) ?>">
          <span class="fb-dial is-empty" data-fb-dial>&mdash;</span>
          <input type="tel" id="<?= fb_h($id) ?>" name="<?= fb_h($key) ?>" placeholder="<?= fb_h($f['placeholder'] ?? '') ?>" autocomplete="tel" <?= $req ? 'required' : '' ?><?= !empty($valid['maxlength']) ? ' maxlength="' . (int)$valid['maxlength'] . '"' : '' ?>>
        </div>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php elseif ($type === 'textarea'): ?>
        <label class="fb-label" for="<?= fb_h($id) ?>"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></label>
        <textarea id="<?= fb_h($id) ?>" name="<?= fb_h($key) ?>" placeholder="<?= fb_h($f['placeholder'] ?? '') ?>" <?= $req ? 'required' : '' ?>></textarea>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php elseif ($type === 'select'): ?>
        <label class="fb-label" for="<?= fb_h($id) ?>"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></label>
        <select id="<?= fb_h($id) ?>" name="<?= fb_h($key) ?>" <?= $req ? 'required' : '' ?>>
          <option value="" disabled selected><?= fb_h($f['placeholder'] ?: fb_message($publicSettings, 'select_placeholder')) ?></option>
          <?php $choiceOptions = fb_field_options($f); foreach ($choiceOptions as $choiceIndex => $o):
            $optionFull = isset($o['capacity']) && ($capacityUsage[$key][$o['value']] ?? 0) >= $o['capacity'];
            $optionLabel = $o['label'] . ($o['price'] > 0 ? ' (+' . fb_format_currency($o['price'], (string)$publicSettings['currency_code']) . ')' : '');
            if ($optionFull) $optionLabel .= ' — ' . fb_message($publicSettings, 'option_full_suffix'); ?>
          <option value="<?= fb_h($o['value']) ?>"<?= $optionFull ? ' disabled aria-disabled="true"' : '' ?>><?= fb_h($optionLabel) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php elseif ($type === 'radio' || $type === 'checkbox'): ?>
        <?php $choiceOptions = fb_field_options($f); ?>
        <span class="fb-label" style="display:block"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></span>
        <div class="fb-choices">
          <?php foreach ($choiceOptions as $o): ?>
          <label class="fb-choice">
            <input type="<?= $type ?>" name="<?= fb_h($key) ?><?= $type === 'checkbox' ? '[]' : '' ?>" value="<?= fb_h($o['value']) ?>" <?= ($req && ($type === 'radio' || ($type === 'checkbox' && count($choiceOptions) === 1))) ? 'required' : '' ?>>
            <span><?= fb_h($o['label']) ?></span>
            <?php if ($o['price'] > 0): ?><span class="price">+<?= fb_h(fb_format_currency($o['price'], (string)$publicSettings['currency_code'])) ?></span><?php endif; ?>
          </label>
          <?php endforeach; ?>
        </div>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php else:
        $inputType = in_array($type, ['email', 'tel', 'number', 'date'], true) ? $type : 'text';
        $attrs = '';
        if (isset($valid['min']) && $valid['min'] !== '') $attrs .= ' min="' . fb_h((string)$valid['min']) . '"';
        if (isset($valid['max']) && $valid['max'] !== '') $attrs .= ' max="' . fb_h((string)$valid['max']) . '"';
        if (!empty($valid['maxlength'])) $attrs .= ' maxlength="' . (int)$valid['maxlength'] . '"';
        ?>
        <label class="fb-label" for="<?= fb_h($id) ?>"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></label>
        <input type="<?= $inputType ?>" id="<?= fb_h($id) ?>" name="<?= fb_h($key) ?>" placeholder="<?= fb_h($f['placeholder'] ?? '') ?>" <?= $req ? 'required' : '' ?><?= $attrs ?>>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function fb_render_form(PDO $pdo, array $form): string {
    $formId = (int)$form['id'];
    $slug = (string)$form['slug'];
    $settings = fb_form_settings($form);
    $recaptchaKeys = $settings['recaptcha'] === '1' ? fb_recaptcha_keys($pdo) : ['sitekey'=>'','secret'=>''];
    $recaptchaEnabled = $recaptchaKeys['sitekey'] !== '' && $recaptchaKeys['secret'] !== '';
    $locale = fb_locale();
    [$form, $settings] = fb_localized_form($form, $settings);
    $unsafeCode = ($settings['unsafe_code_enabled'] ?? false) === true;
    static $instances = 0;
    $instance = 'i' . (++$instances);
    $tree = fb_get_tree($pdo, $formId);
    $allFields = fb_flat_fields(fb_get_fields($pdo, $formId, false));
    $ctx = fb_public_ctx($pdo);
    $self = fb_safe_return_url((string)($_SERVER['REQUEST_URI'] ?? '/'));

    // Flash scoped to this form (multiple forms may share a page)
    $flash = (($_GET['fb_form'] ?? '') === $slug) ? (string)($_GET['fb_status'] ?? '') : '';
    $ref = preg_replace('/[^A-Z0-9\-]/i', '', (string)($_GET['fb_ref'] ?? ''));
    $msg = trim((string)($_GET['fb_msg'] ?? ''));

    // Price map for JS live total
    $priceMap = [];
    foreach ($allFields as &$localizedField) $localizedField = fb_localized_field($localizedField, $settings);
    unset($localizedField);
    foreach ($tree as &$treeRow) foreach ($treeRow['cols'] as &$treeCol) foreach ($treeCol['fields'] as &$treeField) $treeField = fb_localized_field($treeField, $settings);
    unset($treeRow, $treeCol, $treeField);
    $successField = fb_success_value_field($allFields, (string)$settings['success_detail_field']);
    $successTemplate = fb_success_notification_template($settings);
    $successMessage = fb_success_notification_text(
        $successTemplate,
        $successField !== null ? (string)$successField['label'] : '',
        '',
        (string)$settings['success_message_case']
    );
    $successToken = is_string($_GET['fb_success'] ?? null) ? $_GET['fb_success'] : '';
    $proofModel = null;
    $needsVerifiedSuccess = $successField !== null || $settings['submission_proof_enabled'] === '1';
    if ($flash === 'ok' && $needsVerifiedSuccess && $ref !== '' && $successToken !== '') {
        try {
            if (fb_success_token_check($pdo, $formId, $ref, $successToken)) {
                $submission = $pdo->prepare('SELECT data_json,created_at FROM fb_submissions WHERE form_id = ? AND reference_code = ? AND is_deleted = 0 LIMIT 1');
                $submission->execute([$formId, $ref]);
                $submissionRow = $submission->fetch(PDO::FETCH_ASSOC);
                if (is_array($submissionRow)) {
                    if (!headers_sent()) {
                        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
                        header('Pragma: no-cache');
                        header('Referrer-Policy: no-referrer');
                        header('X-Robots-Tag: noindex, nofollow, noarchive');
                    }
                    $data = json_decode((string)($submissionRow['data_json'] ?? ''), true);
                    $successValue = '';
                    if ($successField !== null) {
                        $fieldKey = (string)$successField['field_key'];
                        $successValue = is_array($data) ? fb_success_value_text($successField, $data[$fieldKey] ?? '') : '';
                        $successMessage = fb_success_notification_text(
                            $successTemplate,
                            (string)$successField['label'],
                            $successValue,
                            (string)$settings['success_message_case']
                        );
                    }
                    if ($settings['submission_proof_enabled'] === '1') {
                        $createdAt = (string)($submissionRow['created_at'] ?? '');
                        $submittedAt = function_exists('app_display_datetime') ? app_display_datetime($createdAt) : $createdAt;
                        $proofModel = [
                            'format'=>(string)$settings['submission_proof_format'],
                            'filename'=>substr(preg_replace('/[^a-z0-9_-]+/i', '-', $slug . '-' . strtolower($ref)) ?? 'submission-proof', 0, 120),
                            'proof_title'=>fb_message($settings, 'proof_title'),
                            'form_title'=>mb_substr((string)$form['title'], 0, 180),
                            'success_heading'=>fb_message($settings, 'success_heading'),
                            'success_message'=>mb_substr($successMessage, 0, 4000),
                            'reference_label'=>fb_message($settings, 'reference_label'),
                            'reference'=>$ref,
                            'submitted_label'=>fb_message($settings, 'proof_submitted_at'),
                            'submitted_at'=>mb_substr($submittedAt, 0, 200),
                            'note'=>fb_message($settings, 'proof_note'),
                            'download_label'=>fb_message($settings, 'proof_download'),
                            'preparing_label'=>fb_message($settings, 'proof_preparing'),
                            'failed_label'=>fb_message($settings, 'proof_failed'),
                        ];
                    }
                }
            }
        } catch (Throwable $error) {
            error_log('[form-builder] verified success data unavailable: ' . $error->getMessage());
        }
    }
    $capacityUsage = fb_select_capacity_usage($pdo, $formId, $allFields);
    foreach ($allFields as $f) {
        if (!in_array($f['type'], ['select', 'radio', 'checkbox'], true)) continue;
        foreach (fb_field_options($f) as $o) {
            if ($o['price'] !== 0) $priceMap[$f['field_key'] . '::' . $o['value']] = $o['price'];
        }
    }
    $hasTotalEl = false;
    foreach ($allFields as $f) { if ($f['type'] === 'total') { $hasTotalEl = true; break; } }
    // Legacy auto-total: only when the toggle is on and no Total element is placed on the canvas.
    $showTotal = $settings['show_total'] === '1' && $priceMap !== [] && !$hasTotalEl;

    static $cssPrinted = false;
    static $proofScriptPrinted = false;
    static $recaptchaScriptPrinted = false;
    ob_start();

    if (!$cssPrinted):
        $cssPrinted = true; ?>
<style>
.fb-wrap, .fb-wrap * { box-sizing: border-box; margin: 0; padding: 0; }
.fb-wrap {
  --fb-accent: #2b7a4a; --fb-accent-deep: #1c5633; --fb-danger: #be2d2d;
  --fb-surface: #ffffff; --fb-bg: #f4f7f2; --fb-text: #1d241d; --fb-muted: #6b776b;
  --fb-border: #d9e2d6; --fb-radius: 14px;
  font-family: inherit; color: var(--fb-text); line-height: 1.55;
  background: linear-gradient(165deg, var(--fb-bg) 0%, var(--fb-surface) 75%); border: 1px solid var(--fb-border);
  border-radius: 20px; padding: clamp(1.4rem, 3.5vw, 2.6rem); margin: 1.5rem 0;
}
.fb-title { font-size: 1.35rem; font-weight: 700; margin-bottom: .35rem; }
.fb-desc { color: var(--fb-muted); font-size: .94rem; margin-bottom: 1.4rem; }
.fb-rows { display: flex; flex-direction: column; gap: 1rem; }
.fb-row { display: grid; grid-template-columns: repeat(12, 1fr); gap: 1rem 1.1rem; }
.fb-col { grid-column: span 12; display: flex; flex-direction: column; gap: 1rem; }
.fb-col.c6 { grid-column: span 6; } .fb-col.c4 { grid-column: span 4; } .fb-col.c3 { grid-column: span 3; }
@media (max-width: 640px) { .fb-col { grid-column: span 12 !important; } }
.fb-field label.fb-label { display: block; font-size: .74rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; margin-bottom: .42rem; color: var(--fb-text); }
.fb-label .req { color: var(--fb-danger); }
.fb-field input[type=text], .fb-field input[type=email], .fb-field input[type=tel], .fb-field input[type=number], .fb-field input[type=date], .fb-field textarea, .fb-field select {
  width: 100%; font: inherit; font-size: .95rem; color: var(--fb-text);
  background: var(--fb-surface); border: 1.5px solid var(--fb-border); border-radius: var(--fb-radius);
  padding: .7rem .95rem; outline: none; transition: border-color .25s, box-shadow .25s;
}
.fb-field textarea { min-height: 110px; resize: vertical; }
.fb-field select { cursor: pointer; }
.fb-field select option:disabled { color: var(--fb-muted); }
.fb-intl-phone { display: flex; align-items: stretch; }
.fb-intl-phone .fb-dial { display: inline-flex; align-items: center; min-width: 4.5rem; padding: .7rem .8rem; color: var(--fb-muted); background: var(--fb-bg); border: 1.5px solid var(--fb-border); border-right: 0; border-radius: var(--fb-radius) 0 0 var(--fb-radius); font-variant-numeric: tabular-nums; }
.fb-intl-phone input[type=tel] { border-radius: 0 var(--fb-radius) var(--fb-radius) 0; }
.fb-field input:focus, .fb-field textarea:focus, .fb-field select:focus { border-color: var(--fb-accent); box-shadow: 0 0 0 4px var(--fb-accent-soft, rgba(43 122 74 / .14)); }
.fb-help { font-size: .76rem; color: var(--fb-muted); margin-top: .35rem; }
.fb-upload-description { font-size: .86rem; color: var(--fb-muted); margin: -.1rem 0 .55rem; }
.fb-upload-description > :first-child { margin-top: 0; }
.fb-upload-description > :last-child { margin-bottom: 0; }
.fb-choices { display: flex; flex-direction: column; gap: .5rem; }
.fb-choice { display: flex; align-items: center; gap: .55rem; font-size: .94rem; background: var(--fb-surface); border: 1.5px solid var(--fb-border); border-radius: var(--fb-radius); padding: .6rem .9rem; cursor: pointer; transition: border-color .2s, background .2s; }
.fb-choice:hover { border-color: var(--fb-accent); }
.fb-choice input { accent-color: var(--fb-accent); width: 16px; height: 16px; flex-shrink: 0; }
.fb-choice .price { margin-left: auto; font-size: .76rem; font-weight: 700; color: var(--fb-accent-deep); white-space: nowrap; }
.fb-heading { font-weight: 700; padding-bottom: .4rem; border-bottom: 2px solid var(--fb-border); margin: 0; }
.fb-h1 { font-size: 1.9rem; } .fb-h2 { font-size: 1.5rem; } .fb-h3 { font-size: 1.25rem; }
.fb-h4 { font-size: 1.12rem; } .fb-h5 { font-size: 1rem; } .fb-h6 { font-size: .9rem; text-transform: uppercase; letter-spacing: .05em; }
.fb-paragraph { color: var(--fb-muted); font-size: .94rem; }
.fb-divider { border: none; border-top: 1.5px dashed var(--fb-border); margin: .4rem 0; }
.fb-richtext { font-size: .94rem; line-height: 1.6; }
.fb-richtext img { max-width: 100%; height: auto; border-radius: 8px; }
.fb-richtext figure { margin: .5rem 0; }
.fb-richtext figcaption, .fb-image figcaption { font-size: .8rem; color: var(--fb-muted); text-align: center; margin-top: .35rem; }
.fb-image { margin: 0; }
.fb-image img { max-width: 100%; height: auto; border-radius: 10px; display: block; }
.fb-rawhtml > *:first-child { margin-top: 0; }
.fb-al-c { text-align: center; }
.fb-al-r { text-align: right; }
.fb-al-c .fb-choices { align-items: center; }
.fb-al-r .fb-choices { align-items: flex-end; }
.fb-al-c .fb-choice, .fb-al-r .fb-choice { text-align: left; }
.fb-al-c .fb-image { margin-left: auto; margin-right: auto; }
.fb-al-r .fb-image { margin-left: auto; margin-right: 0; }
.fb-al-c .fb-image img { margin-left: auto; margin-right: auto; }
.fb-al-r .fb-image img { margin-left: auto; margin-right: 0; }
.fb-al-c .fb-image figcaption { text-align: center; }
.fb-al-r .fb-image figcaption { text-align: right; }
.fb-al-c .fb-richtext img, .fb-al-c .fb-richtext figure { margin-left: auto; margin-right: auto; }
.fb-al-r .fb-richtext img, .fb-al-r .fb-richtext figure { margin-left: auto; margin-right: 0; }
.fb-v-m { margin-top: auto; margin-bottom: auto; }
.fb-v-b { margin-top: auto; }
.fb-drop { position: relative; border: 2px dashed var(--fb-border); border-radius: var(--fb-radius); padding: 1.5rem 1rem; text-align: center; background: var(--fb-surface); transition: border-color .25s, background .25s; cursor: pointer; }
.fb-drop:hover, .fb-drop.dragover { border-color: var(--fb-accent); background: var(--fb-accent-soft, rgba(43 122 74 / .05)); }
.fb-drop input[type=file] { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 2; }
.fb-drop .up-t, .fb-drop .up-s, .fb-drop .up-ic { position: relative; z-index: 1; pointer-events: none; }
.fb-drop .up-ic { font-size: 1.5rem; margin-bottom: .35rem; }
.fb-drop .up-t { font-weight: 600; font-size: .92rem; }
.fb-drop .up-s { font-size: .74rem; color: var(--fb-muted); margin-top: .2rem; }
.fb-drop .up-items, .fb-drop .up-error { position: relative; z-index: 3; pointer-events: none; }
.fb-drop .up-items { display: grid; grid-template-columns: repeat(auto-fit, minmax(112px, 1fr)); gap: .55rem; margin-top: .75rem; }
.fb-drop .up-items:empty { display: none; }
.fb-drop .up-item { position: relative; min-width: 0; padding: .75rem .55rem .55rem; border: 1px solid var(--fb-border); border-radius: 10px; background: var(--fb-surface); }
.fb-drop .up-item img { display: block; width: 100%; height: 82px; margin-bottom: .4rem; border-radius: 7px; object-fit: cover; }
.fb-drop .up-item svg { display: block; width: 34px; height: 34px; margin: 0 auto .4rem; color: var(--fb-accent-deep); }
.fb-drop .up-name { display: block; overflow: hidden; font-size: .75rem; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
.fb-drop .up-size { display: block; font-size: .68rem; color: var(--fb-muted); }
.fb-drop .up-remove { position: absolute; top: .25rem; right: .25rem; z-index: 1; width: 1.55rem; height: 1.55rem; border: 1px solid var(--fb-border); border-radius: 999px; color: var(--fb-danger); background: var(--fb-surface); font: 700 1rem/1 sans-serif; cursor: pointer; pointer-events: auto; }
.fb-drop .up-remove:hover, .fb-drop .up-remove:focus-visible { border-color: var(--fb-danger); outline: 2px solid rgba(190 45 45 / .2); outline-offset: 1px; }
.fb-drop .up-error { display: none; margin-top: .55rem; color: var(--fb-danger); font-size: .76rem; font-weight: 650; }
.fb-drop .up-error:not(:empty) { display: block; }
.fb-drop.has-file { border-style: solid; border-color: var(--fb-accent); background: var(--fb-accent-soft, rgba(43 122 74 / .06)); }
.fb-total { display: flex; justify-content: space-between; align-items: center; gap: 1rem; background: var(--fb-accent-soft, rgba(43 122 74 / .07)); border: 1.5px dashed var(--fb-accent); border-radius: var(--fb-radius); padding: .9rem 1.2rem; margin-top: 1rem; }
.fb-total .lbl { font-size: .72rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--fb-accent-deep); }
.fb-total .amt { font-size: 1.35rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.fb-total .amt.pop { animation: fb-pop .45s cubic-bezier(.34,1.56,.64,1); }
.fb-submit { width: 100%; margin-top: 1rem; display: inline-flex; align-items: center; justify-content: center; gap: .5rem; border: none; cursor: pointer; font: inherit; font-size: 1rem; font-weight: 700; color: var(--fb-on-accent, #fff); background: linear-gradient(135deg, var(--fb-accent), var(--fb-accent-deep)); border-radius: var(--fb-radius); padding: .9rem 1.5rem; box-shadow: 0 8px 22px var(--fb-accent-soft, rgba(43 122 74 / .3)); transition: transform .2s, box-shadow .2s; }
.fb-submit:hover { transform: translateY(-2px); box-shadow: 0 12px 28px var(--fb-accent-soft, rgba(43 122 74 / .4)); }
.fb-submit:disabled { opacity: .75; cursor: wait; transform: none; }
.fb-recaptcha { margin-top: 1rem; min-height: 78px; overflow-x: auto; }
.fb-flash-err { background: rgba(190 45 45 / .08); border: 1.5px solid rgba(190 45 45 / .4); color: var(--fb-danger); border-radius: var(--fb-radius); padding: .8rem 1rem; font-size: .9rem; font-weight: 600; margin-bottom: 1rem; }
.fb-success { text-align: center; padding: 2.5rem 1rem; }
.fb-success .check { width: 72px; height: 72px; margin: 0 auto 1rem; border-radius: 50%; background: linear-gradient(135deg, var(--fb-accent), var(--fb-accent-deep)); color: var(--fb-on-accent, #fff); display: grid; place-items: center; font-size: 2rem; animation: fb-pop .55s cubic-bezier(.34,1.56,.64,1); }
.fb-success h3 { font-size: 1.4rem; margin-bottom: .5rem; }
.fb-success p { color: var(--fb-muted); max-width: 44ch; margin: 0 auto 1.2rem; }
.fb-success-detail { max-width: 52ch; margin: 0 auto 1.5rem; color: var(--fb-text); font-size: 1rem; line-height: 1.55; overflow-wrap: anywhere; }
.fb-success-value { color: var(--fb-accent-deep); font-size: clamp(1.45rem, 4vw, 2.15rem); font-weight: 800; line-height: 1.2; }
.fb-ref { display: inline-block; font-family: ui-monospace, monospace; font-weight: 700; letter-spacing: .08em; background: var(--fb-surface); border: 1.5px dashed var(--fb-accent); color: var(--fb-accent-deep); border-radius: 10px; padding: .5rem 1.1rem; }
.fb-proof { display: flex; flex-direction: column; align-items: center; gap: .55rem; margin-top: 1.4rem; }
.fb-proof-download { display: inline-flex; align-items: center; justify-content: center; gap: .55rem; min-height: 44px; border: 1.5px solid var(--fb-accent); border-radius: var(--fb-radius); padding: .7rem 1rem; color: var(--fb-accent-deep); background: var(--fb-surface); font: inherit; font-weight: 750; cursor: pointer; transition: background .2s, color .2s, transform .2s; }
.fb-proof-download:hover { color: var(--fb-on-accent, #fff); background: var(--fb-accent); transform: translateY(-1px); }
.fb-proof-download:focus-visible { outline: 3px solid var(--fb-accent-soft, rgba(43 122 74 / .25)); outline-offset: 2px; }
.fb-proof-download:disabled { opacity: .65; cursor: wait; transform: none; }
.fb-proof-download svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2; }
.fb-proof-status { min-height: 1.2em; color: var(--fb-muted); font-size: .78rem; }
@keyframes fb-pop { 0% { transform: scale(.7); } 60% { transform: scale(1.12); } 100% { transform: scale(1); } }
</style>
<?php endif; ?>

<?php if ($unsafeCode && !empty($form['css'])): ?>
<style>/* custom CSS for form <?= fb_h($slug) ?> */
<?= (string)$form['css'] ?>
</style>
<?php endif; ?>

<div class="fb-wrap" id="fb-<?= fb_h($slug . '-' . $instance) ?>"<?= ($accentStyle = fb_accent_style($settings)) !== '' ? ' style="' . fb_h($accentStyle) . '"' : '' ?>>
<?php if ($flash === 'ok'): ?>
  <div class="fb-success">
    <div class="check">&#10003;</div>
    <h3><?= fb_h(fb_message($settings, 'success_heading')) ?></h3>
    <?php if ($successMessage !== ''): ?><p><?= nl2br(fb_h($successMessage)) ?></p><?php endif; ?>
    <?php if ($ref !== ''): ?><div><?= fb_h(fb_message($settings, 'reference_label')) ?></div><span class="fb-ref"><?= fb_h($ref) ?></span><?php endif; ?>
    <?php if (is_array($proofModel)):
      $proofJson = json_encode($proofModel, FB_JSON_FLAGS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
      $proofScriptHash = is_file(__DIR__ . '/proof.js') ? substr(hash_file('sha256', __DIR__ . '/proof.js'), 0, 16) : 'missing'; ?>
    <div class="fb-proof" data-fb-proof>
      <button class="fb-proof-download" type="button" data-fb-proof-download>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14"/></svg>
        <span><?= fb_h((string)$proofModel['download_label']) ?></span>
      </button>
      <span class="fb-proof-status" data-fb-proof-status role="status" aria-live="polite"></span>
      <script type="application/json" data-fb-proof-model><?= $proofJson ?></script>
    </div>
    <?php if (!$proofScriptPrinted): $proofScriptPrinted = true; ?><script src="/static/plugins/form-builder/proof.js?v=<?= fb_h($proofScriptHash) ?>" defer referrerpolicy="no-referrer"></script><?php endif; ?>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="fb-title"><?= fb_h($form['title']) ?></div>
  <?php if (!empty($form['description'])): ?><div class="fb-desc"><?= nl2br(fb_h($form['description'])) ?></div><?php endif; ?>

  <form method="post" action="/form-submit/" enctype="multipart/form-data" data-fb-form="<?= fb_h($slug) ?>" novalidate>
    <input type="hidden" name="fb_form_id" value="<?= $formId ?>">
    <input type="hidden" name="fb_slug" value="<?= fb_h($slug) ?>">
    <input type="hidden" name="fb_locale" value="<?= fb_h($locale) ?>">
    <input type="hidden" name="csrf_token" value="<?= fb_h($ctx['csrf']) ?>">
    <input type="hidden" name="fb_return" value="<?= fb_h($self) ?>">
    <input type="hidden" name="fb_started" value="<?= fb_h(fb_started_token($pdo, $formId, $locale)) ?>">
    <input type="hidden" name="fb_idempotency" value="<?= fb_h(bin2hex(random_bytes(16))) ?>">
    <div style="position:absolute;left:-9999px" aria-hidden="true"><input type="text" name="fb_website" tabindex="-1" autocomplete="off"></div>

    <?php if ($flash === 'err'): ?>
    <div class="fb-flash-err"><?= fb_h($msg !== '' ? $msg : fb_message($settings, 'generic_error')) ?></div>
    <?php endif; ?>

    <div class="fb-rows">
    <?php foreach ($tree as $row):
        $n = max(1, count($row['cols']));
        $span = intdiv(12, min(4, $n));
        $colCls = ['12' => '', '6' => ' c6', '4' => ' c4', '3' => ' c3'][$span] ?? ''; ?>
      <div class="fb-row">
        <?php foreach ($row['cols'] as $col): ?>
        <div class="fb-col<?= $colCls ?>">
          <?php foreach ($col['fields'] as $f) echo fb_render_field_html($f, $slug, $instance, $unsafeCode, $settings, $capacityUsage); ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    </div>

    <?php if ($showTotal): ?>
    <div class="fb-total">
      <span class="lbl"><?= fb_h($settings['total_label']) ?></span>
      <span class="amt" data-fb-total><?= fb_format_currency(0, (string)$settings['currency_code']) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($recaptchaEnabled): ?>
    <div class="fb-recaptcha"><div class="g-recaptcha" data-sitekey="<?= fb_h($recaptchaKeys['sitekey']) ?>"></div></div>
    <?php if (!$recaptchaScriptPrinted): $recaptchaScriptPrinted = true; ?><script src="https://www.google.com/recaptcha/api.js" defer referrerpolicy="no-referrer"></script><?php endif; ?>
    <?php endif; ?>

    <button type="submit" class="fb-submit" data-fb-submit><?= fb_h($settings['submit_label']) ?></button>
  </form>
<?php endif; ?>
</div>

<script>
(function () {
  var root = document.getElementById('fb-<?= fb_h($slug . '-' . $instance) ?>');
  if (!root) return;
  var form = root.querySelector('form[data-fb-form]');
  if (!form) return;
  var I18N = <?= json_encode(['choose_image'=>fb_message($settings,'choose_image'),'file_too_large'=>fb_message($settings,'file_too_large'),'ready_to_upload'=>fb_message($settings,'ready_to_upload'),'too_many_files'=>fb_message($settings,'too_many_files'),'files_ready'=>fb_message($settings,'files_ready'),'files_selected'=>fb_message($settings,'files_selected'),'add_more_files'=>fb_message($settings,'add_more_files'),'remove_file'=>fb_message($settings,'remove_file'),'selection_update_failed'=>fb_message($settings,'selection_update_failed'),'submitting'=>fb_message($settings,'submitting')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

  // ---- Live total ----
  var PRICES = <?= json_encode($priceMap, JSON_UNESCAPED_UNICODE) ?>;
  var totalEls = root.querySelectorAll('[data-fb-total]');
  var CURRENCY = <?= json_encode((string)$settings['currency_code']) ?>;
  function money(n) { return CURRENCY + ' ' + Number(n || 0).toLocaleString(); }
  function recalc() {
    if (!totalEls.length) return;
    var total = 0;
    form.querySelectorAll('input[name$="[]"]:checked, input[type=radio]:checked, select').forEach(function (el) {
      var name = el.name.replace(/\[\]$/, '');
      if (el.tagName === 'SELECT') {
        if (el.value) total += PRICES[name + '::' + el.value] || 0;
      } else {
        total += PRICES[name + '::' + el.value] || 0;
      }
    });
    var txt = money(total);
    totalEls.forEach(function (totalEl) {
      if (totalEl.textContent !== txt) {
        totalEl.textContent = txt;
        totalEl.classList.remove('pop'); void totalEl.offsetWidth; totalEl.classList.add('pop');
      }
    });
  }
  form.addEventListener('change', recalc);
  recalc();

  // ---- Linked international phone prefixes ----
  function syncPhone(phone) {
    var country = form.elements[phone.getAttribute('data-country-field')];
    var option = country && country.options ? country.options[country.selectedIndex] : null;
    var dial = option ? option.getAttribute('data-dial') || '' : '';
    var prefix = phone.querySelector('[data-fb-dial]');
    if (prefix) {
      prefix.textContent = dial ? '+' + dial : '—';
      prefix.classList.toggle('is-empty', !dial);
    }
  }
  root.querySelectorAll('[data-fb-phone]').forEach(function (phone) {
    var country = form.elements[phone.getAttribute('data-country-field')];
    if (country) country.addEventListener('change', function () { syncPhone(phone); });
    syncPhone(phone);
  });

  // ---- Dropzones ----
  root.querySelectorAll('.fb-drop').forEach(function (zone) {
    var input = zone.querySelector('input[type=file]');
    var title = zone.querySelector('.up-t');
    var sub = zone.querySelector('.up-s');
    var items = zone.querySelector('.up-items');
    var uploadError = zone.querySelector('[data-fb-upload-error]');
    var countInput = zone.parentNode.querySelector('[data-fb-upload-count]');
    var max = parseInt(zone.getAttribute('data-max') || '5242880', 10);
    var maxFiles = parseInt(zone.getAttribute('data-max-files') || '1', 10);
    var isImage = zone.getAttribute('data-image') === '1';
    var previewMode = zone.getAttribute('data-preview-mode') || (isImage ? 'real' : 'icon');
    var initialTitle = title.textContent;
    var initialSub = sub.textContent;
    var selectedFiles = [];
    var objectUrls = [];
    function fmt(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
    function message(template, values) {
      return Object.keys(values).reduce(function (text, key) { return text.replace('{' + key + '}', String(values[key])); }, template);
    }
    function revokePreviews() {
      objectUrls.forEach(function (url) { URL.revokeObjectURL(url); });
      objectUrls = [];
    }
    function showError(text) {
      if (uploadError) uploadError.textContent = text;
    }
    function assignFiles(files) {
      if (!files.length) { input.value = ''; return true; }
      try {
        var transfer = new DataTransfer();
        files.forEach(function (file) { transfer.items.add(file); });
        input.files = transfer.files;
        return input.files.length === files.length;
      } catch (error) { return false; }
    }
    function fileKey(file) {
      return [file.name, file.size, file.type, file.lastModified].join('\u0000');
    }
    function validationError(files) {
      for (var i = 0; i < files.length; i++) {
        if (isImage && files[i].type.indexOf('image/') !== 0) return I18N.choose_image;
        if (files[i].size > max) return message(I18N.file_too_large, {size:fmt(files[i].size)});
      }
      return '';
    }
    function appendIcon(target, file) {
      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 24 24');
      svg.setAttribute('aria-hidden', 'true');
      var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('fill', 'currentColor');
      path.setAttribute('d', file.type === 'application/pdf' ? 'M6 2h8l4 4v16H6V2zm7 1.5V7h3.5L13 3.5zM8 11v7h2v-2h1.2a2.5 2.5 0 0 0 0-5H8zm2 2h1.2a.5.5 0 0 1 0 1H10v-1z' : 'M6 2h8l4 4v16H6V2zm7 1.5V7h3.5L13 3.5zM8 11h8v2H8v-2zm0 4h8v2H8v-2z');
      svg.appendChild(path);
      target.appendChild(svg);
    }
    function appendPreview(file, index) {
      if (!items) return;
      var item = document.createElement('div');
      item.className = 'up-item';
      if (previewMode === 'real' && file.type.indexOf('image/') === 0) {
        try {
          var image = document.createElement('img');
          var url = URL.createObjectURL(file);
          objectUrls.push(url);
          image.src = url;
          image.alt = '';
          item.appendChild(image);
        } catch (error) { appendIcon(item, file); }
      } else if (previewMode !== 'none') {
        appendIcon(item, file);
      }
      var name = document.createElement('span');
      name.className = 'up-name';
      name.textContent = file.name;
      name.title = file.name;
      var size = document.createElement('span');
      size.className = 'up-size';
      size.textContent = fmt(file.size);
      item.appendChild(name);
      item.appendChild(size);
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'up-remove';
      remove.setAttribute('data-fb-remove-file', '');
      remove.setAttribute('aria-label', message(I18N.remove_file, {file:file.name}));
      remove.title = message(I18N.remove_file, {file:file.name});
      remove.textContent = '\u00d7';
      remove.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        var next = selectedFiles.filter(function (_, selectedIndex) { return selectedIndex !== index; });
        if (!assignFiles(next)) { showError(I18N.selection_update_failed); return; }
        selectedFiles = next;
        showError('');
        renderFiles();
      });
      item.appendChild(remove);
      items.appendChild(item);
    }
    function renderFiles() {
      revokePreviews();
      if (items) items.replaceChildren();
      if (countInput) countInput.value = String(selectedFiles.length);
      zone.classList.toggle('has-file', selectedFiles.length > 0);
      if (!selectedFiles.length) {
        title.textContent = initialTitle;
        sub.textContent = initialSub;
        showError('');
        return;
      }
      if (maxFiles > 1) {
        title.textContent = message(I18N.files_selected, {count:selectedFiles.length, max:maxFiles});
        var remaining = maxFiles - selectedFiles.length;
        var sizes = selectedFiles.map(function (file) { return fmt(file.size); }).join(' · ');
        sub.textContent = remaining > 0 ? message(I18N.add_more_files, {remaining:remaining}) + ' · ' + sizes : sizes;
      } else {
        title.textContent = selectedFiles[0].name;
        sub.textContent = message(I18N.ready_to_upload, {size:fmt(selectedFiles[0].size)});
      }
      selectedFiles.forEach(appendPreview);
    }
    function restoreFiles(errorText) {
      if (!assignFiles(selectedFiles)) {
        input.value = '';
        selectedFiles = [];
        renderFiles();
        showError(I18N.selection_update_failed);
        return;
      }
      showError(errorText);
    }
    function addFiles(incoming) {
      var invalid = validationError(incoming);
      if (invalid !== '') {
        restoreFiles(invalid);
        return;
      }
      var next = maxFiles > 1 ? selectedFiles.slice() : [];
      var known = {};
      next.forEach(function (file) { known[fileKey(file)] = true; });
      incoming.forEach(function (file) {
        var key = fileKey(file);
        if (!known[key]) { known[key] = true; next.push(file); }
      });
      if (next.length > maxFiles) {
        restoreFiles(message(I18N.too_many_files, {field:'', max:maxFiles}).trim());
        return;
      }
      if (!assignFiles(next)) {
        selectedFiles = Array.prototype.slice.call(input.files || []);
        renderFiles();
        showError(I18N.selection_update_failed);
        return;
      }
      selectedFiles = next;
      showError('');
      renderFiles();
    }
    input.addEventListener('change', function () {
      var incoming = Array.prototype.slice.call(input.files || []);
      if (incoming.length) addFiles(incoming);
      else assignFiles(selectedFiles);
    });
    ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('dragover'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('dragover'); }); });
    zone.addEventListener('drop', function (e) {
      var dropped = Array.prototype.slice.call(e.dataTransfer.files || []);
      if (!dropped.length) return;
      addFiles(dropped);
    });
    form.addEventListener('reset', function () { selectedFiles = []; setTimeout(renderFiles, 0); });
    window.addEventListener('pagehide', revokePreviews, { once: true });
    form.addEventListener('submit', revokePreviews);
  });

  // ---- Submit loader ----
  var btn = form.querySelector('[data-fb-submit]');
  form.addEventListener('submit', function (e) {
    if (!form.checkValidity()) { e.preventDefault(); form.reportValidity(); return; }
    if (btn) { btn.disabled = true; btn.textContent = I18N.submitting; }
  });
})();
</script>
<?php if ($unsafeCode && !empty($form['js'])): ?>
<script>/* custom JS for form <?= fb_h($slug) ?> */
(function (root, form) {
<?= (string)$form['js'] ?>
})(document.getElementById('fb-<?= fb_h($slug . '-' . $instance) ?>'), document.querySelector('#fb-<?= fb_h($slug . '-' . $instance) ?> form'));
</script>
<?php endif; ?>
<?php
    return (string)ob_get_clean();
}
