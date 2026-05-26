<?php

declare(strict_types=1);

namespace PrefLog\Pref;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly PrefRepository $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->get('/users/{id}/preferences', $this->handleList(...));
        $this->router->put('/users/{id}/preferences/{key}', $this->handleUpsert(...));
        $this->router->delete('/users/{id}/preferences/{key}', $this->handleDelete(...));
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $userId = isset($params['id']) && ctype_digit((string) $params['id']) ? (int) $params['id'] : 0;

        if ($userId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid user id'], 422);
        }

        $user = $this->repository->findUserById($userId);
        if ($user === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        // Build preferences map: stored values override defaults
        $stored = [];
        foreach ($this->repository->findAllPreferences($userId) as $row) {
            $arr = (array) $row;
            if (isset($arr['pref_key']) && is_string($arr['pref_key'])) {
                $stored[$arr['pref_key']] = $arr;
            }
        }

        $preferences = [];
        foreach (PreferenceKey::cases() as $key) {
            $storedRow = $stored[$key->value] ?? null;
            $preferences[] = [
                'key' => $key->value,
                'value' => $storedRow !== null ? (string) $storedRow['pref_value'] : $key->defaultValue(),
                'is_default' => $storedRow === null,
                'updated_at' => $storedRow !== null ? (string) $storedRow['updated_at'] : null,
            ];
        }

        return $this->responseFactory->create(['preferences' => $preferences], 200);
    }

    private function handleUpsert(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $userId = isset($params['id']) && ctype_digit((string) $params['id']) ? (int) $params['id'] : 0;
        $keyStr = isset($params['key']) && is_string($params['key']) ? $params['key'] : '';

        if ($userId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid user id'], 422);
        }

        $prefKey = PreferenceKey::tryFrom($keyStr);
        if ($prefKey === null) {
            return $this->responseFactory->create(['error' => 'unknown preference key', 'valid_keys' => array_column(PreferenceKey::cases(), 'value')], 422);
        }

        $actorId = (int) $request->getHeaderLine('X-User-Id');
        if ($actorId !== $userId) {
            return $this->responseFactory->create(['error' => 'cannot modify another user\'s preferences'], 403);
        }

        $user = $this->repository->findUserById($userId);
        if ($user === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $body = JsonRequestBodyParser::parse($request);
        $value = isset($body['value']) && is_string($body['value']) ? $body['value'] : null;
        if ($value === null) {
            return $this->responseFactory->create(['error' => 'value is required and must be a string'], 422);
        }

        if (!$prefKey->validate($value)) {
            return $this->responseFactory->create(['error' => 'invalid value for preference key ' . $keyStr], 422);
        }

        $now = date('c');
        $this->repository->upsertPreference($userId, $prefKey->value, $value, $now);

        return $this->responseFactory->create([
            'key' => $prefKey->value,
            'value' => $value,
            'updated_at' => $now,
        ], 200);
    }

    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $userId = isset($params['id']) && ctype_digit((string) $params['id']) ? (int) $params['id'] : 0;
        $keyStr = isset($params['key']) && is_string($params['key']) ? $params['key'] : '';

        if ($userId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid user id'], 422);
        }

        $prefKey = PreferenceKey::tryFrom($keyStr);
        if ($prefKey === null) {
            return $this->responseFactory->create(['error' => 'unknown preference key'], 422);
        }

        $actorId = (int) $request->getHeaderLine('X-User-Id');
        if ($actorId !== $userId) {
            return $this->responseFactory->create(['error' => 'cannot modify another user\'s preferences'], 403);
        }

        $user = $this->repository->findUserById($userId);
        if ($user === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $this->repository->deletePreference($userId, $prefKey->value);

        // Return the default value after reset
        return $this->responseFactory->create([
            'key' => $prefKey->value,
            'value' => $prefKey->defaultValue(),
            'is_default' => true,
        ], 200);
    }
}
