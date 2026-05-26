<?php

declare(strict_types=1);

namespace Circuit\Circuit;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class CircuitBreakerRepository
{
    private const int DEFAULT_TIMEOUT_SECONDS = 30;

    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function findOrCreate(string $name, int $failureThreshold, string $now): CircuitRecord
    {
        $existing = $this->findByName($name);
        if ($existing !== null) {
            return $existing;
        }

        $this->executor->execute(
            'INSERT INTO circuits (name, state, failure_count, failure_threshold, updated_at) VALUES (?, ?, 0, ?, ?)',
            [$name, CircuitState::Closed->value, $failureThreshold, $now],
        );

        $id = (int) $this->executor->lastInsertId();

        return new CircuitRecord(
            $id,
            $name,
            CircuitState::Closed,
            0,
            $failureThreshold,
            null,
            null,
            null,
            $now,
        );
    }

    public function findByName(string $name): ?CircuitRecord
    {
        $rows = $this->executor->fetchAll('SELECT * FROM circuits WHERE name = ?', [$name]);
        if ($rows === []) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    /**
     * Record a successful call.
     * - Half-Open success: reset to Closed, clear failure_count.
     * - Closed success: no state change (idempotent).
     */
    public function recordSuccess(string $name, string $now): CircuitRecord
    {
        $circuit = $this->findByName($name);
        if ($circuit === null) {
            return $this->findOrCreate($name, 5, $now);
        }

        if ($circuit->state === CircuitState::HalfOpen || $circuit->failureCount > 0) {
            $this->executor->execute(
                "UPDATE circuits SET state = 'closed', failure_count = 0, open_until = NULL, half_open_at = NULL, updated_at = ? WHERE name = ?",
                [$now, $name],
            );
        }

        return $this->findByName($name) ?? $circuit;
    }

    /**
     * Record a failed call.
     * - failure_count < threshold: increment failure_count (stay Closed or HalfOpen).
     * - failure_count >= threshold: transition to Open, set open_until = now + timeout.
     * - Half-Open failure immediately reopens the circuit.
     */
    public function recordFailure(string $name, string $now, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): CircuitRecord
    {
        $circuit = $this->findByName($name);
        if ($circuit === null) {
            $circuit = $this->findOrCreate($name, 5, $now);
        }

        $newCount = $circuit->failureCount + 1;

        if ($circuit->state === CircuitState::HalfOpen || $newCount >= $circuit->failureThreshold) {
            $openUntil = (new \DateTimeImmutable($now))
                ->add(new \DateInterval("PT{$timeoutSeconds}S"))
                ->format('Y-m-d H:i:s');

            $this->executor->execute(
                "UPDATE circuits SET state = 'open', failure_count = ?, open_until = ?, last_failure_at = ?, updated_at = ? WHERE name = ?",
                [$newCount, $openUntil, $now, $now, $name],
            );
        } else {
            $this->executor->execute(
                'UPDATE circuits SET failure_count = ?, last_failure_at = ?, updated_at = ? WHERE name = ?',
                [$newCount, $now, $now, $name],
            );
        }

        return $this->findByName($name) ?? $circuit;
    }

    /**
     * Transition Open → Half-Open when the timeout has elapsed.
     * Call before deciding whether to allow a probe request through.
     */
    public function maybeTransitionToHalfOpen(string $name, string $now): CircuitRecord
    {
        $circuit = $this->findByName($name);
        if ($circuit === null) {
            return $this->findOrCreate($name, 5, $now);
        }

        if ($circuit->state === CircuitState::Open && $now >= ($circuit->openUntil ?? '')) {
            $this->executor->execute(
                "UPDATE circuits SET state = 'half_open', half_open_at = ?, updated_at = ? WHERE name = ?",
                [$now, $now, $name],
            );

            return $this->findByName($name) ?? $circuit;
        }

        return $circuit;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CircuitRecord
    {
        return new CircuitRecord(
            (int) $row['id'],
            (string) $row['name'],
            CircuitState::from((string) $row['state']),
            (int) $row['failure_count'],
            (int) $row['failure_threshold'],
            isset($row['open_until']) ? (string) $row['open_until'] : null,
            isset($row['half_open_at']) ? (string) $row['half_open_at'] : null,
            isset($row['last_failure_at']) ? (string) $row['last_failure_at'] : null,
            (string) $row['updated_at'],
        );
    }
}
