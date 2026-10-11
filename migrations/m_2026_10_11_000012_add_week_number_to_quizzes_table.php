<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_11_000012_add_week_number_to_quizzes_table extends MigrationBase
{
    public function up(): void
    {
        $this->exec("ALTER TABLE quizzes ADD COLUMN week_number INT UNSIGNED NULL DEFAULT 1 AFTER feedback_question;");
    }

    public function down(): void
    {
        $this->exec("ALTER TABLE quizzes DROP COLUMN week_number;");
    }
}
