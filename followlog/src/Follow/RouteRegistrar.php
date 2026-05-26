<?php

declare(strict_types=1);

namespace Follow\Follow;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly FollowRepository $repo,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/users/{followerId}/follow', $this->followUser(...));
        $router->delete('/users/{followerId}/follow/{followeeId}', $this->unfollowUser(...));
        $router->get('/users/{userId}/followers', $this->listFollowers(...));
        $router->get('/users/{userId}/following', $this->listFollowing(...));
        $router->get('/users/{userId}/stats', $this->stats(...));
        $router->get('/users/{followerId}/is-following/{followeeId}', $this->isFollowing(...));
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

    private function followUser(ServerRequestInterface $request): ResponseInterface
    {
        $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $followerId = isset($params['followerId']) && is_numeric($params['followerId']) ? (int) $params['followerId'] : 0;

        if ($followerId <= 0 || !$this->repo->findUserById($followerId)) {
            return $this->responseFactory->create(['error' => 'follower not found'], 404);
        }

        $body       = JsonRequestBodyParser::parse($request);
        $followeeId = isset($body['followee_id']) && is_int($body['followee_id']) ? $body['followee_id'] : 0;

        if ($followeeId <= 0 || !$this->repo->findUserById($followeeId)) {
            return $this->responseFactory->create(['error' => 'followee not found'], 404);
        }

        if ($followerId === $followeeId) {
            return $this->responseFactory->create(['error' => 'cannot follow yourself'], 422);
        }

        $now      = date('Y-m-d H:i:s');
        $wasNew   = $this->repo->follow($followerId, $followeeId, $now);
        $status   = $wasNew ? 201 : 200;

        return $this->responseFactory->create([
            'follower_id' => $followerId,
            'followee_id' => $followeeId,
            'following'   => true,
        ], $status);
    }

    private function unfollowUser(ServerRequestInterface $request): ResponseInterface
    {
        $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $followerId = isset($params['followerId']) && is_numeric($params['followerId']) ? (int) $params['followerId'] : 0;
        $followeeId = isset($params['followeeId']) && is_numeric($params['followeeId']) ? (int) $params['followeeId'] : 0;

        if ($followerId <= 0 || !$this->repo->findUserById($followerId)) {
            return $this->responseFactory->create(['error' => 'follower not found'], 404);
        }

        $removed = $this->repo->unfollow($followerId, $followeeId);

        if (!$removed) {
            return $this->responseFactory->create(['error' => 'not following'], 404);
        }

        return $this->responseFactory->createEmpty(204);
    }

    private function listFollowers(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $users = $this->repo->listFollowers($userId);

        return $this->responseFactory->create(['items' => $users, 'count' => count($users)]);
    }

    private function listFollowing(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $users = $this->repo->listFollowing($userId);

        return $this->responseFactory->create(['items' => $users, 'count' => count($users)]);
    }

    private function stats(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        return $this->responseFactory->create([
            'user_id'         => $userId,
            'followers_count' => $this->repo->followerCount($userId),
            'following_count' => $this->repo->followingCount($userId),
        ]);
    }

    private function isFollowing(ServerRequestInterface $request): ResponseInterface
    {
        $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $followerId = isset($params['followerId']) && is_numeric($params['followerId']) ? (int) $params['followerId'] : 0;
        $followeeId = isset($params['followeeId']) && is_numeric($params['followeeId']) ? (int) $params['followeeId'] : 0;

        if ($followerId <= 0 || !$this->repo->findUserById($followerId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        if ($followeeId <= 0 || !$this->repo->findUserById($followeeId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        return $this->responseFactory->create([
            'follower_id' => $followerId,
            'followee_id' => $followeeId,
            'following'   => $this->repo->isFollowing($followerId, $followeeId),
        ]);
    }
}
