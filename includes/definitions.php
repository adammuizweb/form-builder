<?php
declare(strict_types=1);

const FB_DEFINITION_SCHEMA = 1;
const FB_DEFINITION_MAX_BYTES = 524288;
const FB_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

function fb_json_encode(mixed $value): string { return json_encode($value, FB_JSON_FLAGS); }

function fb_public_message_defaults(string $locale): array {
    $defaults = [
            'default_submit'=>'Submit','default_success'=>'Thank you! Your submission has been received.','select_placeholder'=>'-- Select --','dropzone_prompt'=>'Drop file here or click to browse','max_size'=>'Max {size} MB','choose_image'=>'Please choose an image file','file_too_large'=>'File too large ({size})','ready_to_upload'=>'{size} - ready to upload','submitting'=>'Submitting...','generic_error'=>'Submission failed. Please check your input.','success_heading'=>'Application received','reference_label'=>'Reference','service_unavailable'=>'Service unavailable','method_not_allowed'=>'Method not allowed','request_too_large'=>'Request too large','invalid_request'=>'Invalid request','form_unavailable'=>'This form is not accepting submissions.','invalid_form'=>'Invalid form reference.','security_invalid'=>'Security token invalid. Please reload and try again.','spam'=>'Spam detected.','wait'=>'Please wait before submitting.','invalid_submission_key'=>'Invalid submission key.','captcha_failed'=>'reCAPTCHA verification failed.','rate_limited'=>'Too many submissions. Please try again later.','save_failed'=>'Submission could not be saved. Please try again.','required'=>'{field} is required','invalid_option'=>'{field} has an invalid option','invalid_input'=>'{field} has invalid input','invalid_email'=>'{field} must be a valid email','invalid_phone'=>'{field} must be a valid phone number','invalid_number'=>'{field} must be a number','number_min'=>'{field} must be at least {min}','number_max'=>'{field} must be at most {max}','invalid_date'=>'{field} must be a valid YYYY-MM-DD date','invalid_format'=>'{field} has an invalid format','too_long'=>'{field} is too long (max {max} characters)','date_after'=>'{field} must be after {other}','date_before'=>'{field} must be before {other}','upload_failed'=>'{field} upload failed','upload_invalid'=>'{field} upload is invalid','file_too_large_server'=>'{field} exceeds the {size} MB limit','extension_not_allowed'=>'{field}: file type .{extension} is not allowed','mime_mismatch'=>'{field} content does not match its extension','extension_size'=>'{field} exceeds the limit for .{extension}','invalid_image'=>'{field} is not a valid image','file_store_failed'=>'{field} could not be stored','upload_storage_unavailable'=>'Upload storage is unavailable','upload_finalize_failed'=>'An upload could not be finalized'
        ];
    $defaults += [
        'country_search' => 'Search countries',
        'country_placeholder' => '-- Select a country --',
        'invalid_country' => '{field} must be a valid country',
    ];
    if (!function_exists('apply_filters')) return $defaults;
    $localized = apply_filters('fb_public_message_defaults', $defaults, $locale);
    return is_array($localized) ? array_merge($defaults, $localized) : $defaults;
}

function fb_message(array $settings, string $key, array $values = []): string {
    $locale = fb_locale();
    $translation = fb_translation_overlay($settings, $locale);
    $defaults = fb_public_message_defaults($locale);
    $message = $translation['messages'][$key] ?? $defaults[$key] ?? fb_public_message_defaults('en')[$key] ?? $key;
    if (!is_string($message)) $message = $key;
    foreach ($values as $name => $value) $message = str_replace('{' . $name . '}', mb_substr((string)$value, 0, 1000), $message);
    return $message;
}

