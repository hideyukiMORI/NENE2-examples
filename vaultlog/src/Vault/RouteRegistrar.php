<?php

declare(strict_types=1);

namespace VaultLog\Vault;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const string KEY_PATTERN = '/\A[a-z0-9_-]{1,64}\z/';
    private const int MAX_VALUE_LEN = 4096;

    public function __construct(
        private readonly VaultRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/vault', $this->handleStore(...));
        $router->get('/vault', $this->handleList(...));
        $router->get('/vault/{key}', $this->handleGet(...));
        $router->delete('/vault/{key}', $this->handleDelete(...));
        $router->get('/admin/vault', $this->handleAdminListAll(...));
        $router->get('/admin/vault/{userId}', $this->handleAdminListUser(...));
    }

    private function handleStore(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $key = $body['key'] ?? null;
        $value = $body['value'] ?? null;

        $errors = [];
        if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            $errors[] = new ValidationError('key', 'key must match [a-z0-9_-]{1,64}', 'invalid_value');
        }
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_VALUE_LEN) {
            $errors[] = new ValidationError('value', 'value must be a non-empty string up to 4096 bytes', 'invalid_value');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($key) && is_string($value));

        $result = $this->repo->store($userId, $key, $value, $this->now());
        return $this->json->create(['key' => $key], $result === 'stored' ? 201 : 200);
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        $keys = $this->repo->listKeys($userId);
        return $this->json->create(['keys' => $keys, 'count' => count($keys)]);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        $key = $this->keyParam($request);
        if ($key === null) {
            return $this->notFound();
        }

        $entry = $this->repo->findEntry($userId, $key);
        if ($entry === null) {
            return $this->notFound(); // IDOR-safe: cross-user access is indistinguishable from absent
        }
        if (!$this->repo->verifyIntegrity($entry)) {
            return $this->json->create(['error' => 'Secret integrity check failed'], 500);
        }

        return $this->json->create([
            'key' => (string) $entry['key_name'],
            'value' => (string) $entry['value'],
            'updated_at' => (string) $entry['updated_at'],
        ]);
    }

    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        $key = $this->keyParam($request);
        if ($key === null || !$this->repo->delete($userId, $key)) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    private function handleAdminListAll(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->json->create(['error' => 'Admin key required'], 403);
        }
        $entries = array_map(
            static fn (array $r): array => ['user_id' => (int) $r['user_id'], 'key' => (string) $r['key_name']],
            $this->repo->adminListAll(),
        );
        return $this->json->create(['entries' => $entries, 'count' => count($entries)]);
    }

    private function handleAdminListUser(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->json->create(['error' => 'Admin key required'], 403);
        }
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['userId'] ?? '');
        if (!ctype_digit($raw) || strlen($raw) > 18) {
            return $this->notFound();
        }
        $keys = $this->repo->adminListUser((int) $raw);
        return $this->json->create(['user_id' => (int) $raw, 'keys' => $keys, 'count' => count($keys)]);
    }

    private function keyParam(ServerRequestInterface $request): ?string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $key = (string) ($params['key'] ?? '');
        return preg_match(self::KEY_PATTERN, $key) === 1 ? $key : null;
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        $provided = $request->getHeaderLine('X-Admin-Key');
        return $provided !== '' && hash_equals($this->adminKey, $provided);
    }

    private function resolveUserId(ServerRequestInterface $request): ?int
    {
        $raw = $request->getHeaderLine('X-User-Id');
        if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
