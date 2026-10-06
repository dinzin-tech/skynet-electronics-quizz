<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_07_000009_add_zone_dept_designation_to_employees_table extends MigrationBase
{
    public function up(): void
    {
        $this->exec("ALTER TABLE employees " .
            "ADD COLUMN zone_region VARCHAR(100) NULL AFTER employee_code, " .
            "ADD COLUMN department VARCHAR(100) NULL AFTER name, " .
            "ADD COLUMN designation VARCHAR(150) NULL AFTER department, " .
            "ADD KEY idx_employees_department (department), " .
            "ADD KEY idx_employees_zone_region (zone_region);"
        );
    }

    public function down(): void
    {
        $this->exec("ALTER TABLE employees " .
            "DROP INDEX idx_employees_zone_region, " .
            "DROP INDEX idx_employees_department, " .
            "DROP COLUMN designation, " .
            "DROP COLUMN department, " .
            "DROP COLUMN zone_region;"
        );
    }
}
