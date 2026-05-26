<?php

declare(strict_types=1);

namespace Token\Token;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly TokenRepository $repo,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/users/{userId}/tokens', $this->issueToken(...));
        $router->get('/users/{userId}/tokens', $this->listTokens(...));
        $router->delete('/users/{userId}/tokens/{tokenId}', $this->revokeToken(...));
        $router->post('/tokens/verify', $this->verifyToken(...));
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

    private function issueToken(ServerRequestInterface $request): ResponseInterface
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

        $body       = JsonRequestBodyParser::parse($request);
        $scopeValue = isset($body['scope']) && is_string($body['scope']) ? $body['scope'] : 'read';
        $label      = isset($body['label']) && is_string($body['label']) ? trim($body['label']) : '';
        $scope      = TokenScope::tryFrom($scopeValue);

        if ($scope === null) {
            return $this->responseFactory->create(['error' => 'invalid scope, must be read/write/admin'], 422);
        }

        $now   = date('Y-m-d H:i:s');
        $token = $this->repo->issueToken($userId, $scope, $label, $now);

        return $this->responseFactory->create([
            'token'      => $token,
            'scope'      => $scope->value,
            'label'      => $label,
            'created_at' => $now,
        ], 201);
    }

    private function listTokens(ServerRequestInterface $request): ResponseInterface
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

        $tokens = $this->repo->listTokens($userId);

        return $this->responseFactory->create(['items' => $tokens, 'count' => count($tokens)]);
    }

    private function revokeToken(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId  = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;
        $tokenId = isset($params['tokenId']) && is_numeric($params['tokenId']) ? (int) $params['tokenId'] : 0;
        $actorId = $this->resolveActorId($request);

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        if ($actorId !== $userId) {
            return $this->responseFactory->create(['error' => 'forbidden'], 403);
        }

        $token = $this->repo->findTokenById($tokenId);

        if ($token === null) {
            return $this->responseFactory->create(['error' => 'token not found'], 404);
        }

        if ($token['user_id'] !== $userId) {
            return $this->responseFactory->create(['error' => 'forbidden'], 403);
        }

        if ($token['revoked']) {
            return $this->responseFactory->create(['error' => 'token already revoked'], 409);
        }

        $this->repo->revokeToken($tokenId, date('Y-m-d H:i:s'));

        return $this->responseFactory->createEmpty(204);
    }

    private function verifyToken(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $token = isset($body['token']) && is_string($body['token']) ? trim($body['token']) : '';

        if ($token === '') {
            return $this->responseFactory->create(['error' => 'token is required'], 422);
        }

        $result = $this->repo->verifyToken($token);

        if ($result === null) {
            return $this->responseFactory->create(['valid' => false], 200);
        }

        return $this->responseFactory->create([
            'valid'   => $result['valid'],
            'user_id' => $result['user_id'],
            'scope'   => $result['scope'],
        ]);
    }

    private function resolveActorId(ServerRequestInterface $request): int
    {
        $header = $request->getHeaderLine('X-User-Id');

        return is_numeric($header) ? (int) $header : 0;
    }
}
