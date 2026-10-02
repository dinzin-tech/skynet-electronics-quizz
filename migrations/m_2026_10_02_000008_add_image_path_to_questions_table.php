<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_02_000008_add_image_path_to_questions_table extends MigrationBase
{
    public function up(): void
    {
        $this->exec("ALTER TABLE questions ADD COLUMN image_path VARCHAR(255) NULL AFTER question_text;");
    }

    public function down(): void
    {
        $this->exec("ALTER TABLE questions DROP COLUMN image_path;");
    }
}
