<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fb_builder_drafts` (
      `form_id` bigint(20) unsigned NOT NULL,
      `definition_json` longtext NOT NULL,
      `revision` bigint(20) unsigned NOT NULL DEFAULT 1,
      `published_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      `created_by` bigint(20) unsigned DEFAULT NULL,
      `updated_by` bigint(20) unsigned DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`form_id`),
      KEY `idx_fb_builder_drafts_updated` (`updated_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
