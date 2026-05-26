<?php

declare(strict_types=1);

namespace InboundLog\Inbound;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly WebhookRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/sources', $this->handleCreateSource(...));
        $router->post('/sources/{id}/receive', $this->handleReceive(...));
        $router->get('/sources/{id}/events', $this->handleListEvents(...));
        $router->get('/events/{id}', $this->handleGetEvent(...));
    }

    private function handleCreateSource(ServerRequestInterface $request): ResponseInterface
    {
        $body   = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            $errors[] = new ValidationError('name', 'name is required', 'required');
        }

        $secret = isset($body['secret']) && is_string($body['secret']) ? trim($body['secret']) : '';
        if ($secret === '') {
            $errors[] = new ValidationError('secret', 'secret is required', 'required');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id     = $this->repo->createSource($name, $secret, $this->now());
        $source = $this->repo->findSource($id);
        assert($source !== null);
        // Never expose the secret in the response
        unset($source['secret']);
        return $this->json->create($source, 201);
    }

    private function handleReceive(ServerRequestInterface $request): ResponseInterface
    {
        $id     = $this->id($request);
        $source = $this->repo->findSource($id);
        if ($source === null) {
            return $this->json->create(['error' => 'Source not found'], 404);
        }

        if (!(bool) $source['active']) {
            return $this->json->create(['error' => 'Source is inactive'], 403);
        }

        // Validate HMAC-SHA256 signature
        $rawBody   = (string) $request->getBody();
        $sigHeader = $request->getHeaderLine('X-Webhook-Signature');
        if (!$this->verifySignature($rawBody, $sigHeader, (string) $source['secret'])) {
            return $this->json->create(['error' => 'Invalid signature'], 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($rawBody, true) ?? [];

        $eventId = isset($payload['event_id']) && is_string($payload['event_id'])
            ? trim($payload['event_id']) : '';
        if ($eventId === '') {
            throw new ValidationException([new ValidationError('event_id', 'event_id is required in payload', 'required')]);
        }

        $eventType = isset($payload['event_type']) && is_string($payload['event_type'])
            ? trim($payload['event_type']) : '';
        if ($eventType === '') {
            throw new ValidationException([new ValidationError('event_type', 'event_type is required in payload', 'required')]);
        }

        // Idempotency: already processed
        $existing = $this->repo->findEventBySourceAndEventId($id, $eventId);
        if ($existing !== null) {
            return $this->json->create(['status' => 'already_processed', 'id' => $existing['id']]);
        }

        $dbId  = $this->repo->storeEvent($id, $eventId, $eventType, $rawBody, $this->now());
        $event = $this->repo->findEvent($dbId);
        assert($event !== null);
        return $this->json->create(array_merge($event, ['status' => 'processed']), 201);
    }

    private function handleListEvents(ServerRequestInterface $request): ResponseInterface
    {
        $id     = $this->id($request);
        $source = $this->repo->findSource($id);
        if ($source === null) {
            return $this->json->create(['error' => 'Source not found'], 404);
        }
        $events = $this->repo->listEvents($id);
        return $this->json->create(['events' => $events, 'count' => count($events)]);
    }

    private function handleGetEvent(ServerRequestInterface $request): ResponseInterface
    {
        $id    = $this->id($request);
        $event = $this->repo->findEvent($id);
        if ($event === null) {
            return $this->json->create(['error' => 'Event not found'], 404);
        }
        return $this->json->create($event);
    }

    private function verifySignature(string $body, string $header, string $secret): bool
    {
        if (!str_starts_with($header, 'sha256=')) {
            return false;
        }
        $expected = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, substr($header, 7));
    }

    private function id(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['id'] ?? 0);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
