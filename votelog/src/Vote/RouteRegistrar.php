<?php

declare(strict_types=1);

namespace Vote\Vote;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly VoteRepository $repo,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/items', $this->createItem(...));
        $router->post('/items/{itemId}/vote', $this->castVote(...));
        $router->get('/items/{itemId}/score', $this->getScore(...));
        $router->get('/items/{itemId}/vote/{userId}', $this->getUserVote(...));
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

    private function createItem(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';

        if ($title === '') {
            return $this->responseFactory->create(['error' => 'title is required'], 422);
        }

        $now    = date('Y-m-d H:i:s');
        $itemId = $this->repo->createItem($title, $now);

        return $this->responseFactory->create(['id' => $itemId, 'title' => $title], 201);
    }

    private function castVote(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $itemId = isset($params['itemId']) && is_numeric($params['itemId']) ? (int) $params['itemId'] : 0;

        if ($itemId <= 0 || !$this->repo->findItemById($itemId)) {
            return $this->responseFactory->create(['error' => 'item not found'], 404);
        }

        $body   = JsonRequestBodyParser::parse($request);
        $userId = isset($body['user_id']) && is_int($body['user_id']) ? $body['user_id'] : 0;
        $dirStr = isset($body['direction']) && is_string($body['direction']) ? $body['direction'] : '';

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $direction = VoteDirection::tryFrom($dirStr);

        if ($direction === null) {
            return $this->responseFactory->create(['error' => 'direction must be "up" or "down"'], 422);
        }

        $now    = date('Y-m-d H:i:s');
        $result = $this->repo->castVote($userId, $itemId, $direction, $now);
        $score  = $this->repo->getScore($itemId);

        return $this->responseFactory->create([
            'user_id'   => $userId,
            'item_id'   => $itemId,
            'vote'      => $result !== null ? $result->value : null,
            'score'     => $score->toArray(),
        ]);
    }

    private function getScore(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $itemId = isset($params['itemId']) && is_numeric($params['itemId']) ? (int) $params['itemId'] : 0;

        if ($itemId <= 0 || !$this->repo->findItemById($itemId)) {
            return $this->responseFactory->create(['error' => 'item not found'], 404);
        }

        return $this->responseFactory->create($this->repo->getScore($itemId)->toArray());
    }

    private function getUserVote(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $itemId = isset($params['itemId']) && is_numeric($params['itemId']) ? (int) $params['itemId'] : 0;
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($itemId <= 0 || !$this->repo->findItemById($itemId)) {
            return $this->responseFactory->create(['error' => 'item not found'], 404);
        }

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $current = $this->repo->getCurrentVote($userId, $itemId);

        return $this->responseFactory->create([
            'user_id' => $userId,
            'item_id' => $itemId,
            'vote'    => $current !== null ? $current->value : null,
        ]);
    }
}
