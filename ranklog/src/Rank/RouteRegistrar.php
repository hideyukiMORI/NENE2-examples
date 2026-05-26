<?php

declare(strict_types=1);

namespace Rank\Rank;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly RankRepository    $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/leaderboards', $this->createLeaderboard(...));
        $router->post('/leaderboards/{leaderboardId}/scores', $this->submitScore(...));
        $router->get('/leaderboards/{leaderboardId}/rankings', $this->getRankings(...));
        $router->get('/leaderboards/{leaderboardId}/rankings/me', $this->getMyRank(...));
        $router->delete('/leaderboards/{leaderboardId}/scores/{userId}', $this->deleteScore(...));
    }

    private function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
            return $this->responseFactory->create(['error' => 'name is required'], 422);
        }

        $id = $this->repository->createUser(trim($body['name']), date('c'));

        return $this->responseFactory->create(['id' => $id], 201);
    }

    private function createLeaderboard(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
            return $this->responseFactory->create(['error' => 'name is required'], 422);
        }

        $id = $this->repository->createLeaderboard(trim($body['name']), date('c'));

        return $this->responseFactory->create(['id' => $id], 201);
    }

    private function submitScore(ServerRequestInterface $request): ResponseInterface
    {
        $body   = JsonRequestBodyParser::parse($request);
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);

        $leaderboardId = isset($params['leaderboardId']) ? (int) $params['leaderboardId'] : 0;

        if ($leaderboardId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid leaderboardId'], 404);
        }

        $leaderboard = $this->repository->findLeaderboardById($leaderboardId);

        if ($leaderboard === null) {
            return $this->responseFactory->create(['error' => 'leaderboard not found'], 404);
        }

        if (!isset($body['user_id']) || !is_int($body['user_id']) || $body['user_id'] <= 0) {
            return $this->responseFactory->create(['error' => 'user_id is required'], 422);
        }

        if (!$this->repository->findUserById($body['user_id'])) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        if (!isset($body['score']) || !is_int($body['score'])) {
            return $this->responseFactory->create(['error' => 'score must be an integer'], 422);
        }

        $isNewBest = $this->repository->submitScore($leaderboardId, $body['user_id'], $body['score'], date('c'));

        return $this->responseFactory->create([
            'ok'          => true,
            'new_best'    => $isNewBest,
        ], 200);
    }

    private function getRankings(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);

        $leaderboardId = isset($params['leaderboardId']) ? (int) $params['leaderboardId'] : 0;

        if ($leaderboardId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid leaderboardId'], 404);
        }

        $leaderboard = $this->repository->findLeaderboardById($leaderboardId);

        if ($leaderboard === null) {
            return $this->responseFactory->create(['error' => 'leaderboard not found'], 404);
        }

        $query = $request->getQueryParams();
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 10;

        if ($limit <= 0 || $limit > 100) {
            $limit = 10;
        }

        $rankings = $this->repository->getRankings($leaderboardId, $limit);

        return $this->responseFactory->create([
            'items' => $rankings,
            'count' => count($rankings),
        ], 200);
    }

    private function getMyRank(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');

        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 400);
        }

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);

        $leaderboardId = isset($params['leaderboardId']) ? (int) $params['leaderboardId'] : 0;

        if ($leaderboardId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid leaderboardId'], 404);
        }

        $leaderboard = $this->repository->findLeaderboardById($leaderboardId);

        if ($leaderboard === null) {
            return $this->responseFactory->create(['error' => 'leaderboard not found'], 404);
        }

        $score = $this->repository->findScore($leaderboardId, $actorId);

        if ($score === null) {
            return $this->responseFactory->create(['error' => 'no score submitted yet'], 404);
        }

        $rank = $this->repository->getUserRank($leaderboardId, $actorId);

        return $this->responseFactory->create([
            'user_id' => $actorId,
            'score'   => $score['score'],
            'rank'    => $rank,
        ], 200);
    }

    private function deleteScore(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');

        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 400);
        }

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);

        $leaderboardId = isset($params['leaderboardId']) ? (int) $params['leaderboardId'] : 0;
        $userId        = isset($params['userId']) ? (int) $params['userId'] : 0;

        if ($leaderboardId <= 0 || $userId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid parameters'], 404);
        }

        if ($actorId !== $userId) {
            return $this->responseFactory->create(['error' => 'cannot delete another user\'s score'], 403);
        }

        $leaderboard = $this->repository->findLeaderboardById($leaderboardId);

        if ($leaderboard === null) {
            return $this->responseFactory->create(['error' => 'leaderboard not found'], 404);
        }

        $deleted = $this->repository->deleteScore($leaderboardId, $userId);

        if (!$deleted) {
            return $this->responseFactory->create(['error' => 'score not found'], 404);
        }

        return $this->responseFactory->createEmpty(204);
    }
}
