<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $index = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fb_submissions' AND INDEX_NAME = 'idx_fb_submission_capacity'");
    $index->execute();
    if ((int)$index->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE `fb_submissions` ADD KEY `idx_fb_submission_capacity` (`form_id`,`is_deleted`)');
    }
};
