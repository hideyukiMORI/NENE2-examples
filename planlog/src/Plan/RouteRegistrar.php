<?php

declare(strict_types=1);

namespace Plan\Plan;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly PlanRepository $repo,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->get('/plans', $this->listPlans(...));
        $router->post('/users/{userId}/subscription', $this->subscribe(...));
        $router->get('/users/{userId}/subscription', $this->getSubscription(...));
        $router->put('/users/{userId}/subscription', $this->changePlan(...));
        $router->delete('/users/{userId}/subscription', $this->cancel(...));
    }

    private function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);
        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';

        if ($name === '') {
            return $this->responseFactory->create(['error' => 'name is required'], 422);
        }

        $now    = date('Y-m-d H:i:s');
        $userId = $this->repo->createUser($name, $now);

        return $this->responseFactory->create(['id' => $userId, 'name' => $name], 201);
    }

    private function listPlans(ServerRequestInterface $request): ResponseInterface
    {
        $plans = $this->repo->listPlans();

        return $this->responseFactory->create(['items' => $plans, 'count' => count($plans)]);
    }

    private function subscribe(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId  = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;
        $actorId = $this->resolveActorId($request);

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        if ($actorId !== $userId) {
            return $this->responseFactory->create(['error' => 'forbidden'], 403);
        }

        $existing = $this->repo->findSubscription($userId);

        if ($existing !== null && $existing['status'] === 'active') {
            return $this->responseFactory->create(['error' => 'already subscribed, use PUT to change plan'], 409);
        }

        $body = JsonRequestBodyParser::parse($request);
        $slug = isset($body['plan']) && is_string($body['plan']) ? trim($body['plan']) : '';
        $plan = $this->repo->findPlanBySlug($slug);

        if ($plan === null) {
            return $this->responseFactory->create(['error' => 'plan not found'], 404);
        }

        $now = date('Y-m-d H:i:s');
        $this->repo->subscribe($userId, $plan['id'], $now);

        return $this->responseFactory->create($this->repo->findSubscription($userId) ?? [], 201);
    }

    private function getSubscription(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId  = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;
        $actorId = $this->resolveActorId($request);

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        if ($actorId !== $userId) {
            return $this->responseFactory->create(['error' => 'forbidden'], 403);
        }

        $sub = $this->repo->findSubscription($userId);

        if ($sub === null) {
            return $this->responseFactory->create(['error' => 'no subscription'], 404);
        }

        return $this->responseFactory->create($sub);
    }

    private function changePlan(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId  = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;
        $actorId = $this->resolveActorId($request);

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        if ($actorId !== $userId) {
            return $this->responseFactory->create(['error' => 'forbidden'], 403);
        }

        $sub = $this->repo->findSubscription($userId);

        if ($sub === null) {
            return $this->responseFactory->create(['error' => 'no subscription, use POST to subscribe'], 404);
        }

        if ($sub['status'] === 'cancelled') {
            return $this->responseFactory->create(['error' => 'subscription is cancelled, use POST to re-subscribe'], 409);
        }

        $body = JsonRequestBodyParser::parse($request);
        $slug = isset($body['plan']) && is_string($body['plan']) ? trim($body['plan']) : '';
        $plan = $this->repo->findPlanBySlug($slug);

        if ($plan === null) {
            return $this->responseFactory->create(['error' => 'plan not found'], 404);
        }

        $now = date('Y-m-d H:i:s');
        $this->repo->changePlan($userId, $plan['id'], $now);

        return $this->responseFactory->create($this->repo->findSubscription($userId) ?? []);
    }

    private function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId  = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;
        $actorId = $this->resolveActorId($request);

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        if ($actorId !== $userId) {
            return $this->responseFactory->create(['error' => 'forbidden'], 403);
        }

        $cancelled = $this->repo->cancel($userId, date('Y-m-d H:i:s'));

        if (!$cancelled) {
            return $this->responseFactory->create(['error' => 'no active subscription to cancel'], 404);
        }

        return $this->responseFactory->createEmpty(204);
    }

    private function resolveActorId(ServerRequestInterface $request): int
    {
        $header = $request->getHeaderLine('X-User-Id');

        return is_numeric($header) ? (int) $header : 0;
    }
}
