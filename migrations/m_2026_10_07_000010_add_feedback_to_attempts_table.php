<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_07_000010_add_feedback_to_attempts_table extends MigrationBase
{
    public function up(): void
    {
        $this->exec("ALTER TABLE attempts ADD COLUMN feedback TEXT NULL AFTER completion_time_s;");
    }

    public function down(): void
    {
        $this->exec("ALTER TABLE attempts DROP COLUMN feedback;");
    }
}
