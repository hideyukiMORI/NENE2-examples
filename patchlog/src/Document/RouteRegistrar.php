<?php

declare(strict_types=1);

namespace PatchLog\Document;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\ConditionalWriteHelper;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    /** Fields a client may never set through a write body. */
    private const array IMMUTABLE = ['id', 'owner_id', 'version', 'created_at', 'updated_at'];

    public function __construct(
        private readonly DocumentRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/documents', $this->create(...));
        $router->get('/documents', $this->list(...));
        $router->get('/documents/{id}', $this->get(...));
        $router->patch('/documents/{id}', $this->patch(...));
        $router->put('/documents/{id}', $this->put(...));
        $router->delete('/documents/{id}', $this->delete(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $owner = V::userId($request->getHeaderLine('X-User-Id'));
        if ($owner === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $this->rejectImmutable($body);

        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            throw new ValidationException([new ValidationError('title', 'title must be a non-empty string (<= 200 chars)', 'invalid_value')]);
        }
        $status = $this->parseStatus($body, 'status', DocumentStatus::Draft);

        $id = $this->repo->create($owner, $title, $status->value, $this->now());
        return $this->withEtag($this->json->create($this->view((array) $this->repo->findById($id)), 201), (array) $this->repo->findById($id));
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit = V::queryInt($params, 'limit', 1, 100, 20);
        $page = V::queryInt($params, 'page', 1, 1000000, 1);
        if ($limit === null || $page === null) {
            throw new ValidationException([new ValidationError('query', 'limit (1..100) and page (>=1) must be valid integers', 'invalid_value')]);
        }
        $items = array_map($this->view(...), $this->repo->listByPage($limit, ($page - 1) * $limit));
        return $this->json->create(['items' => $items, 'total' => $this->repo->count(), 'page' => $page, 'limit' => $limit]);
    }

    private function get(ServerRequestInterface $request): ResponseInterface
    {
        $doc = $this->repo->findById($this->idParam($request));
        if ($doc === null) {
            return $this->notFound();
        }
        return $this->withEtag($this->json->create($this->view($doc)), $doc);
    }

    private function patch(ServerRequestInterface $request): ResponseInterface
    {
        $owner = V::userId($request->getHeaderLine('X-User-Id'));
        if ($owner === null) {
            return $this->unauthorized();
        }
        $doc = $this->ownedOr404($request, $owner);
        if ($doc === null) {
            return $this->notFound();
        }
        // Optimistic lock: If-Match must equal the current ETag.
        $precondition = ConditionalWriteHelper::check($request, $this->problems, $this->etag($doc));
        if ($precondition !== null) {
            return $precondition;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $this->rejectImmutable($body);

        $title = (string) $doc['title'];
        $status = (string) $doc['status'];
        $changed = false;

        // RFC 7396: absent = unchanged, null = reset to default, value = set.
        if (array_key_exists('title', $body)) {
            $t = V::str($body['title'], 200);
            if ($t === null || $t === '') {
                throw new ValidationException([new ValidationError('title', 'title must be a non-empty string (<= 200 chars)', 'invalid_value')]);
            }
            $title = $t;
            $changed = true;
        }
        if (array_key_exists('status', $body)) {
            $status = $body['status'] === null
                ? DocumentStatus::Draft->value
                : $this->parseStatusValue($body['status']);
            $changed = true;
        }

        if ($changed) {
            $this->repo->update((int) $doc['id'], $title, $status, $this->now());
        }
        $fresh = (array) $this->repo->findById((int) $doc['id']);
        return $this->withEtag($this->json->create($this->view($fresh)), $fresh);
    }

    private function put(ServerRequestInterface $request): ResponseInterface
    {
        $owner = V::userId($request->getHeaderLine('X-User-Id'));
        if ($owner === null) {
            return $this->unauthorized();
        }
        $doc = $this->ownedOr404($request, $owner);
        if ($doc === null) {
            return $this->notFound();
        }
        $precondition = ConditionalWriteHelper::check($request, $this->problems, $this->etag($doc));
        if ($precondition !== null) {
            return $precondition;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $this->rejectImmutable($body);

        // Full replace: title is required.
        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            throw new ValidationException([new ValidationError('title', 'title is required for PUT', 'required')]);
        }
        $status = $this->parseStatus($body, 'status', DocumentStatus::Draft);

        $this->repo->update((int) $doc['id'], $title, $status->value, $this->now());
        $fresh = (array) $this->repo->findById((int) $doc['id']);
        return $this->withEtag($this->json->create($this->view($fresh)), $fresh);
    }

    private function delete(ServerRequestInterface $request): ResponseInterface
    {
        $owner = V::userId($request->getHeaderLine('X-User-Id'));
        if ($owner === null) {
            return $this->unauthorized();
        }
        if (!$this->repo->delete($this->idParam($request), $owner)) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function rejectImmutable(array $body): void
    {
        foreach (self::IMMUTABLE as $field) {
            if (array_key_exists($field, $body)) {
                throw new ValidationException([new ValidationError($field, "{$field} is immutable and cannot be set", 'immutable')]);
            }
        }
    }

    /** @param array<string, mixed> $body */
    private function parseStatus(array $body, string $key, DocumentStatus $default): DocumentStatus
    {
        if (!array_key_exists($key, $body)) {
            return $default;
        }
        $status = V::enum($body[$key], DocumentStatus::class);
        if (!$status instanceof DocumentStatus) {
            throw new ValidationException([new ValidationError($key, 'status must be one of: draft, published, archived', 'invalid_value')]);
        }
        return $status;
    }

    private function parseStatusValue(mixed $raw): string
    {
        $status = V::enum($raw, DocumentStatus::class);
        if (!$status instanceof DocumentStatus) {
            throw new ValidationException([new ValidationError('status', 'status must be one of: draft, published, archived', 'invalid_value')]);
        }
        return $status->value;
    }

    /**
     * Return the document only if it exists and belongs to the caller; null
     * otherwise (cross-owner access is indistinguishable from absent — IDOR).
     *
     * @return array<string, mixed>|null
     */
    private function ownedOr404(ServerRequestInterface $request, int $owner): ?array
    {
        $doc = $this->repo->findById($this->idParam($request));
        if ($doc === null || (int) $doc['owner_id'] !== $owner) {
            return null;
        }
        return $doc;
    }

    /** @param array<string, mixed> $doc */
    private function etag(array $doc): string
    {
        return sprintf('"%d-%d"', (int) $doc['id'], (int) $doc['version']);
    }

    /** @param array<string, mixed> $doc */
    private function withEtag(ResponseInterface $response, array $doc): ResponseInterface
    {
        return $response->withHeader('ETag', $this->etag($doc));
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function view(array $doc): array
    {
        return [
            'id' => (int) $doc['id'],
            'owner_id' => (int) $doc['owner_id'],
            'title' => (string) $doc['title'],
            'status' => (string) $doc['status'],
            'version' => (int) $doc['version'],
            'created_at' => (string) $doc['created_at'],
            'updated_at' => (string) $doc['updated_at'],
        ];
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        return ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : 0;
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Document not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
