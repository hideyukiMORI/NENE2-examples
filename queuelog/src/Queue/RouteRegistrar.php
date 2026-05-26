<?php

declare(strict_types=1);

namespace Queue;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private SqliteJobRepository          $repo,
        private JsonResponseFactory          $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/jobs', $this->create(...));
        $router->get('/jobs', $this->list(...));
        $router->get('/jobs/{id}', $this->get(...));
        $router->post('/jobs/claim', $this->claim(...));
        $router->post('/jobs/{id}/complete', $this->complete(...));
        $router->post('/jobs/{id}/fail', $this->fail(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        $type           = isset($body['type']) && is_string($body['type']) ? trim($body['type']) : '';
        $payload        = isset($body['payload']) && is_array($body['payload']) ? $body['payload'] : [];
        $priority       = isset($body['priority']) && is_string($body['priority']) ? $body['priority'] : 'medium';
        $idempotencyKey = isset($body['idempotency_key']) && is_string($body['idempotency_key']) && $body['idempotency_key'] !== ''
            ? $body['idempotency_key']
            : null;
        $maxRetries     = isset($body['max_retries']) && is_int($body['max_retries']) && $body['max_retries'] >= 0
            ? $body['max_retries']
            : 3;

        if ($type === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'type', 'code' => 'required', 'message' => 'Job type is required.']],
            ]);
        }

        try {
            $p = JobPriority::fromLabel($priority);
        } catch (\InvalidArgumentException) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'priority', 'code' => 'invalid', 'message' => "Unknown priority '{$priority}'. Use: low, medium, high, critical."]],
            ]);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($idempotencyKey !== null) {
            $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $this->json->create($existing->toArray(), 200);
            }
        }

        $job = $this->repo->create($type, json_encode($payload, JSON_THROW_ON_ERROR), $p, $now, $idempotencyKey, $maxRetries);

        return $this->json->create($job->toArray(), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $status = null;
        if (isset($params['status']) && is_string($params['status'])) {
            $status = JobStatus::tryFrom($params['status']);
            if ($status === null) {
                return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                    'errors' => [['field' => 'status', 'code' => 'invalid', 'message' => "Unknown status '{$params['status']}'. Use: pending, running, completed, failed."]],
                ]);
            }
        }

        $jobs = $this->repo->list($status);

        return $this->json->create(['jobs' => array_map(fn (Job $j) => $j->toArray(), $jobs)]);
    }

    private function get(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $job    = $this->repo->findById($id);

        if ($job === null) {
            return $this->problems->create($request, 'not-found', 'Job not found.', 404, '');
        }

        return $this->json->create($job->toArray());
    }

    private function claim(ServerRequestInterface $request): ResponseInterface
    {
        $body     = JsonRequestBodyParser::parse($request);
        $workerId = isset($body['worker_id']) && is_string($body['worker_id']) ? trim($body['worker_id']) : '';

        if ($workerId === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'worker_id', 'code' => 'required', 'message' => 'worker_id is required.']],
            ]);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $job = $this->repo->claim($workerId, $now);

        if ($job === null) {
            return $this->problems->create($request, 'no-pending-jobs', 'No pending jobs available.', 404, '');
        }

        return $this->json->create($job->toArray());
    }

    private function complete(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $job    = $this->repo->complete($id, $now);

        if ($job === null) {
            return $this->problems->create($request, 'conflict', 'Job not found or not in running state.', 409, '');
        }

        return $this->json->create($job->toArray());
    }

    private function fail(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $body   = JsonRequestBodyParser::parse($request);
        $error  = isset($body['error']) && is_string($body['error']) ? trim($body['error']) : 'Unknown error';

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $job = $this->repo->fail($id, $error, $now);

        if ($job === null) {
            return $this->problems->create($request, 'conflict', 'Job not found or not in running state.', 409, '');
        }

        return $this->json->create($job->toArray());
    }
}
