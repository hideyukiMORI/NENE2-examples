<?php

declare(strict_types=1);

namespace IsolationLog\Tenant;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly IsolationRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/tenants', $this->createTenant(...));
        $router->get('/tenants', $this->listTenants(...));
        $router->get('/tenants/{id}', $this->getTenant(...));
        $router->post('/notes', $this->createNote(...));
        $router->get('/notes', $this->listNotes(...));
        $router->get('/notes/{id}', $this->getNote(...));
        $router->delete('/notes/{id}', $this->deleteNote(...));
    }

    // ── tenants (admin) ─────────────────────────────────────────────────────

    private function createTenant(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $name = V::str($body['name'] ?? null, 100);
        if ($name === null || $name === '') {
            throw new ValidationException([new ValidationError('name', 'name must be a non-empty string', 'invalid_value')]);
        }
        $id = $this->repo->createTenant($name, $this->now());
        return $this->json->create($this->tenantView((array) $this->repo->findTenant($id)), 201);
    }

    private function listTenants(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }
        return $this->json->create(['tenants' => array_map($this->tenantView(...), $this->repo->listTenants())]);
    }

    private function getTenant(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }
        $tenant = $this->repo->findTenant($this->idParam($request));
        if ($tenant === null) {
            return $this->json->create(['error' => 'Tenant not found'], 404);
        }
        return $this->json->create($this->tenantView($tenant));
    }

    // ── notes (tenant-scoped) ─────────────────────────────────────────────────

    private function createNote(ServerRequestInterface $request): ResponseInterface
    {
        [$tenantId, $userId] = $this->identity($request);
        if ($tenantId === null || $userId === null) {
            return $this->unauthorized();
        }
        // The tenant must exist; body `tenant_id` (if any) is ignored — the header wins.
        if ($this->repo->findTenant($tenantId) === null) {
            throw new ValidationException([new ValidationError('tenant', 'tenant does not exist', 'invalid_value')]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            throw new ValidationException([new ValidationError('title', 'title must be a non-empty string', 'invalid_value')]);
        }
        $note = V::str($body['body'] ?? '', 4000) ?? '';

        $id = $this->repo->createNote($tenantId, $userId, $title, $note, $this->now());
        return $this->json->create($this->noteView((array) $this->repo->findNote($id, $tenantId)), 201);
    }

    private function listNotes(ServerRequestInterface $request): ResponseInterface
    {
        [$tenantId, $userId] = $this->identity($request);
        if ($tenantId === null || $userId === null) {
            return $this->unauthorized();
        }
        $params = $request->getQueryParams();
        $limit = V::queryInt($params, 'limit', 1, 100, 20);
        $offset = V::queryInt($params, 'offset', 0, 1000000, 0);
        if ($limit === null || $offset === null) {
            throw new ValidationException([new ValidationError('query', 'limit (1..100) / offset (>=0) must be valid', 'invalid_value')]);
        }
        $notes = array_map($this->noteView(...), $this->repo->listNotes($tenantId, $limit, $offset));
        return $this->json->create(['notes' => $notes, 'count' => count($notes)]);
    }

    private function getNote(ServerRequestInterface $request): ResponseInterface
    {
        [$tenantId, $userId] = $this->identity($request);
        if ($tenantId === null || $userId === null) {
            return $this->unauthorized();
        }
        $note = $this->repo->findNote($this->idParam($request), $tenantId);
        if ($note === null) {
            return $this->notFound(); // cross-tenant or absent — indistinguishable
        }
        return $this->json->create($this->noteView($note));
    }

    private function deleteNote(ServerRequestInterface $request): ResponseInterface
    {
        [$tenantId, $userId] = $this->identity($request);
        if ($tenantId === null || $userId === null) {
            return $this->unauthorized();
        }
        if (!$this->repo->deleteNote($this->idParam($request), $tenantId)) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array{0: int|null, 1: int|null} validated tenant id + user id */
    private function identity(ServerRequestInterface $request): array
    {
        return [
            V::userId($request->getHeaderLine('X-Tenant-Id')),
            V::userId($request->getHeaderLine('X-User-Id')),
        ];
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        return V::secret($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private function tenantView(array $t): array
    {
        return ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'created_at' => (string) $t['created_at']];
    }

    /**
     * @param array<string, mixed> $n
     * @return array<string, mixed>
     */
    private function noteView(array $n): array
    {
        return [
            'id' => (int) $n['id'],
            'tenant_id' => (int) $n['tenant_id'],
            'user_id' => (int) $n['user_id'],
            'title' => (string) $n['title'],
            'body' => (string) $n['body'],
            'created_at' => (string) $n['created_at'],
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
        return $this->json->create(['error' => 'Valid credentials required'], 401);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Note not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
