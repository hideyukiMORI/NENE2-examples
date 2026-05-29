<?php

declare(strict_types=1);

namespace WaitlistLog\Waitlist;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int MAX_NOTE_LEN = 500;

    public function __construct(
        private readonly WaitlistRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/waitlist', $this->join(...));
        // Static '/waitlist/me' before dynamic '/waitlist/{id}/...' so "me" is not captured.
        $router->get('/waitlist/me', $this->me(...));
        $router->delete('/waitlist/me', $this->leave(...));
        $router->get('/waitlist', $this->adminList(...));
        $router->post('/waitlist/{id}/approve', $this->approve(...));
        $router->post('/waitlist/{id}/decline', $this->decline(...));
    }

    private function join(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $note = $this->resolveNote($body['note'] ?? null);
        $entry = $this->repo->join($userId, $note, $this->now());
        if ($entry === null) {
            return $this->json->create(['error' => 'Already on the waitlist.'], 409);
        }
        return $this->json->create($this->view($entry), 201);
    }

    private function me(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $entry = $this->repo->findByUser($userId);
        if ($entry === null) {
            return $this->json->create(['error' => 'Not on the waitlist.'], 404);
        }
        $view = $this->view($entry);
        $view['position'] = $this->repo->positionOf((int) $entry['id']); // null unless waiting
        return $this->json->create($view);
    }

    private function leave(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        return match ($this->repo->leave($userId)) {
            'removed' => $this->json->create(['removed' => true]),
            'not_found' => $this->json->create(['error' => 'Not on the waitlist.'], 404),
            'not_waiting' => $this->json->create(['error' => 'Cannot leave — status is no longer waiting.'], 409),
        };
    }

    private function adminList(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $params = $request->getQueryParams();
        $limit = V::queryInt($params, 'limit', 1, 100, 50);
        $offset = V::queryInt($params, 'offset', 0, 1000000, 0);
        if ($limit === null || $offset === null) {
            return $this->json->create(['error' => 'invalid limit/offset'], 422);
        }
        $entries = array_map(fn (array $e): array => $this->adminView($e), $this->repo->listAll($limit, $offset));
        return $this->json->create(['entries' => $entries, 'count' => count($entries)]);
    }

    private function approve(ServerRequestInterface $request): ResponseInterface
    {
        return $this->transition($request, WaitlistStatus::Approved);
    }

    private function decline(ServerRequestInterface $request): ResponseInterface
    {
        return $this->transition($request, WaitlistStatus::Declined);
    }

    private function transition(ServerRequestInterface $request, WaitlistStatus $newStatus): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $id = $this->idParam($request);
        if ($id === 0) {
            return $this->json->create(['error' => 'Entry not found.'], 404);
        }
        return match ($this->repo->transition($id, $newStatus, $this->now())) {
            'ok' => $this->json->create(['id' => $id, 'status' => $newStatus->value]),
            'not_found' => $this->json->create(['error' => 'Entry not found.'], 404),
            'already_terminal' => $this->json->create(['error' => 'Entry is already approved or declined.'], 409),
        };
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false; // fail-closed
        }
        return hash_equals($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
    }

    /** Notes are optional soft metadata — truncated (not rejected) when over the limit. */
    private function resolveNote(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        return mb_strlen($raw) > self::MAX_NOTE_LEN ? mb_substr($raw, 0, self::MAX_NOTE_LEN) : $raw;
    }

    /**
     * Public view — no user_id leaked to the entry owner's response (it's their own,
     * but we keep the shape minimal and consistent with the admin view boundary).
     *
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function view(array $e): array
    {
        return [
            'id' => (int) $e['id'],
            'status' => (string) $e['status'],
            'note' => $e['note'] !== null ? (string) $e['note'] : null,
            'created_at' => (string) $e['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function adminView(array $e): array
    {
        return ['user_id' => (int) $e['user_id']] + $this->view($e);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
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

    private function forbidden(): ResponseInterface
    {
        return $this->json->create(['error' => 'admin key required'], 403);
    }
}
