<?php

declare(strict_types=1);

namespace DeadLetterLog\Queue;

use Nene2\Database\DatabaseTransactionManagerInterface;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int MIN_RETRIES = 1;
    private const int MAX_RETRIES = 10;

    public function __construct(
        private readonly QueueRepository $repo,
        private readonly DatabaseTransactionManagerInterface $tx,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/queues/{queue}/messages', $this->enqueue(...));
        $router->get('/queues/{queue}/messages', $this->list(...));
        $router->post('/queues/{queue}/claim', $this->claim(...));
        $router->get('/queues/{queue}/messages/{id}', $this->show(...));
        $router->post('/queues/{queue}/messages/{id}/succeed', $this->succeed(...));
        $router->post('/queues/{queue}/messages/{id}/fail', $this->fail(...));
        $router->post('/queues/{queue}/messages/{id}/replay', $this->replay(...));
    }

    private function enqueue(ServerRequestInterface $request): ResponseInterface
    {
        $queue = $this->queueParam($request);
        if ($queue === null) {
            return $this->notFound('unknown queue name');
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $payload = $body['payload'] ?? null;
        if (!is_string($payload) || $payload === '' || strlen($payload) > 65535) {
            throw new ValidationException([new ValidationError('payload', 'payload must be a non-empty string', 'invalid_value')]);
        }
        $maxRetries = 3;
        if (array_key_exists('max_retries', $body)) {
            if (!is_int($body['max_retries']) || $body['max_retries'] < self::MIN_RETRIES || $body['max_retries'] > self::MAX_RETRIES) {
                throw new ValidationException([new ValidationError('max_retries', 'max_retries must be an integer 1..10', 'invalid_value')]);
            }
            $maxRetries = $body['max_retries'];
        }
        $id = $this->repo->enqueue($queue, $payload, $maxRetries, $this->now($request));
        return $this->json->create($this->view($id, $queue), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $queue = $this->queueParam($request);
        if ($queue === null) {
            return $this->notFound('unknown queue name');
        }
        $params = $request->getQueryParams();
        $status = null;
        if (array_key_exists('status', $params)) {
            $raw = $params['status'];
            if (!is_string($raw) || MessageStatus::tryFrom($raw) === null) {
                throw new ValidationException([new ValidationError('status', 'status must be pending|processing|succeeded|dead', 'invalid_value')]);
            }
            $status = $raw;
        }
        $limit = $this->intParam($params, 'limit', 1, 100, 20);
        $offset = $this->intParam($params, 'offset', 0, 1000000, 0);
        if ($limit === null || $offset === null) {
            throw new ValidationException([new ValidationError('limit', 'limit (1..100) / offset (>=0) must be valid', 'invalid_value')]);
        }
        $messages = array_map(static fn (Message $m): array => $m->toArray(), $this->repo->list($queue, $status, $limit, $offset));
        return $this->json->create(['messages' => $messages, 'count' => count($messages)]);
    }

    private function claim(ServerRequestInterface $request): ResponseInterface
    {
        $queue = $this->queueParam($request);
        if ($queue === null) {
            return $this->notFound('unknown queue name');
        }
        $now = $this->now($request);
        // Atomic claim — SELECT + UPDATE wrapped in a transaction so two workers
        // can't grab the same message.
        $claimed = null;
        $this->tx->transactional(function ($executor) use (&$claimed, $queue, $now): void {
            $claimed = (new QueueRepository($executor))->claimInTransaction($queue, $now);
        });
        if ($claimed === null) {
            return $this->json->create(['claimed' => false, 'message' => null]);
        }
        return $this->json->create(['claimed' => true, 'message' => $claimed->toArray()]);
    }

    private function show(ServerRequestInterface $request): ResponseInterface
    {
        $queue = $this->queueParam($request);
        $id = $this->idParam($request);
        $msg = ($queue === null || $id === 0) ? null : $this->repo->find($id, $queue);
        if ($msg === null) {
            return $this->notFound('message not found');
        }
        return $this->json->create($msg->toArray());
    }

    private function succeed(ServerRequestInterface $request): ResponseInterface
    {
        return $this->transition($request, fn (int $id, string $queue): ?Message => $this->repo->succeed($id, $queue, $this->now($request)), 'only processing messages can succeed');
    }

    private function fail(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $error = is_string($body['error'] ?? null) ? substr((string) $body['error'], 0, 1000) : 'unspecified error';
        return $this->transition($request, fn (int $id, string $queue): ?Message => $this->repo->fail($id, $queue, $error, $this->now($request)), 'only processing messages can fail');
    }

    private function replay(ServerRequestInterface $request): ResponseInterface
    {
        return $this->transition($request, fn (int $id, string $queue): ?Message => $this->repo->replay($id, $queue, $this->now($request)), 'only dead messages can be replayed');
    }

    /**
     * Shared transition flow: resolve message, run the operation, map a null
     * (wrong state / missing) to 404 when absent or 409 when the state is wrong.
     *
     * @param callable(int, string): ?Message $op
     */
    private function transition(ServerRequestInterface $request, callable $op, string $conflictMessage): ResponseInterface
    {
        $queue = $this->queueParam($request);
        $id = $this->idParam($request);
        if ($queue === null || $id === 0 || $this->repo->find($id, $queue) === null) {
            return $this->notFound('message not found');
        }
        $result = $op($id, $queue);
        if ($result === null) {
            return $this->json->create(['error' => $conflictMessage], 409); // wrong state
        }
        return $this->json->create($result->toArray());
    }

    /** Queues are created implicitly; the name just has to be a safe identifier. */
    private function queueParam(ServerRequestInterface $request): ?string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['queue'] ?? '');
        return preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $raw) === 1 ? $raw : null;
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        return ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function intParam(array $params, string $key, int $min, int $max, int $default): ?int
    {
        if (!array_key_exists($key, $params)) {
            return $default;
        }
        $raw = $params[$key];
        if (!is_string($raw) || !ctype_digit($raw)) {
            return null;
        }
        $n = (int) $raw;
        return $n >= $min && $n <= $max ? $n : null;
    }

    /**
     * Current time as 'Y-m-d H:i:s' (UTC). An `X-Now` header overrides it (a test /
     * worker clock seam) so backoff scheduling is deterministically observable.
     */
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

    /** @return array<string, mixed> */
    private function view(int $id, string $queue): array
    {
        $msg = $this->repo->find($id, $queue);
        return $msg !== null ? $msg->toArray() : [];
    }

    private function notFound(string $message): ResponseInterface
    {
        return $this->json->create(['error' => $message], 404);
    }
}
