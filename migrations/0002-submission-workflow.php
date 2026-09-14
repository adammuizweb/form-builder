<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database === '') throw new RuntimeException('No selected database.');
    $columnExists = static function (string $table, string $column) use ($pdo, $database): bool {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $st->execute([$database, $table, $column]);
        return $st->fetchColumn() !== false;
    };
    $indexExists = static function (string $table, string $index) use ($pdo, $database): bool {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
        $st->execute([$database, $table, $index]);
        return $st->fetchColumn() !== false;
    };
    $addColumn = static function (string $table, string $column, string $definition) use ($pdo, $columnExists): void {
        if (!$columnExists($table, $column)) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    };

    $addColumn('fb_submissions', 'reference_code', 'varchar(40) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `form_id`');
    $addColumn('fb_submissions', 'workflow_status', "varchar(40) NOT NULL DEFAULT 'submitted' AFTER `reference_code`");
    $addColumn('fb_submissions', 'notes_json', 'longtext DEFAULT NULL AFTER `workflow_status`');
    $addColumn('fb_submissions', 'history_json', 'longtext DEFAULT NULL AFTER `notes_json`');
    $addColumn('fb_submissions', 'source_json', 'longtext DEFAULT NULL AFTER `history_json`');
    $addColumn('fb_submissions', 'idempotency_key', 'char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `source_json`');
    $addColumn('fb_submissions', 'updated_at', 'datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`');

    $pdo->exec("UPDATE `fb_submissions` SET reference_code = CONCAT('FB-', form_id, '-', LPAD(id, 5, '0')) WHERE reference_code IS NULL OR reference_code = ''");
    if (!$indexExists('fb_submissions', 'uq_fb_submission_reference')) $pdo->exec('ALTER TABLE `fb_submissions` ADD UNIQUE KEY `uq_fb_submission_reference` (`form_id`,`reference_code`)');
    if (!$indexExists('fb_submissions', 'uq_fb_submission_idempotency')) $pdo->exec('ALTER TABLE `fb_submissions` ADD UNIQUE KEY `uq_fb_submission_idempotency` (`form_id`,`idempotency_key`)');
    if (!$indexExists('fb_submissions', 'idx_fb_submission_workflow')) $pdo->exec('ALTER TABLE `fb_submissions` ADD KEY `idx_fb_submission_workflow` (`form_id`,`workflow_status`,`is_deleted`,`updated_at`)');
    if (!$indexExists('fb_submissions', 'idx_fb_submission_reference')) $pdo->exec('ALTER TABLE `fb_submissions` ADD KEY `idx_fb_submission_reference` (`reference_code`)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `fb_import_ledger` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `definition_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      `form_slug` varchar(80) NOT NULL,
      `schema_version` smallint(5) unsigned NOT NULL,
      `definition_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      `form_id` bigint(20) unsigned NOT NULL,
      `imported_by` bigint(20) unsigned DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_fb_import_definition` (`definition_id`),
      KEY `idx_fb_import_slug` (`form_slug`,`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `fb_submission_imports` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `source_namespace` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      `source_key` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      `payload_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      `form_id` bigint(20) unsigned NOT NULL,
      `submission_id` bigint(20) unsigned NOT NULL,
      `reference_code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      `imported_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_fb_submission_import_source` (`source_namespace`,`source_key`),
      UNIQUE KEY `uq_fb_submission_import_submission` (`submission_id`),
      KEY `idx_fb_submission_import_form` (`form_id`,`imported_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $formIds = $pdo->query("SELECT id FROM fb_forms WHERE NOT EXISTS (SELECT 1 FROM fb_fields r WHERE r.form_id = fb_forms.id AND r.type = 'row') AND EXISTS (SELECT 1 FROM fb_fields f WHERE f.form_id = fb_forms.id AND f.parent_id = 0 AND f.deleted_at IS NULL AND f.type NOT IN ('row','col')) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($formIds as $formIdValue) {
        $formId = (int)$formIdValue;
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare("SELECT id, width FROM fb_fields WHERE form_id = ? AND parent_id = 0 AND deleted_at IS NULL AND type NOT IN ('row','col') ORDER BY sort_order, id FOR UPDATE");
            $select->execute([$formId]);
            $legacyFields = $select->fetchAll(PDO::FETCH_ASSOC);
            if ($legacyFields === []) { $pdo->commit(); continue; }

            $groups = []; $current = []; $width = 0;
            foreach ($legacyFields as $field) {
                $fieldWidth = max(1, min(12, (int)($field['width'] ?? 12) ?: 12));
                if ($current !== [] && $width + $fieldWidth > 12) { $groups[] = $current; $current = []; $width = 0; }
                $current[] = $field; $width += $fieldWidth;
                if ($width >= 12) { $groups[] = $current; $current = []; $width = 0; }
            }
            if ($current !== []) $groups[] = $current;

            $rowSort = 10;
            foreach ($groups as $group) {
                $firstId = (int)$group[0]['id'];
                $pdo->prepare("INSERT INTO fb_fields (form_id,type,label,field_key,parent_id,sort_order) VALUES (?,'row','',?,0,?)")
                    ->execute([$formId, 'row_migrated_' . $firstId, $rowSort]);
                $rowId = (int)$pdo->lastInsertId();
                $columnSort = 10;
                foreach ($group as $legacy) {
                    $legacyId = (int)$legacy['id'];
                    $pdo->prepare("INSERT INTO fb_fields (form_id,type,label,field_key,parent_id,sort_order,width) VALUES (?,'col','',?,?,?,?)")
                        ->execute([$formId, 'col_migrated_' . $legacyId, $rowId, $columnSort, max(1, min(12, (int)($legacy['width'] ?? 12) ?: 12))]);
                    $columnId = (int)$pdo->lastInsertId();
                    $move = $pdo->prepare('UPDATE fb_fields SET parent_id = ? WHERE id = ? AND form_id = ? AND parent_id = 0');
                    $move->execute([$columnId, $legacyId, $formId]);
                    if ($move->rowCount() !== 1) throw new RuntimeException('Legacy layout changed during migration.');
                    $columnSort += 10;
                }
                $rowSort += 10;
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
};
