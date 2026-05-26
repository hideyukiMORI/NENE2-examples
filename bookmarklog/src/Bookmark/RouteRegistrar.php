<?php

declare(strict_types=1);

namespace Bookmark\Bookmark;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly BookmarkRepository $repo,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/items', $this->createItem(...));
        $router->post('/users/{userId}/bookmarks', $this->addBookmark(...));
        $router->delete('/users/{userId}/bookmarks/{itemId}', $this->removeBookmark(...));
        $router->get('/users/{userId}/bookmarks', $this->listBookmarks(...));
        $router->get('/users/{userId}/bookmarks/count', $this->countBookmarks(...));
        $router->get('/users/{userId}/bookmarks/{itemId}', $this->getBookmark(...));
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

    private function addBookmark(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $body       = JsonRequestBodyParser::parse($request);
        $itemId     = isset($body['item_id']) && is_int($body['item_id']) ? $body['item_id'] : 0;
        $collection = isset($body['collection']) && is_string($body['collection']) ? trim($body['collection']) : 'default';

        if ($itemId <= 0 || !$this->repo->findItemById($itemId)) {
            return $this->responseFactory->create(['error' => 'item not found'], 404);
        }

        if ($collection === '') {
            $collection = 'default';
        }

        $now      = date('Y-m-d H:i:s');
        $bookmark = $this->repo->add($userId, $itemId, $collection, $now);

        return $this->responseFactory->create($bookmark->toArray(), 201);
    }

    private function removeBookmark(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;
        $itemId = isset($params['itemId']) && is_numeric($params['itemId']) ? (int) $params['itemId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $removed = $this->repo->remove($userId, $itemId);

        if (!$removed) {
            return $this->responseFactory->create(['error' => 'bookmark not found'], 404);
        }

        return $this->responseFactory->createEmpty(204);
    }

    private function listBookmarks(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $query      = $request->getQueryParams();
        $collection = isset($query['collection']) && is_string($query['collection']) && $query['collection'] !== ''
            ? $query['collection'] : null;

        $items = $this->repo->listByUser($userId, $collection);

        return $this->responseFactory->create([
            'items' => array_map(fn(Bookmark $b) => $b->toArray(), $items),
            'count' => count($items),
        ]);
    }

    private function countBookmarks(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        return $this->responseFactory->create(['count' => $this->repo->countByUser($userId)]);
    }

    private function getBookmark(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;
        $itemId = isset($params['itemId']) && is_numeric($params['itemId']) ? (int) $params['itemId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $bookmark = $this->repo->find($userId, $itemId);

        if ($bookmark === null) {
            return $this->responseFactory->create(['error' => 'bookmark not found'], 404);
        }

        return $this->responseFactory->create($bookmark->toArray());
    }
}