function fb_email_text(array $settings, string $key, array $values): string {
    $locale = fb_locale();
    $translation = fb_translation_overlay($settings, $locale);
    $defaults = ['admin_subject'=>'[{form_title}] New submission {reference}','admin_body'=>"A submission was received for {form_title}.\nReference: {reference}\n\n{fields}",'applicant_subject'=>'Submission received: {reference}','applicant_body'=>"Your submission was received.\nReference: {reference}\n"];
    if (function_exists('apply_filters')) {
        $localized = apply_filters('fb_email_text_defaults', $defaults, $locale);
        if (is_array($localized)) $defaults = array_merge($defaults, $localized);
    }
    $template = $translation['email'][$key] ?? $defaults[$key] ?? '';
    if (!is_string($template)) return '';
    foreach ($values as $name => $value) $template = str_replace('{' . $name . '}', (string)$value, $template);
    return $template;
}

function fb_definition_safe_settings(array $settings): array {
    return array_intersect_key($settings, array_fill_keys(array_keys(fb_default_settings()), true));
}

function fb_export_form_definition(PDO $pdo, string|int $form, bool $includeUnsafeCode = false): array {
    $row = is_int($form) ? fb_get_form($pdo, $form) : fb_get_form_by_slug($pdo, $form);
    if ($row === null) throw new InvalidArgumentException('Form not found.');
    $fields = fb_get_fields($pdo, (int)$row['id']);
    $idKeys = [];
    foreach ($fields as $field) $idKeys[(int)$field['id']] = (string)$field['field_key'];
    $exported = [];
    foreach ($fields as $field) {
        $fieldSettings = fb_field_settings($field);
        if (!$includeUnsafeCode && in_array($field['type'], ['richtext', 'raw_html'], true)) unset($fieldSettings['html']);
        $exported[] = ['key'=>(string)$field['field_key'],'parent'=>$idKeys[(int)$field['parent_id']] ?? null,'type'=>(string)$field['type'],'label'=>(string)$field['label'],'placeholder'=>(string)($field['placeholder'] ?? ''),'help'=>(string)($field['help_text'] ?? ''),'required'=>(bool)$field['required'],'width'=>(int)$field['width'],'order'=>(int)$field['sort_order'],'hidden'=>(bool)$field['is_hidden'],'options'=>fb_field_options($field),'validation'=>fb_field_validation($field),'settings'=>$fieldSettings];
    }
    $settings = fb_definition_safe_settings(fb_form_settings($row));
    $settings['unsafe_code_enabled'] = false;
    return ['schema'=>FB_DEFINITION_SCHEMA,'definition_id'=>hash('sha256', 'form-builder:' . $row['slug']),'form'=>['slug'=>(string)$row['slug'],'title'=>(string)$row['title'],'description'=>(string)($row['description'] ?? ''),'status'=>(string)$row['status'],'settings'=>$settings,'css'=>$includeUnsafeCode ? (string)($row['css'] ?? '') : '','js'=>$includeUnsafeCode ? (string)($row['js'] ?? '') : '','fields'=>$exported]];
}

function fb_definition_text(mixed $value, int $max, string $error, bool $allowEmpty = true): string {
    if (!is_string($value) || (!$allowEmpty && trim($value) === '') || mb_strlen($value) > $max || str_contains($value, "\0")) throw new InvalidArgumentException($error);
    return $value;
}

