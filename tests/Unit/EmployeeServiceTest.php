<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Ulid;
use App\Services\EmployeeService;
use Core\Database;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class EmployeeServiceTest extends TestCase
{
    private PDO $db;
    private EmployeeService $service;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->service = new EmployeeService($this->db);
    }

    public function test_can_create_and_find_employee_with_groups(): void
    {
        $uniqueSuffix = substr(Ulid::generate(), -6);
        $code = 'TEST_' . $uniqueSuffix;
        $email = "test_{$uniqueSuffix}@corp.local";
        $username = "user_{$uniqueSuffix}";

        // Get an existing group ID
        $groupStmt = $this->db->query('SELECT id FROM `groups` LIMIT 1');
        $groupId = (int) $groupStmt->fetchColumn();
        $groupIds = $groupId > 0 ? [$groupId] : [];

        $created = $this->service->create([
            'employee_code' => $code,
            'name' => 'Test Employee',
            'zone_region' => 'ZONE 1',
            'department' => 'KOLAR SALES',
            'designation' => 'SALES LEADER',
            'email' => $email,
            'username' => $username,
            'status' => 'active',
        ], $groupIds);

        $this->assertNotEmpty($created);
        $this->assertSame($code, $created['employee_code']);
        $this->assertSame('active', $created['status']);
        $this->assertSame('ZONE 1', $created['zone_region']);
        $this->assertSame('KOLAR SALES', $created['department']);
        $this->assertSame('SALES LEADER', $created['designation']);
        if ($groupId > 0) {
            $this->assertCount(1, $created['groups']);
            $this->assertSame($groupId, $created['groups'][0]['id']);
        }

        // Test list search finds this employee by department
        $list = $this->service->list('KOLAR SALES', null, 1, 10);
        $this->assertGreaterThanOrEqual(1, $list['total']);

        // Test update
        $updated = $this->service->update((int) $created['id'], [
            'department' => 'BANGALORE SALES',
            'designation' => 'REGIONAL MANAGER',
            'zone_region' => 'ZONE 2',
        ]);
        $this->assertSame('BANGALORE SALES', $updated['department']);
        $this->assertSame('REGIONAL MANAGER', $updated['designation']);
        $this->assertSame('ZONE 2', $updated['zone_region']);

        // Clean up
        $this->service->delete((int) $created['id']);
    }

    public function test_validates_required_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Employee code is required');
        $this->service->create(['employee_code' => '', 'name' => 'Name']);
    }

    public function test_validates_invalid_email_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format');
        $this->service->create([
            'employee_code' => 'CODE_' . substr(Ulid::generate(), -6),
            'name' => 'Test User',
            'email' => 'invalid-email-no-at-domain',
        ]);
    }

    public function test_prevents_duplicate_employee_code(): void
    {
        $code = 'DUP_' . substr(Ulid::generate(), -6);
        $this->service->create([
            'employee_code' => $code,
            'name' => 'User One',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Employee code '{$code}' already exists");

        $this->service->create([
            'employee_code' => $code,
            'name' => 'User Two',
        ]);
    }

    public function test_soft_deletes_when_attempts_exist_and_hard_deletes_otherwise(): void
    {
        $code = 'DEL_' . substr(Ulid::generate(), -6);
        $emp = $this->service->create([
            'employee_code' => $code,
            'name' => 'Delete Test User',
        ]);
        $empId = (int) $emp['id'];

        // 1. Hard delete when no attempts exist
        $deleted = $this->service->delete($empId);
        $this->assertTrue($deleted);
        $this->assertNull($this->service->getById($empId));

        // 2. Soft delete when attempts exist
        $emp2 = $this->service->create([
            'employee_code' => $code . '_2',
            'name' => 'Delete Test User 2',
        ]);
        $emp2Id = (int) $emp2['id'];

        // Get a valid quiz ID
        $quizStmt = $this->db->query('SELECT id FROM quizzes LIMIT 1');
        $quizId = (int) $quizStmt->fetchColumn();

        // Create an attempt for emp2
        $insStmt = $this->db->prepare(
            'INSERT INTO attempts (public_id, quiz_id, quiz_version, employee_id, attempt_no, status) ' .
            'VALUES (:pid, :qid, 1, :eid, 1, "NOT_STARTED")'
        );
        $insStmt->execute([
            'pid' => Ulid::generate(),
            'qid' => $quizId,
            'eid' => $emp2Id,
        ]);

        // Now delete should soft-delete to 'inactive'
        $this->service->delete($emp2Id);
        $afterDelete = $this->service->getById($emp2Id);
        $this->assertNotNull($afterDelete);
        $this->assertSame('inactive', $afterDelete['status']);

        // Clean up attempt and employee
        $this->db->prepare('DELETE FROM attempts WHERE employee_id = :eid')->execute(['eid' => $emp2Id]);
        $this->db->prepare('DELETE FROM employees WHERE id = :eid')->execute(['eid' => $emp2Id]);
    }

    public function test_bulk_delete_employees(): void
    {
        $uniqueSuffix = substr(Ulid::generate(), -4);
        $emp1 = $this->service->create([
            'employee_code' => 'BULK1_' . $uniqueSuffix,
            'name' => 'Bulk User 1',
        ]);
        $emp2 = $this->service->create([
            'employee_code' => 'BULK2_' . $uniqueSuffix,
            'name' => 'Bulk User 2',
        ]);

        $ids = [(int) $emp1['id'], (int) $emp2['id']];
        $deletedCount = $this->service->bulkDelete($ids);

        $this->assertSame(2, $deletedCount);
        $this->assertNull($this->service->getById((int) $emp1['id']));
        $this->assertNull($this->service->getById((int) $emp2['id']));
    }
}
