<?php

declare(strict_types=1);

namespace ShiftLog\Shift;

use Nene2\Database\DatabaseTransactionManagerInterface;

/**
 * Scheduling a shift runs the overlap check and the insert inside one
 * transaction, so two concurrent requests can't both pass the check and
 * double-book the same employee (V-04 in the howto's VULN review).
 */
final class ShiftService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {
    }

    /**
     * @return array{0: string, 1: int} status ('not_found'|'overlap'|'ok') + new shift id (0 unless ok)
     */
    public function schedule(int $employeeId, string $startsAt, string $endsAt, string $location, string $now): array
    {
        /** @var array{0: string, 1: int} */
        return $this->tx->transactional(function ($executor) use ($employeeId, $startsAt, $endsAt, $location, $now): array {
            $employee = $executor->fetchOne('SELECT id FROM employees WHERE id = ?', [$employeeId]);
            if ($employee === null) {
                return ['not_found', 0];
            }
            // Any existing shift intersecting [startsAt, endsAt) for this employee.
            $overlap = $executor->fetchOne(
                'SELECT id FROM shifts WHERE employee_id = ? AND starts_at < ? AND ends_at > ?',
                [$employeeId, $endsAt, $startsAt],
            );
            if ($overlap !== null) {
                return ['overlap', 0];
            }
            $id = $executor->insert(
                'INSERT INTO shifts (employee_id, starts_at, ends_at, location, created_at) VALUES (?, ?, ?, ?, ?)',
                [$employeeId, $startsAt, $endsAt, $location, $now],
            );
            return ['ok', $id];
        });
    }
}
