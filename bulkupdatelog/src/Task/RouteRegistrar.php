<?php

declare(strict_types=1);

namespace BulkUpdateLog\Task;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int MAX_ITEMS = 100; // V-02 DoS guard

    public function __construct(
        private readonly TaskRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/tasks', $this->create(...));
        $router->get('/tasks', $this->list(...));
        $router->patch('/tasks/status', $this->bulkStatus(...));
        $router->patch('/tasks/done', $this->bulkDone(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            throw new ValidationException([new ValidationError('title', 'title must be a non-empty string', 'invalid_value')]);
        }
        $id = $this->repo->create($userId, $title, $this->now());
        return $this->json->create($this->view((array) $this->repo->findOwned($id, $userId)), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        $items = array_map($this->view(...), $this->repo->listOwned($userId));
        return $this->json->create(['tasks' => $items, 'count' => count($items)]);
    }

    /**
     * Per-item bulk update — each item carries its own target status. Always 200;
     * the caller inspects `failed`. Ownership-scoped (V-01 hardening): another
     * user's id is reported as not found, never mutated.
     */
    private function bulkStatus(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $updates = $body['updates'] ?? null;
        if (!is_array($updates) || !array_is_list($updates) || $updates === []) {
            throw new ValidationException([new ValidationError('updates', 'updates must be a non-empty array', 'invalid_value')]);
        }
        if (count($updates) > self::MAX_ITEMS) {
            throw new ValidationException([new ValidationError('updates', 'too many items (max ' . self::MAX_ITEMS . ')', 'invalid_value')]);
        }

        $updated = [];
        $failed = [];
        $now = $this->now();
        foreach ($updates as $item) {
            $itemArr = is_array($item) ? $item : [];
            $id = isset($itemArr['id']) && is_int($itemArr['id']) ? $itemArr['id'] : null;
            $status = isset($itemArr['status']) && is_string($itemArr['status']) ? TaskStatus::tryFrom($itemArr['status']) : null;
            if ($id === null) {
                $failed[] = ['id' => 0, 'error' => 'id must be an integer'];
                continue;
            }
            if ($status === null) {
                $failed[] = ['id' => $id, 'error' => 'invalid status value'];
                continue;
            }
            if ($this->repo->updateOwnedStatus($id, $userId, $status, $now)) {
                $updated[] = $id;
            } else {
                $failed[] = ['id' => $id, 'error' => 'task not found'];
            }
        }
        return $this->json->create(['updated' => $updated, 'failed' => $failed]);
    }

    /** Homogeneous bulk update — all given ids move to `done`. */
    private function bulkDone(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $rawIds = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        // Non-integer values are silently dropped.
        $ids = array_values(array_filter($rawIds, static fn (mixed $v): bool => is_int($v)));
        if ($ids === []) {
            return $this->json->create(['error' => 'ids array is required and must contain integers'], 422);
        }
        if (count($ids) > self::MAX_ITEMS) {
            return $this->json->create(['error' => 'too many ids (max ' . self::MAX_ITEMS . ')'], 422);
        }
        $updated = $this->repo->bulkSetOwnedStatus($ids, $userId, TaskStatus::Done, $this->now());
        return $this->json->create(['updated' => $updated, 'count' => count($updated)]);
    }

    private function userId(ServerRequestInterface $request): ?int
    {
        return V::userId($request->getHeaderLine('X-User-Id'));
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private function view(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'title' => (string) $t['title'],
            'status' => (string) $t['status'],
            'created_at' => (string) $t['created_at'],
            'updated_at' => (string) $t['updated_at'],
        ];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }
}
