<?php

declare(strict_types=1);

namespace ShiftLog\Shift;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ScheduleRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findEmployee(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, name, role, hourly_rate, created_at FROM employees WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listEmployees(int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, name, role, hourly_rate, created_at FROM employees ORDER BY id ASC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
    }

    public function createEmployee(string $name, string $role, int $hourlyRate, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO employees (name, role, hourly_rate, created_at) VALUES (?, ?, ?, ?)',
            [$name, $role, $hourlyRate, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function findShift(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, employee_id, starts_at, ends_at, location, created_at FROM shifts WHERE id = ?',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> */
    public function shiftsForEmployee(int $employeeId, int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, employee_id, starts_at, ends_at, location, created_at FROM shifts
             WHERE employee_id = ? ORDER BY starts_at ASC LIMIT ? OFFSET ?',
            [$employeeId, $limit, $offset],
        );
    }

    /** @return list<array<string, mixed>> shifts intersecting [$from, $to) */
    public function shiftsInWindow(string $from, string $to): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, employee_id, starts_at, ends_at, location FROM shifts
             WHERE starts_at < ? AND ends_at > ? ORDER BY starts_at ASC',
            [$to, $from],
        );
    }

    public function deleteShift(int $id): bool
    {
        return $this->db->execute('DELETE FROM shifts WHERE id = ?', [$id]) > 0;
    }
}
