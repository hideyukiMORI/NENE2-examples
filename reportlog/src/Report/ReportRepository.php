<?php

declare(strict_types=1);

namespace ReportLog\Report;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ReportRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $executor,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findUserById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findArticleById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM articles WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findReportById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM reports WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findReportByReporterAndArticle(int $reporterId, int $articleId): ?array
    {
        return $this->executor->fetchOne(
            'SELECT * FROM reports WHERE reporter_id = ? AND article_id = ?',
            [$reporterId, $articleId]
        );
    }

    public function createReport(int $reporterId, int $articleId, ReportReason $reason, ?string $details, string $now): int
    {
        return $this->executor->insert(
            'INSERT INTO reports (reporter_id, article_id, reason, details, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$reporterId, $articleId, $reason->value, $details, 'pending', $now]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function listReports(): array
    {
        return $this->executor->fetchAll('SELECT * FROM reports ORDER BY created_at DESC');
    }

    public function updateReportStatus(int $id, string $status, int $resolvedBy, string $resolvedAt, ?string $note): void
    {
        $this->executor->execute(
            'UPDATE reports SET status = ?, resolved_by = ?, resolved_at = ?, resolution_note = ? WHERE id = ?',
            [$status, $resolvedBy, $resolvedAt, $note, $id]
        );
    }
}
