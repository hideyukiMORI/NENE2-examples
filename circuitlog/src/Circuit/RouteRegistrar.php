<?php

declare(strict_types=1);

namespace Circuit\Circuit;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private CircuitBreakerRepository     $repo,
        private JsonResponseFactory          $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        // Inspect circuit state
        $router->get('/circuits/{name}', $this->getCircuit(...));

        // Simulate calling an external service through the circuit breaker
        $router->post('/circuits/{name}/call', $this->call(...));

        // Manually record outcomes (for testing / admin)
        $router->post('/circuits/{name}/success', $this->recordSuccess(...));
        $router->post('/circuits/{name}/failure', $this->recordFailure(...));

        // Reset circuit to closed
        $router->post('/circuits/{name}/reset', $this->reset(...));
    }

    private function getCircuit(ServerRequestInterface $request): ResponseInterface
    {
        $name    = $this->circuitName($request);
        $now     = $this->now();
        $circuit = $this->repo->findByName($name);

        if ($circuit === null) {
            return $this->problems->create($request, 'not-found', 'Circuit not found.', 404, '');
        }

        // Eagerly transition Open → Half-Open if timeout elapsed
        $circuit = $this->repo->maybeTransitionToHalfOpen($name, $now);

        return $this->json->create($circuit->toArray());
    }

    private function call(ServerRequestInterface $request): ResponseInterface
    {
        $name    = $this->circuitName($request);
        $body    = JsonRequestBodyParser::parse($request);
        $success = isset($body['success']) && is_bool($body['success']) ? $body['success'] : true;
        $now     = $this->now();

        // Get or create the circuit with threshold from body
        $threshold = isset($body['threshold']) && is_int($body['threshold']) && $body['threshold'] > 0
            ? $body['threshold']
            : 5;
        $timeout   = isset($body['timeout_seconds']) && is_int($body['timeout_seconds']) && $body['timeout_seconds'] > 0
            ? $body['timeout_seconds']
            : 30;

        $circuit = $this->repo->findOrCreate($name, $threshold, $now);
        $circuit = $this->repo->maybeTransitionToHalfOpen($name, $now);

        if (!$circuit->isCallAllowed($now)) {
            return $this->problems->create($request, 'service-unavailable', 'Circuit is open — calls are blocked.', 503, null, [
                'circuit'    => $circuit->toArray(),
                'open_until' => $circuit->openUntil,
            ]);
        }

        if ($success) {
            $circuit = $this->repo->recordSuccess($name, $now);

            return $this->json->create(['result' => 'success', 'circuit' => $circuit->toArray()]);
        }

        $circuit = $this->repo->recordFailure($name, $now, $timeout);

        return $this->json->create(['result' => 'failure', 'circuit' => $circuit->toArray()]);
    }

    private function recordSuccess(ServerRequestInterface $request): ResponseInterface
    {
        $name    = $this->circuitName($request);
        $now     = $this->now();
        $circuit = $this->repo->findOrCreate($name, 5, $now);
        $circuit = $this->repo->recordSuccess($name, $now);

        return $this->json->create($circuit->toArray());
    }

    private function recordFailure(ServerRequestInterface $request): ResponseInterface
    {
        $name    = $this->circuitName($request);
        $body    = JsonRequestBodyParser::parse($request);
        $timeout = isset($body['timeout_seconds']) && is_int($body['timeout_seconds']) ? $body['timeout_seconds'] : 30;
        $now     = $this->now();

        $this->repo->findOrCreate($name, 5, $now);
        $circuit = $this->repo->recordFailure($name, $now, $timeout);

        return $this->json->create($circuit->toArray());
    }

    private function reset(ServerRequestInterface $request): ResponseInterface
    {
        $name    = $this->circuitName($request);
        $now     = $this->now();
        $circuit = $this->repo->findByName($name);

        if ($circuit === null) {
            return $this->problems->create($request, 'not-found', 'Circuit not found.', 404, '');
        }

        $this->repo->recordSuccess($name, $now);
        $circuit = $this->repo->findByName($name);

        return $this->json->create(($circuit ?? $this->repo->findOrCreate($name, 5, $now))->toArray());
    }

    private function circuitName(ServerRequestInterface $request): string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);

        return urldecode((string) ($params['name'] ?? ''));
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
