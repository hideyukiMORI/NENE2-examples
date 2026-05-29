<?php

declare(strict_types=1);

namespace StatsLog\Event;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const string MIN_DATE = '2000-01-01T00:00:00Z';
    private const string MAX_DATE = '2100-01-01T00:00:00Z';

    public function __construct(
        private readonly EventRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/events', $this->create(...));
        $router->get('/events', $this->list(...));
        // Static '/events/by-property' before parameterised '/events/{id}'.
        $router->get('/events/by-property', $this->byProperty(...));
        $router->get('/events/{id}', $this->show(...));
        $router->get('/stats/per-day', $this->perDay(...));
        $router->get('/stats/per-type', $this->perType(...));
        $router->get('/stats/unique-users', $this->uniqueUsers(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $type = V::str($body['event_type'] ?? null, 100);
        if ($type === null || $type === '') {
            throw new ValidationException([new ValidationError('event_type', 'event_type must be a non-empty string', 'invalid_value')]);
        }
        $userId = V::str($body['user_id'] ?? null, 200);
        if ($userId === null || $userId === '') {
            throw new ValidationException([new ValidationError('user_id', 'user_id must be a non-empty string', 'invalid_value')]);
        }
        $sessionId = V::str($body['session_id'] ?? '', 200) ?? '';

        // properties must be a JSON object (associative), not a scalar or list.
        $rawProps = $body['properties'] ?? null;
        if ($rawProps !== null && (!is_array($rawProps) || array_is_list($rawProps) && $rawProps !== [])) {
            throw new ValidationException([new ValidationError('properties', 'properties must be a JSON object', 'invalid_value')]);
        }
        $propertiesJson = is_array($rawProps) ? json_encode($rawProps, JSON_THROW_ON_ERROR) : '{}';

        $occurredAt = $this->occurredAt($body['occurred_at'] ?? null);

        $id = $this->repo->record($type, $userId, $sessionId, $propertiesJson, $occurredAt);
        return $this->json->create($this->view((array) $this->repo->find($id)), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        [$limit, $offset] = $this->pagination($request);
        $items = array_map($this->view(...), $this->repo->listAll($limit, $offset));
        return $this->json->create(['events' => $items, 'count' => count($items)]);
    }

    private function byProperty(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $key = isset($params['key']) && is_string($params['key']) ? $params['key'] : '';
        $value = isset($params['value']) && is_string($params['value']) ? $params['value'] : null;
        // Restrict the JSONPath key shape: dotted alphanumeric segments only.
        if (preg_match('/\A[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*\z/', $key) !== 1) {
            throw new ValidationException([new ValidationError('key', 'key must be a dotted alphanumeric property path', 'invalid_value')]);
        }
        if ($value === null) {
            throw new ValidationException([new ValidationError('value', 'value is required', 'invalid_value')]);
        }
        [$limit, $offset] = $this->pagination($request);
        $items = array_map($this->view(...), $this->repo->byProperty($key, $value, $limit, $offset));
        return $this->json->create(['events' => $items, 'count' => count($items)]);
    }

    private function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $event = $id === 0 ? null : $this->repo->find($id);
        if ($event === null) {
            return $this->notFound();
        }
        return $this->json->create($this->view($event));
    }

    private function perDay(ServerRequestInterface $request): ResponseInterface
    {
        [$from, $to] = $this->range($request);
        return $this->json->create(['from' => $from, 'to' => $to, 'per_day' => $this->repo->perDay($from, $to)]);
    }

    private function perType(ServerRequestInterface $request): ResponseInterface
    {
        [$from, $to] = $this->range($request);
        return $this->json->create(['from' => $from, 'to' => $to, 'per_type' => $this->repo->perType($from, $to)]);
    }

    private function uniqueUsers(ServerRequestInterface $request): ResponseInterface
    {
        [$from, $to] = $this->range($request);
        return $this->json->create(['from' => $from, 'to' => $to, 'unique_users' => $this->repo->uniqueUsers($from, $to)]);
    }

    private function occurredAt(mixed $raw): string
    {
        if ($raw === null) {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        }
        if (!is_string($raw) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $raw) !== 1) {
            throw new ValidationException([new ValidationError('occurred_at', 'occurred_at must be ISO 8601 UTC (…Z)', 'invalid_value')]);
        }
        return $raw;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();
        $from = $this->dateOr($params, 'from', self::MIN_DATE);
        $to = $this->dateOr($params, 'to', self::MAX_DATE);
        return [$from, $to];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dateOr(array $params, string $key, string $default): string
    {
        if (!array_key_exists($key, $params)) {
            return $default;
        }
        $raw = $params[$key];
        if (!is_string($raw) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $raw) !== 1) {
            throw new ValidationException([new ValidationError($key, "{$key} must be ISO 8601 UTC (…Z)", 'invalid_value')]);
        }
        return $raw;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pagination(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();
        $limit = V::queryInt($params, 'limit', 1, 100, 20);
        $offset = V::queryInt($params, 'offset', 0, 1000000, 0);
        if ($limit === null || $offset === null) {
            throw new ValidationException([new ValidationError('limit', 'limit (1..100) / offset (>=0) must be valid', 'invalid_value')]);
        }
        return [$limit, $offset];
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function view(array $e): array
    {
        $props = json_decode((string) $e['properties'], true);
        return [
            'id' => (int) $e['id'],
            'event_type' => (string) $e['event_type'],
            'user_id' => (string) $e['user_id'],
            'session_id' => (string) $e['session_id'],
            'properties' => is_array($props) ? $props : [],
            'occurred_at' => (string) $e['occurred_at'],
        ];
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        return ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : 0;
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'event not found'], 404);
    }
}
