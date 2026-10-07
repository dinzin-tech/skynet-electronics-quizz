<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_07_000011_add_feedback_question_to_quizzes_table extends MigrationBase
{
    public function up(): void
    {
        $this->exec("ALTER TABLE quizzes ADD COLUMN feedback_question TEXT NULL AFTER instructions;");
    }

    public function down(): void
    {
        $this->exec("ALTER TABLE quizzes DROP COLUMN feedback_question;");
    }
}
