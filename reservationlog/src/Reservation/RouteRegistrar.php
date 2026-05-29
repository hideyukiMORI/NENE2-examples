<?php

declare(strict_types=1);

namespace ReservationLog\Reservation;

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
        private readonly ReservationRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/resources', $this->createResource(...));
        $router->get('/resources/{id}/bookings', $this->resourceBookings(...));
        $router->post('/resources/{id}/book', $this->book(...));
        $router->get('/bookings', $this->myBookings(...));
        $router->delete('/bookings/{id}', $this->cancel(...));
    }

    private function createResource(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $name = V::str($body['name'] ?? null, 200);
        if ($name === null || $name === '') {
            throw new ValidationException([new ValidationError('name', 'name must be a non-empty string', 'invalid_value')]);
        }
        $id = $this->repo->createResource($name, $this->now());
        return $this->json->create(['id' => $id, 'name' => $name], 201);
    }

    private function resourceBookings(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $resourceId = $this->idParam($request);
        if ($resourceId === 0 || !$this->repo->resourceExists($resourceId)) {
            return $this->notFound('resource not found');
        }
        $bookings = $this->repo->listByResource($resourceId);
        // Admin view includes user_id for auditing.
        return $this->json->create([
            'data' => array_map(static fn (Booking $b): array => $b->toAdminArray(), $bookings),
            'total' => count($bookings),
        ]);
    }

    private function book(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $resourceId = $this->idParam($request);
        if ($resourceId === 0 || !$this->repo->resourceExists($resourceId)) {
            return $this->notFound('resource not found');
        }
        $body = (array) ($request->getParsedBody() ?? []);

        $startsAt = $this->iso($body['starts_at'] ?? null);
        $endsAt = $this->iso($body['ends_at'] ?? null);
        // Narrow both to non-null via control flow so the type-checker is satisfied.
        if ($startsAt === null || $endsAt === null || !$this->after($endsAt, $startsAt)) {
            throw new ValidationException([new ValidationError(
                'starts_at',
                'starts_at / ends_at must be ISO 8601 (±HH:MM offset) and ends_at must be after starts_at',
                'invalid_value',
            )]);
        }
        $note = null;
        if (array_key_exists('note', $body) && $body['note'] !== null) {
            $raw = $body['note'];
            if (!is_string($raw) || mb_strlen($raw) > 500) {
                throw new ValidationException([new ValidationError('note', 'note must be a string (max 500)', 'invalid_value')]);
            }
            $note = $raw;
        }

        $booking = $this->repo->book($resourceId, $userId, $startsAt, $endsAt, $note, $this->now());
        if ($booking === null) {
            return $this->json->create(['error' => 'time slot overlaps an existing booking'], 409);
        }
        return $this->json->create($booking->toPublicArray(), 201);
    }

    private function myBookings(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $bookings = $this->repo->listByUser($userId);
        // Public view excludes user_id.
        return $this->json->create([
            'data' => array_map(static fn (Booking $b): array => $b->toPublicArray(), $bookings),
            'total' => count($bookings),
        ]);
    }

    private function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request);
        $booking = $id === 0 ? null : $this->repo->find($id);
        if ($booking === null) {
            return $this->notFound('booking not found'); // → 404
        }
        if ($booking->userId !== $userId) {
            // Booking id is already visible to its lister, so existence is not secret:
            // wrong owner → 403 (not 404).
            return $this->json->create(['error' => 'not your booking'], 403);
        }
        $this->repo->delete($id);
        return $this->json->create(['cancelled' => true]);
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        return hash_equals($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
    }

    private function iso(mixed $raw): ?string
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

    private function notFound(string $message): ResponseInterface
    {
        return $this->json->create(['error' => $message], 404);
    }
}
