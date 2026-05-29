<?php

declare(strict_types=1);

namespace TicketLog\Ticket;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly TicketRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/events', $this->handleCreateEvent(...));
        $router->get('/events', $this->handleListEvents(...));
        $router->get('/events/{id}', $this->handleGetEvent(...));
        $router->post('/events/{id}/tickets', $this->handlePurchase(...));
        $router->delete('/tickets/{id}', $this->handleCancel(...));
    }

    private function handleCreateEvent(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->json->create(['error' => 'Admin key required'], 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $name = $body['name'] ?? null;
        $capacity = $body['capacity'] ?? null;

        $errors = [];
        if (!is_string($name) || trim($name) === '') {
            $errors[] = new ValidationError('name', 'name must be a non-empty string', 'invalid_value');
        }
        if (!is_int($capacity) || $capacity < 1) {
            $errors[] = new ValidationError('capacity', 'capacity must be a positive integer', 'invalid_value');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($name) && is_int($capacity));

        $id = $this->repo->createEvent(trim($name), $capacity, $this->now());
        $event = $this->repo->findEvent($id);
        assert($event !== null);
        return $this->json->create($this->projectEvent($event), 201);
    }

    private function handleListEvents(ServerRequestInterface $request): ResponseInterface
    {
        $events = array_map(
            fn (array $e): array => $this->projectEvent($e),
            $this->repo->listEvents(),
        );
        return $this->json->create(['events' => $events, 'count' => count($events)]);
    }

    private function handleGetEvent(ServerRequestInterface $request): ResponseInterface
    {
        $event = $this->repo->findEvent($this->idParam($request, 'id'));
        if ($event === null) {
            return $this->json->create(['error' => 'Event not found'], 404);
        }
        return $this->json->create($this->projectEvent($event));
    }

    private function handlePurchase(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
        }

        $eventId = $this->idParam($request, 'id');
        $result = $this->repo->purchase($eventId, $userId, $this->now());

        return match ($result) {
            'not_found' => $this->json->create(['error' => 'Event not found'], 404),
            'sold_out' => $this->json->create(['error' => 'Event is sold out'], 409),
            'duplicate' => $this->json->create(['error' => 'You already have a ticket for this event'], 409),
            default => $this->json->create([
                'ticket_id' => $this->repo->findUserTicket($eventId, $userId),
                'event_id' => $eventId,
            ], 201),
        };
    }

    private function handleCancel(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
        }

        $result = $this->repo->cancel($this->idParam($request, 'id'), $userId);
        return match ($result) {
            'not_found' => $this->json->create(['error' => 'Ticket not found'], 404),
            'not_owner' => $this->json->create(['error' => 'You do not own this ticket'], 403),
            default => $this->json->createEmpty(204),
        };
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function projectEvent(array $event): array
    {
        $capacity = (int) $event['capacity'];
        $sold = isset($event['sold']) ? (int) $event['sold'] : $this->repo->soldCount((int) $event['id']);
        return [
            'id' => (int) $event['id'],
            'name' => (string) $event['name'],
            'capacity' => $capacity,
            'sold' => $sold,
            'remaining' => max(0, $capacity - $sold),
            'sold_out' => $sold >= $capacity,
        ];
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        $provided = $request->getHeaderLine('X-Admin-Key');
        return $provided !== '' && hash_equals($this->adminKey, $provided);
    }

    private function resolveUserId(ServerRequestInterface $request): ?int
    {
        $raw = $request->getHeaderLine('X-User-Id');
        if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function idParam(ServerRequestInterface $request, string $key): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params[$key] ?? 0);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
