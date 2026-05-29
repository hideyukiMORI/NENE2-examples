<?php

declare(strict_types=1);

namespace TimeLog\Timer;

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
        private readonly TimerRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        // Static routes first so literal paths are not captured by /timers/{id}.
        $router->post('/timers/start', $this->start(...));
        $router->post('/timers/stop', $this->stop(...));
        $router->get('/timers/running', $this->running(...));
        $router->get('/timers/summary', $this->summary(...));
        $router->get('/timers', $this->list(...));
        $router->get('/timers/{id}', $this->show(...));
        $router->delete('/timers/{id}', $this->delete(...));
    }

    private function start(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $label = V::str($body['label'] ?? null, 200);
        if ($label === null || $label === '') {
            throw new ValidationException([new ValidationError('label', 'label must be a non-empty string', 'invalid_value')]);
        }
        $startTime = $this->isoOrNow($body['start_time'] ?? null, 'start_time');
        try {
            $entry = $this->repo->start($label, $startTime, $this->now());
        } catch (TimerAlreadyRunningException $e) {
            return $this->json->create(['error' => 'a timer is already running', 'running_id' => $e->runningId], 409);
        }
        return $this->json->create($entry->toArray(), 201);
    }

    private function stop(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $endTime = $this->isoOrNow($body['end_time'] ?? null, 'end_time');
        try {
            $entry = $this->repo->stop($endTime);
        } catch (NoRunningTimerException) {
            return $this->json->create(['error' => 'no timer is running'], 409);
        }
        return $this->json->create($entry->toArray());
    }

    private function running(ServerRequestInterface $request): ResponseInterface
    {
        $entry = $this->repo->findRunning();
        // Consistent shape; "no running timer" is empty state, not 404.
        if ($entry === null) {
            return $this->json->create(['running' => false, 'entry' => null]);
        }
        return $this->json->create(['running' => true, 'entry' => $entry->toArray()]);
    }

    private function summary(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $from = $this->dateParam($params, 'from');
        $to = $this->dateParam($params, 'to');
        $days = $this->repo->summary($from, $to);
        return $this->json->create(['summary' => $days, 'count' => count($days)]);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $label = null;
        if (array_key_exists('label', $params)) {
            $raw = $params['label'];
            if (!is_string($raw) || strlen($raw) > 200) {
                throw new ValidationException([new ValidationError('label', 'label filter must be a string (max 200)', 'invalid_value')]);
            }
            $label = $raw;
        }
        $date = $this->dateParam($params, 'date');
        $limit = V::queryInt($params, 'limit', 1, 100, 20);
        $offset = V::queryInt($params, 'offset', 0, 1000000, 0);
        if ($limit === null || $offset === null) {
            throw new ValidationException([new ValidationError('limit', 'limit (1..100) / offset (>=0) must be valid', 'invalid_value')]);
        }
        $entries = array_map(static fn (TimeEntry $e): array => $e->toArray(), $this->repo->list($label, $date, $limit, $offset));
        return $this->json->create(['entries' => $entries, 'count' => count($entries)]);
    }

    private function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $entry = $id === 0 ? null : $this->repo->findById($id);
        if ($entry === null) {
            return $this->notFound();
        }
        return $this->json->create($entry->toArray());
    }

    private function delete(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($id === 0 || $this->repo->delete($id) === 0) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    private function isoOrNow(mixed $raw, string $field): string
    {
        if ($raw === null) {
            return $this->now();
        }
        // V::isoDatetime は ^1.5.327 でオフセット範囲チェック済み（+25:00 等を拒否、#1352）。
        $iso = V::isoDatetime($raw);
        if ($iso === null) {
            throw new ValidationException([new ValidationError($field, "{$field} must be ISO 8601 with ±HH:MM offset", 'invalid_value')]);
        }
        return $iso;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dateParam(array $params, string $key): ?string
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }
        $raw = $params[$key];
        if (!is_string($raw) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $raw) !== 1) {
            throw new ValidationException([new ValidationError($key, "{$key} must be YYYY-MM-DD", 'invalid_value')]);
        }
        return $raw;
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

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'time entry not found'], 404);
    }
}
