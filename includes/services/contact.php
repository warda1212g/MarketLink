<?php
/** Contact-form messages: stored from contact.php, read by admin/messages.php. */
declare(strict_types=1);

const CONTACT_TOPICS = [
    'general' => 'General question',
    'order' => 'An order / pickup',
    'farmer' => 'Joining as a farmer',
    'feedback' => 'Feedback',
];

/** Creates the table on first use (and adds is_read to tables made by the first version). */
function contact_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec("CREATE TABLE IF NOT EXISTS contact_messages (
      id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id    INT UNSIGNED NULL,
      name       VARCHAR(120) NOT NULL,
      email      VARCHAR(190) NOT NULL,
      topic      VARCHAR(20) NOT NULL,
      message    TEXT NOT NULL,
      is_read    TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_contact_created (created_at),
      KEY idx_contact_read (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!db_value("SHOW COLUMNS FROM contact_messages LIKE 'is_read'")) {
        db()->exec('ALTER TABLE contact_messages ADD is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER message, ADD KEY idx_contact_read (is_read)');
    }
    $done = true;
}

function contact_unread_count(): int
{
    contact_ensure_table();
    return (int) db_value('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0');
}
