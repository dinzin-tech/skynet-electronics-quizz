<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_12_000013_add_question_type_and_text_answer extends MigrationBase
{
    public function up(): void
    {
        $this->exec("ALTER TABLE questions ADD COLUMN type ENUM('mcq', 'written') NOT NULL DEFAULT 'mcq' AFTER question_text;");
        $this->exec("ALTER TABLE attempt_answers ADD COLUMN text_answer TEXT NULL AFTER selected_option_id;");
    }

    public function down(): void
    {
        $this->exec("ALTER TABLE attempt_answers DROP COLUMN text_answer;");
        $this->exec("ALTER TABLE questions DROP COLUMN type;");
    }
}
