<?php

declare(strict_types=1);

namespace ReportLog\Report;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly ReportRepository $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->post('/reports', $this->handleCreate(...));
        $this->router->get('/reports', $this->handleList(...));
        $this->router->get('/reports/{id}', $this->handleGet(...));
        $this->router->put('/reports/{id}/resolve', $this->handleResolve(...));
        $this->router->put('/reports/{id}/dismiss', $this->handleDismiss(...));
    }

    private function requireUserId(ServerRequestInterface $request): int
    {
        return (int) $request->getHeaderLine('X-User-Id');
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id required'], 401);
        }

        $actor = $this->repository->findUserById($actorId);
        if ($actor === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];

        $articleId = isset($body['article_id']) && is_int($body['article_id']) ? $body['article_id'] : null;
        $reasonStr = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : null;
        $details = isset($body['details']) && is_string($body['details']) ? $body['details'] : null;

        if ($articleId === null || $reasonStr === null) {
            return $this->responseFactory->create(['error' => 'article_id and reason are required'], 422);
        }

        $reason = ReportReason::tryFrom($reasonStr);
        if ($reason === null) {
            $validReasons = array_map(fn (ReportReason $r) => $r->value, ReportReason::cases());
            return $this->responseFactory->create(['error' => 'invalid reason', 'valid_reasons' => $validReasons], 422);
        }

        $article = $this->repository->findArticleById($articleId);
        if ($article === null) {
            return $this->responseFactory->create(['error' => 'article not found'], 404);
        }

        $existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
        if ($existing !== null) {
            return $this->responseFactory->create($this->formatReport($existing), 200);
        }

        $id = $this->repository->createReport($actorId, $articleId, $reason, $details, date('c'));
        $report = $this->repository->findReportById($id);

        return $this->responseFactory->create($this->formatReport($report ?? []), 201);
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id required'], 401);
        }

        $actor = $this->repository->findUserById($actorId);
        if ($actor === null || $actor['role'] !== 'moderator') {
            return $this->responseFactory->create(['error' => 'moderator role required'], 403);
        }

        $reports = $this->repository->listReports();
        $formatted = array_map(fn (array $r) => $this->formatReport($r), $reports);
        return $this->responseFactory->create(['reports' => $formatted, 'count' => count($formatted)], 200);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id required'], 401);
        }

        $id = (int) Router::param($request, 'id');
        $report = $this->repository->findReportById($id);
        if ($report === null) {
            return $this->responseFactory->create(['error' => 'report not found'], 404);
        }

        $actor = $this->repository->findUserById($actorId);
        $isModerator = $actor !== null && $actor['role'] === 'moderator';
        $isReporter = (int) $report['reporter_id'] === $actorId;

        if (!$isModerator && !$isReporter) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        return $this->responseFactory->create($this->formatReport($report), 200);
    }

    private function handleResolve(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleStatusChange($request, 'resolved');
    }

    private function handleDismiss(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleStatusChange($request, 'dismissed');
    }

    private function handleStatusChange(ServerRequestInterface $request, string $newStatus): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id required'], 401);
        }

        $actor = $this->repository->findUserById($actorId);
        if ($actor === null || $actor['role'] !== 'moderator') {
            return $this->responseFactory->create(['error' => 'moderator role required'], 403);
        }

        $id = (int) Router::param($request, 'id');
        $report = $this->repository->findReportById($id);
        if ($report === null) {
            return $this->responseFactory->create(['error' => 'report not found'], 404);
        }

        if ($report['status'] !== 'pending') {
            return $this->responseFactory->create(['error' => 'report is not pending', 'current_status' => $report['status']], 422);
        }

        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];
        $note = isset($body['resolution_note']) && is_string($body['resolution_note']) ? $body['resolution_note'] : null;

        $this->repository->updateReportStatus($id, $newStatus, $actorId, date('c'), $note);
        $updated = $this->repository->findReportById($id);

        return $this->responseFactory->create($this->formatReport($updated ?? []), 200);
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    private function formatReport(array $report): array
    {
        return [
            'id' => (int) ($report['id'] ?? 0),
            'reporter_id' => (int) ($report['reporter_id'] ?? 0),
            'article_id' => (int) ($report['article_id'] ?? 0),
            'reason' => (string) ($report['reason'] ?? ''),
            'details' => isset($report['details']) ? (string) $report['details'] : null,
            'status' => (string) ($report['status'] ?? ''),
            'resolved_by' => isset($report['resolved_by']) ? (int) $report['resolved_by'] : null,
            'resolved_at' => isset($report['resolved_at']) ? (string) $report['resolved_at'] : null,
            'resolution_note' => isset($report['resolution_note']) ? (string) $report['resolution_note'] : null,
            'created_at' => (string) ($report['created_at'] ?? ''),
        ];
    }
}