function fb_definition_decode(string|array $definition): array {
    if (is_string($definition)) {
        if ($definition === '' || strlen($definition) > FB_DEFINITION_MAX_BYTES) throw new InvalidArgumentException('Definition size is invalid.');
        $definition = json_decode($definition, true, 64, JSON_THROW_ON_ERROR);
    }
    if (!is_array($definition) || !isset($definition['form']) || ($definition['schema'] ?? null) !== FB_DEFINITION_SCHEMA || array_diff(array_keys($definition), ['schema','definition_id','form']) !== []) throw new InvalidArgumentException('Unsupported form definition.');
    if (isset($definition['definition_id']) && (!is_string($definition['definition_id']) || preg_match('/\A[a-f0-9]{64}\z/', $definition['definition_id']) !== 1)) throw new InvalidArgumentException('Invalid definition id.');
    $form = $definition['form'];
    if (!is_array($form) || array_diff(array_keys($form), ['slug','title','description','status','settings','css','js','fields']) !== []) throw new InvalidArgumentException('Invalid form contract.');
    $slug = fb_definition_text($form['slug'] ?? null, 80, 'Invalid form slug.', false);
    if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,79}\z/', $slug) !== 1) throw new InvalidArgumentException('Invalid form slug.');
    fb_definition_text($form['title'] ?? null, 255, 'Invalid form title.', false);
    fb_definition_text($form['description'] ?? '', 10000, 'Invalid form description.');
    fb_definition_text($form['css'] ?? '', 100000, 'Invalid form CSS.');
    fb_definition_text($form['js'] ?? '', 100000, 'Invalid form JavaScript.');
    if (!in_array($form['status'] ?? null, ['draft','active','archived'], true)) throw new InvalidArgumentException('Invalid form status.');
    $fields = $form['fields'] ?? null;
    if (!is_array($fields) || !array_is_list($fields) || count($fields) > 300) throw new InvalidArgumentException('Invalid field list.');

    $types = fb_field_types(); $byKey = []; $optionValues = [];
    foreach ($fields as $field) {
        $allowedKeys = ['key','parent','type','label','placeholder','help','required','width','order','hidden','options','validation','settings'];
        if (!is_array($field) || array_diff(array_keys($field), $allowedKeys) !== []) throw new InvalidArgumentException('Invalid field contract.');
        $key = fb_definition_text($field['key'] ?? null, 80, 'Invalid field key.', false);
        if (preg_match('/\A[a-z0-9][a-z0-9_]{0,79}\z/', $key) !== 1 || isset($byKey[$key])) throw new InvalidArgumentException('Invalid or duplicate field key.');
        $type = $field['type'] ?? null;
        if (!is_string($type) || !isset($types[$type])) throw new InvalidArgumentException('Invalid field type.');
        fb_definition_text($field['label'] ?? '', 1000, 'Invalid field label.');
        fb_definition_text($field['placeholder'] ?? '', 1000, 'Invalid field placeholder.');
        fb_definition_text($field['help'] ?? '', 2000, 'Invalid field help.');
        foreach (['required','hidden'] as $boolKey) if (isset($field[$boolKey]) && !is_bool($field[$boolKey])) throw new InvalidArgumentException('Invalid field boolean.');
        foreach (['width','order'] as $intKey) if (isset($field[$intKey]) && !is_int($field[$intKey])) throw new InvalidArgumentException('Invalid field integer.');
        if (isset($field['width']) && ($field['width'] < 1 || $field['width'] > 12)) throw new InvalidArgumentException('Invalid field width.');
        if (isset($field['order']) && (abs($field['order']) > 1000000)) throw new InvalidArgumentException('Invalid field order.');
        $options = $field['options'] ?? [];
        if (!is_array($options) || !array_is_list($options) || count($options) > 200 || ($options !== [] && empty($types[$type]['options']))) throw new InvalidArgumentException('Invalid field options.');
        $values = [];
        foreach ($options as $option) {
            if (!is_array($option) || array_diff(array_keys($option), ['value','label','price']) !== [] || !array_key_exists('value', $option) || !array_key_exists('label', $option)) throw new InvalidArgumentException('Invalid option contract.');
            $value = fb_definition_text($option['value'], 200, 'Invalid option value.', false);
            fb_definition_text($option['label'], 500, 'Invalid option label.', false);
            if (preg_match('/[\x00-\x1F\x7F]/', $value) || isset($values[$value]) || (isset($option['price']) && (!is_int($option['price']) || abs($option['price']) > 1000000000000))) throw new InvalidArgumentException('Invalid option value or price.');
            $values[$value] = true;
        }
        $optionValues[$key] = $values;
        $byKey[$key] = $field;
    }

    $allowedValidation = ['min','max','maxlength','pattern','after_field','before_field','max_bytes','exts','max_bytes_by_ext'];
    $allowedExtensions = ['jpg','jpeg','png','webp','pdf'];
    foreach ($fields as $field) {
        $key = $field['key']; $type = $field['type']; $parent = $field['parent'] ?? null;
        if ($parent !== null && (!is_string($parent) || !isset($byKey[$parent]))) throw new InvalidArgumentException('Unknown field parent.');
        if ($type === 'row' && $parent !== null) throw new InvalidArgumentException('Rows must be top-level.');
        if ($type === 'col' && ($parent === null || $byKey[$parent]['type'] !== 'row')) throw new InvalidArgumentException('Columns must belong to rows.');
        if (!in_array($type, ['row','col'], true) && ($parent === null || $byKey[$parent]['type'] !== 'col')) throw new InvalidArgumentException('Fields must belong to columns.');
        $validation = $field['validation'] ?? [];
        if (!is_array($validation) || array_is_list($validation) && $validation !== [] || array_diff(array_keys($validation), $allowedValidation) !== []) throw new InvalidArgumentException('Invalid validation contract.');
        foreach (['min','max'] as $number) if (isset($validation[$number])) {
            if (!in_array($type, ['number','date'], true)) throw new InvalidArgumentException('Range used on an unsupported field.');
            if ($type === 'number' && (!is_int($validation[$number]) && !is_float($validation[$number]) && (!is_string($validation[$number]) || !is_numeric($validation[$number])))) throw new InvalidArgumentException('Invalid numeric range.');
            if ($type === 'date') {
                $date = is_string($validation[$number]) ? DateTimeImmutable::createFromFormat('!Y-m-d', $validation[$number]) : false;
                if ($date === false || $date->format('Y-m-d') !== $validation[$number]) throw new InvalidArgumentException('Invalid date range.');
            }
        }
        if (isset($validation['maxlength']) && (!is_int($validation['maxlength']) || $validation['maxlength'] < 1 || $validation['maxlength'] > 65536)) throw new InvalidArgumentException('Invalid maxlength.');
        if (isset($validation['maxlength']) && !in_array($type, ['text','email','tel','intl_phone','textarea'], true)) throw new InvalidArgumentException('Maxlength used on an unsupported field.');
        if (isset($validation['pattern'])) fb_definition_text($validation['pattern'], 500, 'Invalid pattern.', false);
        if (isset($validation['pattern']) && !in_array($type, ['text','email','tel','intl_phone','textarea'], true)) throw new InvalidArgumentException('Pattern used on an unsupported field.');
        foreach (['after_field','before_field'] as $dateRule) if (isset($validation[$dateRule]) && (!is_string($validation[$dateRule]) || $type !== 'date' || $validation[$dateRule] === $key || !isset($byKey[$validation[$dateRule]]) || $byKey[$validation[$dateRule]]['type'] !== 'date')) throw new InvalidArgumentException('Invalid date reference.');
        $isFile = !empty($types[$type]['file']);
        foreach (['max_bytes','exts','max_bytes_by_ext'] as $fileRule) if (isset($validation[$fileRule]) && !$isFile) throw new InvalidArgumentException('File validation used on a non-file field.');
        if (isset($validation['max_bytes']) && (!is_int($validation['max_bytes']) || $validation['max_bytes'] < 1 || $validation['max_bytes'] > 25 * 1024 * 1024)) throw new InvalidArgumentException('Invalid file size.');
        if (isset($validation['exts'])) {
            if (!is_array($validation['exts']) || !array_is_list($validation['exts']) || $validation['exts'] === [] || count($validation['exts']) > 10 || array_values(array_unique($validation['exts'])) !== $validation['exts']) throw new InvalidArgumentException('Invalid extension list.');
            foreach ($validation['exts'] as $extension) if (!is_string($extension) || !in_array($extension, $allowedExtensions, true) || ($type === 'image' && $extension === 'pdf')) throw new InvalidArgumentException('Unsupported extension.');
        }
        if (isset($validation['max_bytes_by_ext'])) {
            if (!is_array($validation['max_bytes_by_ext']) || array_is_list($validation['max_bytes_by_ext'])) throw new InvalidArgumentException('Invalid per-extension limits.');
            foreach ($validation['max_bytes_by_ext'] as $extension => $bytes) if (!in_array($extension, $validation['exts'] ?? [], true) || !is_int($bytes) || $bytes < 1 || $bytes > ($validation['max_bytes'] ?? 25 * 1024 * 1024)) throw new InvalidArgumentException('Invalid per-extension limit.');
        }
        $fieldSettings = $field['settings'] ?? [];
        if (!is_array($fieldSettings) || array_is_list($fieldSettings) && $fieldSettings !== [] || array_diff(array_keys($fieldSettings), ['align','valign','level','html','url','alt','caption','width','country_field']) !== []) throw new InvalidArgumentException('Invalid field settings.');
        foreach ($fieldSettings as $settingKey => $settingValue) if (!is_string($settingValue) || mb_strlen($settingValue) > ($settingKey === 'html' ? 100000 : 2000)) throw new InvalidArgumentException('Invalid field setting.');
        if (isset($fieldSettings['align']) && !in_array($fieldSettings['align'], ['center','right'], true)) throw new InvalidArgumentException('Invalid alignment.');
        if (isset($fieldSettings['valign']) && !in_array($fieldSettings['valign'], ['middle','bottom'], true)) throw new InvalidArgumentException('Invalid vertical alignment.');
        if (isset($fieldSettings['level']) && ($type !== 'heading' || !in_array($fieldSettings['level'], FB_HEADING_LEVELS, true))) throw new InvalidArgumentException('Invalid heading level.');
        if (isset($fieldSettings['html']) && !in_array($type, ['richtext','raw_html'], true)) throw new InvalidArgumentException('Invalid HTML setting.');
        foreach (['url','alt','caption','width'] as $imageSetting) if (isset($fieldSettings[$imageSetting]) && $type !== 'image_block') throw new InvalidArgumentException('Invalid image setting.');
        if ($type === 'intl_phone') {
            $countryField = $fieldSettings['country_field'] ?? null;
            if (!is_string($countryField) || $countryField === $key || !isset($byKey[$countryField]) || $byKey[$countryField]['type'] !== 'country' || !empty($byKey[$countryField]['hidden']) || (!empty($field['required']) && empty($byKey[$countryField]['required']))) throw new InvalidArgumentException('Invalid country field reference.');
        } elseif (isset($fieldSettings['country_field'])) throw new InvalidArgumentException('Invalid country field setting.');
        if (isset($fieldSettings['width']) && !in_array($fieldSettings['width'], ['25','50','75'], true)) throw new InvalidArgumentException('Invalid image width.');
        if (isset($fieldSettings['url']) && $fieldSettings['url'] !== '' && $fieldSettings['url'][0] !== '/' && preg_match('#\Ahttps?://#i', $fieldSettings['url']) !== 1) throw new InvalidArgumentException('Invalid image URL.');
    }

    $settings = $form['settings'] ?? [];
    if (!is_array($settings) || array_diff(array_keys($settings), array_keys(fb_default_settings())) !== []) throw new InvalidArgumentException('Invalid form settings.');
    foreach (['submit_label','success_message','recaptcha','notify_email','show_total','total_label','accent','currency_code','confirmation_email_field','reply_to_email_field'] as $key) if (isset($settings[$key])) fb_definition_text($settings[$key], 4000, 'Invalid form setting.');
    foreach (['rate_max','rate_window','min_fill_seconds'] as $key) if (isset($settings[$key]) && !is_int($settings[$key])) throw new InvalidArgumentException('Invalid numeric setting.');
    if (isset($settings['rate_max']) && ($settings['rate_max'] < 1 || $settings['rate_max'] > 10000)) throw new InvalidArgumentException('Invalid rate maximum.');
    if (isset($settings['rate_window']) && ($settings['rate_window'] < 60 || $settings['rate_window'] > 604800)) throw new InvalidArgumentException('Invalid rate window.');
    if (isset($settings['min_fill_seconds']) && ($settings['min_fill_seconds'] < 0 || $settings['min_fill_seconds'] > 30)) throw new InvalidArgumentException('Invalid minimum fill time.');
    foreach (['recaptcha','show_total'] as $toggle) if (isset($settings[$toggle]) && !in_array($settings[$toggle], ['0','1'], true)) throw new InvalidArgumentException('Invalid toggle setting.');
    if (($settings['notify_email'] ?? '') !== '' && filter_var($settings['notify_email'], FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('Invalid notification email.');
    if (isset($settings['accent']) && !isset(fb_accent_presets()[$settings['accent']])) throw new InvalidArgumentException('Invalid accent setting.');
    if (isset($settings['currency_code']) && preg_match('/\A[A-Z]{3}\z/', $settings['currency_code']) !== 1) throw new InvalidArgumentException('Invalid currency code.');
    if (isset($settings['unsafe_code_enabled']) && !is_bool($settings['unsafe_code_enabled'])) throw new InvalidArgumentException('Invalid unsafe-code setting.');
    $statuses = $settings['workflow_statuses'] ?? fb_default_settings()['workflow_statuses'];
    if (!is_array($statuses) || !array_is_list($statuses) || count($statuses) < 1 || count($statuses) > 20 || array_values(array_unique($statuses)) !== $statuses || !in_array('submitted', $statuses, true)) throw new InvalidArgumentException('Invalid workflow statuses.');
    foreach ($statuses as $status) if (!is_string($status) || preg_match('/\A[a-z][a-z0-9_-]{0,39}\z/', $status) !== 1) throw new InvalidArgumentException('Invalid workflow status.');
    foreach (['confirmation_email_field','reply_to_email_field'] as $emailKey) if (($settings[$emailKey] ?? '') !== '' && (!isset($byKey[$settings[$emailKey]]) || $byKey[$settings[$emailKey]]['type'] !== 'email')) throw new InvalidArgumentException('Invalid email field reference.');
    $columns = $settings['columns'] ?? [];
    if (!is_array($columns) || !array_is_list($columns) || count($columns) > 20 || array_values(array_unique($columns)) !== $columns) throw new InvalidArgumentException('Invalid column list.');
    foreach ($columns as $column) if (!is_string($column) || !isset($byKey[$column]) || empty($types[$byKey[$column]['type']]['input'])) throw new InvalidArgumentException('Invalid submission column.');

    $translations = $settings['translations'] ?? [];
    if (!is_array($translations) || count($translations) > 50) throw new InvalidArgumentException('Invalid locales.');
    foreach (array_keys($translations) as $locale) if (!is_string($locale) || fb_normalize_locale($locale) !== $locale) throw new InvalidArgumentException('Invalid locale.');
    $messageKeys = array_keys(fb_public_message_defaults('en'));
    $emailKeys = ['admin_subject','admin_body','applicant_subject','applicant_body'];
    foreach ($translations as $locale => $translation) {
        if (!is_array($translation) || array_diff(array_keys($translation), ['form','fields','messages','email']) !== []) throw new InvalidArgumentException('Invalid translation contract.');
        $formText = $translation['form'] ?? [];
        if (!is_array($formText) || array_diff(array_keys($formText), ['title','description','submit_label','success_message']) !== []) throw new InvalidArgumentException('Invalid form translation.');
        foreach ($formText as $text) fb_definition_text($text, 4000, 'Invalid translated text.');
        $messages = $translation['messages'] ?? [];
        if (!is_array($messages) || array_diff(array_keys($messages), $messageKeys) !== []) throw new InvalidArgumentException('Invalid public messages.');
        foreach ($messages as $text) fb_definition_text($text, 2000, 'Invalid public message.', false);
        $email = $translation['email'] ?? [];
        if (!is_array($email) || array_diff(array_keys($email), $emailKeys) !== []) throw new InvalidArgumentException('Invalid email translation.');
        foreach ($email as $key => $text) {
            fb_definition_text($text, str_ends_with($key, '_body') ? 20000 : 4000, 'Invalid email text.', false);
            if (str_ends_with($key, '_subject') && preg_match('/[\x00-\x1F\x7F]/', $text)) throw new InvalidArgumentException('Invalid email subject.');
        }
        $fieldTexts = $translation['fields'] ?? [];
        if (!is_array($fieldTexts) || count($fieldTexts) > 300) throw new InvalidArgumentException('Invalid field translations.');
        foreach ($fieldTexts as $fieldKey => $fieldText) {
            if (!isset($byKey[$fieldKey]) || !is_array($fieldText) || array_diff(array_keys($fieldText), ['label','placeholder','help_text','options']) !== []) throw new InvalidArgumentException('Invalid field translation.');
            foreach (['label','placeholder','help_text'] as $textKey) if (isset($fieldText[$textKey])) fb_definition_text($fieldText[$textKey], 2000, 'Invalid translated field text.');
            $translatedOptions = $fieldText['options'] ?? [];
            if (!is_array($translatedOptions) || count($translatedOptions) > 200) throw new InvalidArgumentException('Invalid option translations.');
            foreach ($translatedOptions as $value => $label) if (!isset($optionValues[$fieldKey][$value])) throw new InvalidArgumentException('Unknown translated option.'); else fb_definition_text($label, 500, 'Invalid translated option.', false);
        }
    }
    if (strlen(fb_json_encode($definition)) > FB_DEFINITION_MAX_BYTES) throw new InvalidArgumentException('Definition size is invalid.');
    return $definition;
}

function fb_upsert_form_definition(PDO $pdo, string|array $input, ?int $actorId = null, bool $allowUnsafeCode = false): array {
    $definition = fb_definition_decode($input); $form = $definition['form'];
    $settings = array_merge(fb_default_settings(), fb_definition_safe_settings($form['settings'] ?? []));
    $hasUnsafeCode = !empty($form['css']) || !empty($form['js']);
    foreach ($form['fields'] as $candidate) if (in_array($candidate['type'], ['richtext','raw_html'], true) && !empty($candidate['settings']['html'])) { $hasUnsafeCode = true; break; }
    $settings['unsafe_code_enabled'] = $allowUnsafeCode && $hasUnsafeCode;
    $hash = hash('sha256', fb_json_encode($definition));
    $definitionId = (string)($definition['definition_id'] ?? hash('sha256', 'form-builder:' . $form['slug']));
    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT * FROM fb_forms WHERE slug = ? FOR UPDATE'); $existing->execute([$form['slug']]); $row = $existing->fetch(PDO::FETCH_ASSOC);
        $css = $allowUnsafeCode && $form['css'] !== '' ? $form['css'] : null; $js = $allowUnsafeCode && $form['js'] !== '' ? $form['js'] : null;
        if ($row) {
            $formId = (int)$row['id'];
            $pdo->prepare('UPDATE fb_forms SET title=?,description=?,status=?,settings_json=?,css=?,js=?,updated_at=NOW() WHERE id=?')->execute([trim($form['title']),trim($form['description']) ?: null,$form['status'],fb_json_encode($settings),$css,$js,$formId]);
            $pdo->prepare('DELETE FROM fb_fields WHERE form_id = ?')->execute([$formId]);
        } else {
            $pdo->prepare('INSERT INTO fb_forms (slug,title,description,status,settings_json,css,js,access_json,created_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$form['slug'],trim($form['title']),trim($form['description']) ?: null,$form['status'],fb_json_encode($settings),$css,$js,fb_json_encode(['roles'=>[],'users'=>[],'owner'=>$actorId ?? 0,'submissions'=>['roles'=>[],'users'=>[]]]),$actorId]);
            $formId = (int)$pdo->lastInsertId();
        }
        $ids = []; $pending = $form['fields'];
        $insert = $pdo->prepare('INSERT INTO fb_fields (form_id,parent_id,type,label,field_key,placeholder,help_text,required,width,sort_order,is_hidden,options_json,validation_json,settings_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $index => $field) {
                $parent = $field['parent'] ?? null; if ($parent !== null && !isset($ids[$parent])) continue;
                $fieldSettings = $field['settings'] ?? []; if (!$allowUnsafeCode && in_array($field['type'], ['richtext','raw_html'], true)) unset($fieldSettings['html']);
                $insert->execute([$formId,$parent === null ? 0 : $ids[$parent],$field['type'],$field['label'],$field['key'],($field['placeholder'] ?? '') ?: null,($field['help'] ?? '') ?: null,!empty($field['required']) ? 1 : 0,$field['width'] ?? 12,$field['order'] ?? 0,!empty($field['hidden']) ? 1 : 0,($field['options'] ?? []) ? fb_json_encode($field['options']) : null,($field['validation'] ?? []) ? fb_json_encode($field['validation']) : null,$fieldSettings ? fb_json_encode($fieldSettings) : null]);
                $ids[$field['key']] = (int)$pdo->lastInsertId(); unset($pending[$index]); $progress = true;
            }
            if (!$progress) throw new InvalidArgumentException('Cyclic field layout.');
        }
        $pdo->prepare('INSERT INTO fb_import_ledger (definition_id,form_slug,schema_version,definition_sha256,form_id,imported_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE definition_sha256=VALUES(definition_sha256),form_id=VALUES(form_id),imported_by=VALUES(imported_by),created_at=NOW()')->execute([$definitionId,$form['slug'],FB_DEFINITION_SCHEMA,$hash,$formId,$actorId]);
        $pdo->commit(); return ['form_id'=>$formId,'slug'=>$form['slug'],'sha256'=>$hash];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function fb_normalize_locale(string $locale): ?string {
    $locale = strtolower(str_replace('_', '-', trim($locale)));
    return preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3}\z/', $locale) === 1 ? $locale : null;
}

function fb_locale(): string {
    $locale = function_exists('get_locale') ? (string)get_locale() : (string)($GLOBALS['__APP_LOCALE'] ?? $GLOBALS['locale'] ?? 'en');
    return fb_normalize_locale($locale) ?? 'en';
}

function fb_translation_overlay(array $settings, string $locale): array {
    $translations = is_array($settings['translations'] ?? null) ? $settings['translations'] : [];
    $base = explode('-', $locale, 2)[0];
    $baseTranslation = is_array($translations[$base] ?? null) ? $translations[$base] : [];
    $exactTranslation = is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
    return array_replace_recursive($baseTranslation, $exactTranslation);
}

function fb_localized_form(array $form, array $settings): array {
    $translation = fb_translation_overlay($settings, fb_locale())['form'] ?? [];
    if (is_array($translation)) { foreach (['title','description'] as $key) if (is_string($translation[$key] ?? null)) $form[$key] = $translation[$key]; foreach (['submit_label','success_message'] as $key) if (is_string($translation[$key] ?? null)) $settings[$key] = $translation[$key]; }
    if (!is_string($translation['submit_label'] ?? null) && $settings['submit_label'] === fb_default_settings()['submit_label']) $settings['submit_label'] = fb_message($settings, 'default_submit');
    if (!is_string($translation['success_message'] ?? null) && $settings['success_message'] === fb_default_settings()['success_message']) $settings['success_message'] = fb_message($settings, 'default_success');
    return [$form,$settings];
}

function fb_localized_field(array $field, array $settings): array {
    $translation = fb_translation_overlay($settings, fb_locale())['fields'][$field['field_key']] ?? [];
    if (!is_array($translation)) return $field;
    foreach (['label','placeholder','help_text'] as $key) if (is_string($translation[$key] ?? null)) $field[$key] = $translation[$key];
    if (is_array($translation['options'] ?? null)) { $options = fb_field_options($field); foreach ($options as &$option) if (is_string($translation['options'][$option['value']] ?? null)) $option['label'] = $translation['options'][$option['value']]; unset($option); $field['options_json'] = fb_json_encode($options); }
    return $field;
}
