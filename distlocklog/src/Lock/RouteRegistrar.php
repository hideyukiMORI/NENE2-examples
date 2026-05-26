<?php

declare(strict_types=1);

namespace Lock\Lock;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private LockRepository                $repo,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/locks/{resource}', $this->acquire(...));
        $router->get('/locks/{resource}', $this->get(...));
        $router->delete('/locks/{resource}', $this->release(...));
        $router->post('/locks/{resource}/renew', $this->renew(...));
    }

    private function acquire(ServerRequestInterface $request): ResponseInterface
    {
        $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $resource   = (string) ($params['resource'] ?? '');
        $body       = JsonRequestBodyParser::parse($request);
        $owner      = isset($body['owner']) && is_string($body['owner']) ? trim($body['owner']) : '';
        $ttlSeconds = isset($body['ttl_seconds']) && is_int($body['ttl_seconds']) ? $body['ttl_seconds'] : null;

        if ($owner === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'owner', 'code' => 'required', 'message' => 'owner is required.']],
            ]);
        }

        if ($ttlSeconds === null || $ttlSeconds < 1) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'ttl_seconds', 'code' => 'invalid', 'message' => 'ttl_seconds must be a positive integer.']],
            ]);
        }

        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');
        $lock      = $this->repo->acquire($resource, $owner, $expiresAt, $now);

        if ($lock === null) {
            return $this->json->create(['acquired' => false, 'resource' => $resource]);
        }

        return $this->json->create(['acquired' => true, 'lock' => $lock->toArray()]);
    }

    private function get(ServerRequestInterface $request): ResponseInterface
    {
        $params   = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $resource = (string) ($params['resource'] ?? '');
        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $lock     = $this->repo->findByResource($resource);

        if ($lock === null || $lock->isExpired($now)) {
            return $this->problems->create($request, 'not-found', 'Lock not found or expired.', 404, '');
        }

        return $this->json->create($lock->toArray());
    }

    private function release(ServerRequestInterface $request): ResponseInterface
    {
        $params   = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $resource = (string) ($params['resource'] ?? '');
        $body     = JsonRequestBodyParser::parse($request);
        $owner    = isset($body['owner']) && is_string($body['owner']) ? trim($body['owner']) : '';

        if ($owner === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'owner', 'code' => 'required', 'message' => 'owner is required.']],
            ]);
        }

        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $result = $this->repo->release($resource, $owner, $now);

        return match ($result) {
            ReleaseResult::Released  => $this->json->create([], 204),
            ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404, ''),
            ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch — cannot release lock held by another owner.', 403, ''),
        };
    }

    private function renew(ServerRequestInterface $request): ResponseInterface
    {
        $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $resource   = (string) ($params['resource'] ?? '');
        $body       = JsonRequestBodyParser::parse($request);
        $owner      = isset($body['owner']) && is_string($body['owner']) ? trim($body['owner']) : '';
        $ttlSeconds = isset($body['ttl_seconds']) && is_int($body['ttl_seconds']) ? $body['ttl_seconds'] : null;

        if ($owner === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'owner', 'code' => 'required', 'message' => 'owner is required.']],
            ]);
        }

        if ($ttlSeconds === null || $ttlSeconds < 1) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'ttl_seconds', 'code' => 'invalid', 'message' => 'ttl_seconds must be a positive integer.']],
            ]);
        }

        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');
        $lock      = $this->repo->renew($resource, $owner, $expiresAt, $now);

        if ($lock === null) {
            return $this->problems->create($request, 'conflict', 'Cannot renew: lock not held by this owner or has expired.', 409, '');
        }

        return $this->json->create($lock->toArray());
    }
}
