<?php

declare(strict_types=1);

namespace TimeLog\Timer;

use Nene2\Database\DatabaseQueryExecutorInterface;

class TimerRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function findRunning(): ?TimeEntry
    {
        // WHERE end_time IS NULL — standard NULL comparison. LIMIT 1 guards the invariant.
        $row = $this->db->fetchOne(
            'SELECT * FROM time_entries WHERE end_time IS NULL ORDER BY start_time DESC LIMIT 1',
            [],
        );
        return $row !== null ? TimeEntry::fromRow($row) : null;
    }

    public function findById(int $id): ?TimeEntry
    {
        $row = $this->db->fetchOne('SELECT * FROM time_entries WHERE id = ?', [$id]);
        return $row !== null ? TimeEntry::fromRow($row) : null;
    }

    public function start(string $label, string $startTime, string $createdAt): TimeEntry
    {
        $running = $this->findRunning();
        if ($running !== null) {
            throw new TimerAlreadyRunningException($running->id);
        }
        $id = $this->db->insert(
            'INSERT INTO time_entries (label, start_time, end_time, created_at) VALUES (?, ?, NULL, ?)',
            [$label, $startTime, $createdAt],
        );
        return TimeEntry::fromRow((array) $this->db->fetchOne('SELECT * FROM time_entries WHERE id = ?', [$id]));
    }

    public function stop(string $endTime): TimeEntry
    {
        $running = $this->findRunning();
        if ($running === null) {
            throw new NoRunningTimerException();
        }
        $this->db->execute('UPDATE time_entries SET end_time = ? WHERE id = ?', [$endTime, $running->id]);
        return TimeEntry::fromRow((array) $this->db->fetchOne('SELECT * FROM time_entries WHERE id = ?', [$running->id]));
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM time_entries WHERE id = ?', [$id]);
    }

    /**
     * @return list<TimeEntry>
     */
    public function list(?string $label, ?string $date, int $limit, int $offset): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($label !== null) {
            $where[] = 'label LIKE ?';
            $params[] = '%' . $label . '%';
        }
        if ($date !== null) {
            $where[] = 'date(start_time) = ?';
            $params[] = $date;
        }
        $params[] = $limit;
        $params[] = $offset;
        $rows = $this->db->fetchAll(
            'SELECT * FROM time_entries WHERE ' . implode(' AND ', $where)
            . ' ORDER BY start_time DESC, id DESC LIMIT ? OFFSET ?',
            $params,
        );
        return array_map(TimeEntry::fromRow(...), $rows);
    }

    /**
     * Daily totals. Running timers (end_time NULL) are excluded.
     *
     * NB: the FT246 howto computes seconds with
     * `CAST((julianday(end) - julianday(start)) * 86400 AS INTEGER)`, but the
     * julianday product is a float a hair below the whole second, so the CAST
     * truncates to N-1 (e.g. 60s → 59s). We use `strftime('%s', …)` instead, which
     * yields exact integer epoch seconds (and handles the ±offset like
     * DateTimeImmutable::getTimestamp() does on the PHP side). A howto fix is filed
     * separately.
     *
     * @return list<array{day: string, total_seconds: int, entry_count: int}>
     */
    public function summary(?string $from, ?string $to): array
    {
        $where = ['end_time IS NOT NULL'];
        $params = [];
        if ($from !== null) {
            $where[] = 'date(start_time) >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'date(start_time) <= ?';
            $params[] = $to;
        }
        $rows = $this->db->fetchAll(
            "SELECT date(start_time) AS day,
                    SUM(strftime('%s', end_time) - strftime('%s', start_time)) AS total_seconds,
                    COUNT(*) AS entry_count
             FROM time_entries
             WHERE " . implode(' AND ', $where) . '
             GROUP BY day
             ORDER BY day DESC',
            $params,
        );
        return array_map(
            static fn (array $r): array => [
                'day' => (string) $r['day'],
                'total_seconds' => (int) $r['total_seconds'],
                'entry_count' => (int) $r['entry_count'],
            ],
            $rows,
        );
    }
}
