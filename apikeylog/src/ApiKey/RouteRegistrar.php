<?php

declare(strict_types=1);

namespace ApiKey\ApiKey;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private ApiKeyRepository             $repo,
        private JsonResponseFactory          $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        // Key management (owner must be identified via owner_id in request for simplicity)
        $router->post('/keys', $this->create(...));
        $router->get('/keys', $this->list(...));
        $router->post('/keys/{id}/revoke', $this->revoke(...));
        $router->post('/keys/{id}/rotate', $this->rotate(...));

        // Protected resource endpoints (require a valid API key in X-Api-Key header)
        $router->get('/resource/read', $this->readResource(...));
        $router->post('/resource/write', $this->writeResource(...));
        $router->delete('/resource/admin', $this->adminResource(...));

        // Introspect the authenticated key
        $router->get('/auth/me', $this->me(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body        = JsonRequestBodyParser::parse($request);
        $ownerId     = isset($body['owner_id']) && is_int($body['owner_id']) ? $body['owner_id'] : 0;
        $scope       = isset($body['scope']) && is_string($body['scope']) ? $body['scope'] : 'read';
        $description = isset($body['description']) && is_string($body['description']) ? trim($body['description']) : '';
        $expiresAt   = isset($body['expires_at']) && is_string($body['expires_at']) ? $body['expires_at'] : null;

        if ($ownerId <= 0) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'owner_id', 'code' => 'required', 'message' => 'owner_id must be a positive integer.']],
            ]);
        }

        $scopeEnum = ApiKeyScope::tryFrom($scope);
        if ($scopeEnum === null) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'scope', 'code' => 'invalid', 'message' => "Unknown scope '{$scope}'. Use: read, write, admin."]],
            ]);
        }

        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $result = $this->repo->create($ownerId, $scopeEnum, $description, $now, $expiresAt);

        return $this->json->create($result->toArray(), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $params  = $request->getQueryParams();
        $ownerId = isset($params['owner_id']) && is_numeric($params['owner_id']) ? (int) $params['owner_id'] : 0;

        if ($ownerId <= 0) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'owner_id', 'code' => 'required', 'message' => 'owner_id query parameter is required.']],
            ]);
        }

        $keys = $this->repo->findByOwner($ownerId);

        return $this->json->create(['keys' => array_map(fn (ApiKey $k) => $k->toArray(), $keys)]);
    }

    private function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $routeParams = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id          = (int) ($routeParams['id'] ?? 0);
        $body        = JsonRequestBodyParser::parse($request);
        $ownerId     = isset($body['owner_id']) && is_int($body['owner_id']) ? $body['owner_id'] : 0;

        if ($ownerId <= 0) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'owner_id', 'code' => 'required', 'message' => 'owner_id is required.']],
            ]);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $key = $this->repo->revoke($id, $ownerId, $now);

        if ($key === null) {
            return $this->problems->create($request, 'not-found', 'Key not found or not owned by this owner.', 404, '');
        }

        return $this->json->create($key->toArray());
    }

    private function rotate(ServerRequestInterface $request): ResponseInterface
    {
        $routeParams = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id          = (int) ($routeParams['id'] ?? 0);
        $body        = JsonRequestBodyParser::parse($request);
        $ownerId     = isset($body['owner_id']) && is_int($body['owner_id']) ? $body['owner_id'] : 0;

        if ($ownerId <= 0) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'owner_id', 'code' => 'required', 'message' => 'owner_id is required.']],
            ]);
        }

        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $result = $this->repo->rotate($id, $ownerId, $now);

        if ($result === null) {
            return $this->problems->create($request, 'not-found', 'Key not found, not owned by this owner, or already revoked.', 404, '');
        }

        return $this->json->create($result->toArray(), 201);
    }

    // --- Protected resource endpoints ---

    private function readResource(ServerRequestInterface $request): ResponseInterface
    {
        $key = $this->requireScope($request, ApiKeyScope::Read);
        if ($key instanceof ResponseInterface) {
            return $key;
        }

        return $this->json->create(['data' => 'read-only content', 'scope' => $key->scope->value]);
    }

    private function writeResource(ServerRequestInterface $request): ResponseInterface
    {
        $key = $this->requireScope($request, ApiKeyScope::Write);
        if ($key instanceof ResponseInterface) {
            return $key;
        }

        return $this->json->create(['data' => 'write confirmed', 'scope' => $key->scope->value]);
    }

    private function adminResource(ServerRequestInterface $request): ResponseInterface
    {
        $key = $this->requireScope($request, ApiKeyScope::Admin);
        if ($key instanceof ResponseInterface) {
            return $key;
        }

        return $this->json->create(['data' => 'admin action confirmed', 'scope' => $key->scope->value]);
    }

    private function me(ServerRequestInterface $request): ResponseInterface
    {
        $key = $this->requireScope($request, ApiKeyScope::Read);
        if ($key instanceof ResponseInterface) {
            return $key;
        }

        return $this->json->create($key->toArray());
    }

    /**
     * Extract X-Api-Key header, authenticate, and check scope.
     * Returns the ApiKey on success, or an error ResponseInterface on failure.
     */
    private function requireScope(ServerRequestInterface $request, ApiKeyScope $required): ApiKey|ResponseInterface
    {
        $rawKey = $request->getHeaderLine('X-Api-Key');
        if ($rawKey === '') {
            return $this->problems->create($request, 'unauthorized', 'Missing X-Api-Key header.', 401, '');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $key = $this->repo->authenticate($rawKey, $now);

        if ($key === null) {
            return $this->problems->create($request, 'unauthorized', 'Invalid or expired API key.', 401, '');
        }

        if (!$key->scope->allows($required)) {
            return $this->problems->create($request, 'forbidden', 'Insufficient scope.', 403, '');
        }

        return $key;
    }
}
