<?php

declare(strict_types=1);

namespace AnnounceLog\Announcement;

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
        private readonly AnnouncementRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/announcements', $this->create(...));
        $router->put('/announcements/{id}', $this->update(...));
        $router->delete('/announcements/{id}', $this->delete(...));
        $router->get('/announcements', $this->list(...));
        $router->post('/announcements/{id}/dismiss', $this->dismiss(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }
        $input = $this->parse((array) ($request->getParsedBody() ?? []));
        $now = $this->now();
        $id = $this->repo->create($input['title'], $input['body'], $input['priority'], $input['starts_at'], $input['ends_at'], $now);
        return $this->json->create($this->view((array) $this->repo->find($id)), 201);
    }

    private function update(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request);
        if ($id === 0 || $this->repo->find($id) === null) {
            return $this->notFound();
        }
        $input = $this->parse((array) ($request->getParsedBody() ?? []));
        $this->repo->update($id, $input['title'], $input['body'], $input['priority'], $input['starts_at'], $input['ends_at'], $this->now());
        return $this->json->create($this->view((array) $this->repo->find($id)));
    }

    private function delete(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request);
        if ($id === 0 || $this->repo->delete($id) === 0) {
            return $this->notFound();
        }
        return $this->json->create(['deleted' => true]);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        // X-User-Id is optional here: absent → show all active; present → exclude dismissed.
        $userId = null;
        $header = $request->getHeaderLine('X-User-Id');
        if ($header !== '') {
            $userId = V::userId($header);
            if ($userId === null) {
                return $this->unauthorized(); // malformed header is rejected, not silently ignored
            }
        }
        $items = array_map($this->view(...), $this->repo->listActive($this->now(), $userId));
        return $this->json->create(['announcements' => $items, 'count' => count($items)]);
    }

    private function dismiss(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request);
        if ($id === 0 || $this->repo->find($id) === null) {
            return $this->notFound();
        }
        $this->repo->dismiss($userId, $id, $this->now());
        return $this->json->create(['dismissed' => true]); // idempotent — repeated call is a no-op
    }

    /**
     * Constant-time admin authentication. Empty configured key fails closed:
     * an unset NENE2 admin key can never be matched.
     */
    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        $provided = $request->getHeaderLine('X-Admin-Key');
        return $provided !== '' && hash_equals($this->adminKey, $provided);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{title: string, body: string, priority: int, starts_at: string, ends_at: string}
     */
    private function parse(array $body): array
    {
        $errors = [];

        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            $errors[] = new ValidationError('title', 'title must be a non-empty string', 'invalid_value');
        }

        $text = V::str($body['body'] ?? '', 2000) ?? '';

        $priority = 0;
        if (array_key_exists('priority', $body)) {
            $p = V::bodyInt($body['priority'], 0, 100);
            if ($p === null) {
                $errors[] = new ValidationError('priority', 'priority must be an integer 0..100', 'invalid_value');
            } else {
                $priority = $p;
            }
        }

        $startsAt = $this->utcIso($body['starts_at'] ?? null);
        if ($startsAt === null) {
            $errors[] = new ValidationError('starts_at', 'starts_at must be an ISO 8601 datetime with ±HH:MM offset', 'invalid_value');
        }
        $endsAt = $this->utcIso($body['ends_at'] ?? null);
        if ($endsAt === null) {
            $errors[] = new ValidationError('ends_at', 'ends_at must be an ISO 8601 datetime with ±HH:MM offset', 'invalid_value');
        }

        // ends_at > starts_at compared as instants (offsets normalised).
        if ($startsAt !== null && $endsAt !== null && !$this->after($endsAt, $startsAt)) {
            $errors[] = new ValidationError('ends_at', 'ends_at must be after starts_at', 'invalid_value');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Reaching here means $errors is empty, so every field validated.
        assert($startsAt !== null && $endsAt !== null);
        return ['title' => $title, 'body' => $text, 'priority' => $priority, 'starts_at' => $startsAt, 'ends_at' => $endsAt];
    }

    /**
     * Validate an ISO 8601 datetime and reject offsets outside ±14:00.
     * (Released V::isoDatetime (1.5.323) accepts e.g. "+25:00".)
     */
    private function utcIso(mixed $raw): ?string
    {
        $iso = V::isoDatetime($raw);
        if ($iso === null) {
            return null;
        }
        $offsetHours = (int) substr($iso, -5, 2);
        $offsetMinutes = (int) substr($iso, -2);
        if ($offsetHours > 14 || $offsetMinutes > 59 || ($offsetHours === 14 && $offsetMinutes > 0)) {
            return null;
        }
        return $iso;
    }

    private function after(string $a, string $b): bool
    {
        $da = \DateTimeImmutable::createFromFormat(DATE_ATOM, $a);
        $db = \DateTimeImmutable::createFromFormat(DATE_ATOM, $b);
        return $da !== false && $db !== false && $da > $db;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function view(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'title' => (string) $a['title'],
            'body' => (string) $a['body'],
            'priority' => (int) $a['priority'],
            'starts_at' => (string) $a['starts_at'],
            'ends_at' => (string) $a['ends_at'],
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
        return $this->json->create(['error' => 'Unauthorized'], 401);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Announcement not found'], 404);
    }
}
