<?php

declare(strict_types=1);

namespace ReorderLog\Reorder;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly ReorderRepository $repo,
        private readonly ReorderService $service,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/boards/{boardId}/items', $this->handleList(...));
        $router->put('/boards/{boardId}/order', $this->handleReorder(...));
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        $boardId = (int) $this->routeParam($request, 'boardId');
        $board = $this->ownedBoardOr404($boardId, $userId);
        if ($board === null) {
            return $this->json->create(['error' => 'Board not found'], 404);
        }

        return $this->json->create(['items' => $this->formatItems($boardId)]);
    }

    private function handleReorder(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        $boardId = (int) $this->routeParam($request, 'boardId');
        // Unknown board and unowned board are indistinguishable: both 404.
        if ($this->ownedBoardOr404($boardId, $userId) === null) {
            return $this->json->create(['error' => 'Board not found'], 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $ids = $this->parseIds($body);

        // The submitted set must be exactly the board's current id set:
        // rejects partial orders, foreign items, and unknown ids in one check.
        $current = $this->repo->itemIds($boardId);
        $submitted = $ids;
        sort($current);
        sort($submitted);
        if ($current !== $submitted) {
            throw new ValidationException([
                new ValidationError('ids', 'ids must be exactly the items of this board', 'invalid_value'),
            ]);
        }

        $this->service->reorder($boardId, $ids);

        return $this->json->create(['items' => $this->formatItems($boardId)]);
    }

    /**
     * Return the board only when it exists and is owned by the caller.
     *
     * @return array<string, mixed>|null
     */
    private function ownedBoardOr404(int $boardId, int $userId): ?array
    {
        if ($boardId <= 0) {
            return null;
        }
        $board = $this->repo->findBoard($boardId);
        if ($board === null || (int) $board['owner_id'] !== $userId) {
            return null;
        }
        return $board;
    }

    /**
     * Parse and validate `ids`: a non-empty list of unique positive integers.
     * Throws ValidationException (HTTP 422) on any violation.
     *
     * @param array<string, mixed> $body
     * @return list<int>
     */
    private function parseIds(array $body): array
    {
        $raw = $body['ids'] ?? null;
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            throw new ValidationException([
                new ValidationError('ids', 'ids must be a non-empty array', 'invalid_type'),
            ]);
        }

        $ids = [];
        foreach ($raw as $value) {
            if (!is_int($value) || $value <= 0) {
                throw new ValidationException([
                    new ValidationError('ids', 'ids must be positive integers', 'invalid_value'),
                ]);
            }
            $ids[] = $value;
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new ValidationException([
                new ValidationError('ids', 'ids must not contain duplicates', 'invalid_value'),
            ]);
        }

        return $ids;
    }

    private function requireUserId(ServerRequestInterface $request): ?int
    {
        $header = $request->getHeaderLine('X-User-Id');
        if ($header === '') {
            return null;
        }
        $id = (int) $header;
        return $id > 0 ? $id : null;
    }

    private function routeParam(ServerRequestInterface $request, string $key): string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (string) ($params[$key] ?? '');
    }

    /** @return list<array<string, mixed>> */
    private function formatItems(int $boardId): array
    {
        return array_map(
            static fn (array $item): array => [
                'id' => (int) $item['id'],
                'title' => (string) $item['title'],
                'position' => (int) $item['position'],
            ],
            $this->repo->listItems($boardId),
        );
    }
}
