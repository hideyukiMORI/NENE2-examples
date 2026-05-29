<?php

declare(strict_types=1);

namespace RateLog\Rate;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int MAX_ENDPOINT_LEN = 128;

    public function __construct(
        private readonly RateRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
        private readonly int $limit,
        private readonly int $windowSeconds,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/rate/check', $this->check(...));
        $router->get('/rate/status', $this->status(...));
        $router->delete('/rate/reset/{userId}', $this->reset(...));
    }

    private function check(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->headerUserId($request);
        if ($userId === null) {
            return $this->badRequest('valid X-User-Id header required'); // ATK-01
        }
        // ATK-11: a completely absent body is a 400, distinct from an empty endpoint.
        if ($request->getParsedBody() === null) {
            return $this->badRequest('request body required');
        }
        $body = (array) $request->getParsedBody();
        $endpoint = $this->endpoint($body['endpoint'] ?? null); // ATK-02/03/12

        $now = $this->now($request);
        $since = $this->windowStart($now);
        $count = $this->repo->countInWindow($userId, $endpoint, $since);

        if ($count >= $this->limit) {
            // ATK: over the limit → 429, and the rejected request is NOT recorded.
            return $this->json->create([
                'status' => 'rate_limited',
                'limit' => $this->limit,
                'window_seconds' => $this->windowSeconds,
                'retry_after_seconds' => $this->windowSeconds,
            ], 429);
        }

        $this->repo->record($userId, $endpoint, $now);
        return $this->json->create([
            'status' => 'ok',
            'count' => $count + 1,
            'limit' => $this->limit,
            'remaining' => $this->limit - ($count + 1),
        ]);
    }

    private function status(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->headerUserId($request);
        if ($userId === null) {
            return $this->badRequest('valid X-User-Id header required');
        }
        $params = $request->getQueryParams();
        $endpoint = $this->endpoint($params['endpoint'] ?? null); // ATK-10
        $now = $this->now($request);
        $count = $this->repo->countInWindow($userId, $endpoint, $this->windowStart($now));
        return $this->json->create([
            'endpoint' => $endpoint,
            'count' => $count,
            'limit' => $this->limit,
            'remaining' => max(0, $this->limit - $count),
            'window_seconds' => $this->windowSeconds,
        ]);
    }

    private function reset(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden(); // ATK-05/06
        }
        $userId = $this->pathUserId($request);
        if ($userId === 0) {
            return $this->notFound(); // ATK-07/08/09
        }
        $params = $request->getQueryParams();
        if (isset($params['endpoint']) && is_string($params['endpoint']) && $params['endpoint'] !== '') {
            $removed = $this->repo->resetUserEndpoint($userId, $params['endpoint']);
        } else {
            $removed = $this->repo->resetUser($userId);
        }
        return $this->json->create(['reset' => true, 'removed' => $removed]);
    }

    /** @return positive-int|null */
    private function headerUserId(ServerRequestInterface $request): ?int
    {
        $raw = $request->getHeaderLine('X-User-Id');
        if (!ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function pathUserId(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['userId'] ?? '');
        if (!ctype_digit($raw) || strlen($raw) > 18) {
            return 0;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : 0;
    }

    private function endpoint(mixed $raw): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw new ValidationException([new ValidationError('endpoint', 'endpoint must be a non-empty string', 'invalid_value')]);
        }
        if (mb_strlen($raw) > self::MAX_ENDPOINT_LEN) {
            throw new ValidationException([new ValidationError('endpoint', 'endpoint too long (max 128)', 'invalid_value')]);
        }
        return $raw;
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false; // fail-closed
        }
        return hash_equals($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
    }

    /**
     * Window start = now - WINDOW seconds. An `X-Now` header overrides the clock
     * (test/worker seam) so sliding-window behaviour is deterministically testable.
     */
    private function windowStart(string $now): string
    {
        return (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
            ->modify("-{$this->windowSeconds} seconds")
            ->format('Y-m-d H:i:s');
    }

    private function now(ServerRequestInterface $request): string
    {
        $override = $request->getHeaderLine('X-Now');
        if ($override !== '') {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $override, new \DateTimeZone('UTC'));
            if ($dt !== false && $dt->format('Y-m-d H:i:s') === $override) {
                return $override;
            }
            throw new ValidationException([new ValidationError('X-Now', 'X-Now must be Y-m-d H:i:s', 'invalid_value')]);
        }
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function badRequest(string $message): ResponseInterface
    {
        return $this->json->create(['error' => $message], 400);
    }

    private function forbidden(): ResponseInterface
    {
        return $this->json->create(['error' => 'admin key required'], 403);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'user not found'], 404);
    }
}
