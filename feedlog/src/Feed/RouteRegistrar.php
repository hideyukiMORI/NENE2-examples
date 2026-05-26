<?php

declare(strict_types=1);

namespace FeedLog\Feed;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int DEFAULT_LIMIT = 20;
    private const int MAX_LIMIT = 50;
    private const array VALID_TYPES = ['post', 'like', 'comment', 'follow', 'share'];

    public function __construct(
        private readonly FeedRepository $repository,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/feed', $this->handleGetFeed(...));
        $router->post('/users/{userId}/activities', $this->handlePostActivity(...));
        $router->get('/users/{userId}/activities', $this->handleGetUserActivities(...));
        $router->post('/users/{followeeId}/follow', $this->handleFollow(...));
        $router->delete('/users/{followeeId}/follow', $this->handleUnfollow(...));
    }

    private function handleGetFeed(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) ($request->getHeaderLine('X-User-Id') ?: 0);
        if ($actorId === 0) {
            return $this->json->create(['error' => 'authentication required'], 401);
        }

        $query = $request->getQueryParams();
        $limit = min((int) ($query['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $beforeId = isset($query['before_id']) && $query['before_id'] !== '' ? (int) $query['before_id'] : null;

        $items = $this->repository->getFeed($actorId, $limit, $beforeId);
        $nextCursor = count($items) === $limit && $items !== [] ? (int) end($items)['id'] : null;

        return $this->json->create([
            'items' => array_map($this->formatActivity(...), $items),
            'next_cursor' => $nextCursor,
        ]);
    }

    private function handlePostActivity(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) ($request->getHeaderLine('X-User-Id') ?: 0);
        if ($actorId === 0) {
            return $this->json->create(['error' => 'authentication required'], 401);
        }

        $userId = (int) $this->routeParam($request, 'userId');
        if ($userId !== $actorId) {
            return $this->json->create(['error' => 'forbidden'], 403);
        }

        $user = $this->repository->findUserById($actorId);
        if ($user === null) {
            return $this->json->create(['error' => 'user not found'], 404);
        }

        $body = (array) (json_decode((string) $request->getBody(), true) ?? []);
        $type = isset($body['type']) && is_string($body['type']) ? $body['type'] : '';
        $summary = isset($body['summary']) && is_string($body['summary']) ? trim($body['summary']) : '';
        $isPublic = isset($body['is_public']) ? (bool) $body['is_public'] : true;
        $objectId = isset($body['object_id']) && is_int($body['object_id']) ? $body['object_id'] : null;
        $objectType = isset($body['object_type']) && is_string($body['object_type']) ? $body['object_type'] : null;

        $errors = [];
        if (!in_array($type, self::VALID_TYPES, true)) {
            $errors[] = new ValidationError('type', 'Must be one of: ' . implode(', ', self::VALID_TYPES), 'invalid');
        }
        if ($summary === '') {
            $errors[] = new ValidationError('summary', 'Summary is required.', 'required');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now = date('Y-m-d H:i:s');
        $id = $this->repository->postActivity($actorId, $type, $objectId, $objectType, $summary, $isPublic, $now);

        $activity = $this->repository->findActivityById($id);
        /** @var array<string, mixed> $activity */
        return $this->json->create($this->formatActivity(array_merge($activity, ['actor_name' => $user['name']])), 201);
    }

    private function handleGetUserActivities(ServerRequestInterface $request): ResponseInterface
    {
        $viewerId = (int) ($request->getHeaderLine('X-User-Id') ?: 0);
        if ($viewerId === 0) {
            return $this->json->create(['error' => 'authentication required'], 401);
        }

        $userId = (int) $this->routeParam($request, 'userId');
        $user = $this->repository->findUserById($userId);
        if ($user === null) {
            return $this->json->create(['error' => 'user not found'], 404);
        }

        $query = $request->getQueryParams();
        $limit = min((int) ($query['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $beforeId = isset($query['before_id']) && $query['before_id'] !== '' ? (int) $query['before_id'] : null;

        $items = $this->repository->getUserActivities($userId, $viewerId, $limit, $beforeId);
        $nextCursor = count($items) === $limit && $items !== [] ? (int) end($items)['id'] : null;

        return $this->json->create([
            'items' => array_map($this->formatActivity(...), $items),
            'next_cursor' => $nextCursor,
        ]);
    }

    private function handleFollow(ServerRequestInterface $request): ResponseInterface
    {
        $followerId = (int) ($request->getHeaderLine('X-User-Id') ?: 0);
        if ($followerId === 0) {
            return $this->json->create(['error' => 'authentication required'], 401);
        }

        $followeeId = (int) $this->routeParam($request, 'followeeId');
        if ($followerId === $followeeId) {
            return $this->json->create(['error' => 'cannot follow yourself'], 422);
        }

        $followee = $this->repository->findUserById($followeeId);
        if ($followee === null) {
            return $this->json->create(['error' => 'user not found'], 404);
        }

        if ($this->repository->isFollowing($followerId, $followeeId)) {
            return $this->json->create(['follower_id' => $followerId, 'followee_id' => $followeeId, 'following' => true]);
        }

        $now = date('Y-m-d H:i:s');
        $this->repository->follow($followerId, $followeeId, $now);

        return $this->json->create(['follower_id' => $followerId, 'followee_id' => $followeeId, 'following' => true], 201);
    }

    private function handleUnfollow(ServerRequestInterface $request): ResponseInterface
    {
        $followerId = (int) ($request->getHeaderLine('X-User-Id') ?: 0);
        if ($followerId === 0) {
            return $this->json->create(['error' => 'authentication required'], 401);
        }

        $followeeId = (int) $this->routeParam($request, 'followeeId');
        $followee = $this->repository->findUserById($followeeId);
        if ($followee === null) {
            return $this->json->create(['error' => 'user not found'], 404);
        }

        if (!$this->repository->isFollowing($followerId, $followeeId)) {
            return $this->json->create(['error' => 'not following this user'], 404);
        }

        $this->repository->unfollow($followerId, $followeeId);

        return $this->json->createEmpty(204);
    }

    private function routeParam(ServerRequestInterface $request, string $key): string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (string) ($params[$key] ?? '');
    }

    /**
     * @param array<string, mixed> $activity
     * @return array<string, mixed>
     */
    private function formatActivity(array $activity): array
    {
        return [
            'id' => (int) $activity['id'],
            'actor_id' => (int) $activity['actor_id'],
            'actor_name' => $activity['actor_name'] ?? null,
            'type' => $activity['type'],
            'object_id' => isset($activity['object_id']) ? (int) $activity['object_id'] : null,
            'object_type' => $activity['object_type'] ?? null,
            'summary' => $activity['summary'],
            'is_public' => (bool) $activity['is_public'],
            'created_at' => $activity['created_at'],
        ];
    }
}
