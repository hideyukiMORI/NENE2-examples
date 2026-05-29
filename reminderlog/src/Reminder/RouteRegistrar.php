<?php

declare(strict_types=1);

namespace ReminderLog\Reminder;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const array STATUSES = ['pending', 'cancelled'];

    public function __construct(
        private readonly ReminderRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/reminders', $this->create(...));
        $router->get('/reminders', $this->list(...));
        $router->patch('/reminders/{id}/cancel', $this->cancel(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);

        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            throw new ValidationException([new ValidationError('title', 'title must be a non-empty string', 'invalid_value')]);
        }
        $now = (new \DateTimeImmutable())->format(DATE_ATOM);
        $remindAt = $this->futureIso($body['remind_at'] ?? null, $now);
        if ($remindAt === null) {
            throw new ValidationException([new ValidationError('remind_at', 'remind_at must be a future ISO 8601 datetime with ±HH:MM offset', 'invalid_value')]);
        }

        $id = $this->repo->create($userId, $title, $remindAt, $now);
        return $this->json->create($this->view((array) $this->repo->findOwned($id, $userId)), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $params = $request->getQueryParams();
        $status = null;
        if (array_key_exists('status', $params)) {
            $raw = $params['status'];
            if (!is_string($raw) || !in_array($raw, self::STATUSES, true)) {
                throw new ValidationException([new ValidationError('status', 'status must be pending|cancelled', 'invalid_value')]);
            }
            $status = $raw;
        }
        $limit = V::queryInt($params, 'limit', 1, 100, 20);
        $offset = V::queryInt($params, 'offset', 0, 1000000, 0);
        if ($limit === null || $offset === null) {
            throw new ValidationException([new ValidationError('limit', 'limit (1..100) / offset (>=0) must be valid', 'invalid_value')]);
        }
        $items = array_map($this->view(...), $this->repo->listOwned($userId, $status, $limit, $offset));
        return $this->json->create(['reminders' => $items, 'count' => count($items)]);
    }

    private function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request);
        $reminder = $this->repo->findOwned($id, $userId);
        if ($reminder === null) {
            return $this->notFound(); // cross-user or absent
        }
        if ((string) $reminder['status'] !== 'pending') {
            return $this->json->create(['error' => 'reminder is not pending'], 409);
        }
        $this->repo->cancel($id, $userId);
        return $this->json->create($this->view((array) $this->repo->findOwned($id, $userId)));
    }

    /**
     * Correct future check: compare as DateTimeImmutable so different offsets
     * are normalised to the same instant before comparing.
     *
     * NB: released `V::futureDatetime()` (1.5.323) compares the ATOM *strings*,
     * which is wrong across timezone offsets — so we validate the format with
     * `V::isoDatetime()` and do the chronological comparison here. A fix for the
     * core helper is filed separately.
     */
    private function futureIso(mixed $raw, string $now): ?string
    {
        $iso = V::isoDatetime($raw);
        if ($iso === null) {
            return null;
        }
        // Released V::isoDatetime (1.5.323) does not range-check the offset, so
        // "+25:00" passes its regex. Reject offsets beyond ±14:00 here.
        $offsetHours = (int) substr($iso, -5, 2);
        $offsetMinutes = (int) substr($iso, -2);
        if ($offsetHours > 14 || $offsetMinutes > 59 || ($offsetHours === 14 && $offsetMinutes > 0)) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat(DATE_ATOM, $iso);
        $nowDt = \DateTimeImmutable::createFromFormat(DATE_ATOM, $now);
        if ($dt === false || $nowDt === false) {
            return null;
        }
        return $dt > $nowDt ? $iso : null;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function view(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'remind_at' => (string) $r['remind_at'],
            'status' => (string) $r['status'],
            'created_at' => (string) $r['created_at'],
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
        return $this->json->create(['error' => 'Reminder not found'], 404);
    }
}
