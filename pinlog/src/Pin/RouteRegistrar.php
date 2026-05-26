<?php

declare(strict_types=1);

namespace PinLog\Pin;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly PinRepository $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->post('/pins', $this->handlePin(...));
        $this->router->delete('/pins/{articleId}', $this->handleUnpin(...));
        $this->router->get('/pins', $this->handleList(...));
        $this->router->put('/pins/order', $this->handleReorder(...));
    }

    private function handlePin(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 401);
        }

        $body = JsonRequestBodyParser::parse($request);
        $articleId = isset($body['article_id']) && is_int($body['article_id']) ? $body['article_id'] : 0;
        if ($articleId <= 0) {
            return $this->responseFactory->create(['error' => 'article_id is required'], 422);
        }

        if ($this->repository->findUserById($actorId) === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        if ($this->repository->findArticleById($articleId) === null) {
            return $this->responseFactory->create(['error' => 'article not found'], 404);
        }

        if ($this->repository->countPins($actorId) >= $this->repository->maxPins()) {
            $existing = $this->repository->findPin($actorId, $articleId);
            if ($existing === null) {
                return $this->responseFactory->create(['error' => 'pin limit reached', 'max' => $this->repository->maxPins()], 422);
            }
        }

        $created = $this->repository->pin($actorId, $articleId, date('c'));
        $status = $created ? 201 : 200;

        $pin = $this->repository->findPin($actorId, $articleId);

        return $this->responseFactory->create([
            'article_id' => $articleId,
            'position' => $pin !== null ? (int) $pin['position'] : 0,
            'pinned_at' => $pin !== null ? (string) $pin['pinned_at'] : '',
        ], $status);
    }

    private function handleUnpin(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 401);
        }

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $articleId = isset($params['articleId']) && ctype_digit((string) $params['articleId']) ? (int) $params['articleId'] : 0;
        if ($articleId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid article id'], 422);
        }

        if ($this->repository->findUserById($actorId) === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $removed = $this->repository->unpin($actorId, $articleId);
        if (!$removed) {
            return $this->responseFactory->create(['error' => 'pin not found'], 404);
        }

        return $this->responseFactory->createEmpty(204);
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 401);
        }

        if ($this->repository->findUserById($actorId) === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $pins = $this->repository->listPins($actorId);
        $result = [];
        foreach ($pins as $pin) {
            $arr = (array) $pin;
            $result[] = [
                'article_id' => isset($arr['article_id']) ? (int) $arr['article_id'] : 0,
                'title' => isset($arr['title']) && is_string($arr['title']) ? $arr['title'] : '',
                'position' => isset($arr['position']) ? (int) $arr['position'] : 0,
                'pinned_at' => isset($arr['pinned_at']) && is_string($arr['pinned_at']) ? $arr['pinned_at'] : '',
            ];
        }

        return $this->responseFactory->create([
            'pins' => $result,
            'count' => count($result),
        ], 200);
    }

    private function handleReorder(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 401);
        }

        if ($this->repository->findUserById($actorId) === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $body = JsonRequestBodyParser::parse($request);
        $articleIds = isset($body['article_ids']) && is_array($body['article_ids']) ? $body['article_ids'] : null;
        if ($articleIds === null) {
            return $this->responseFactory->create(['error' => 'article_ids is required'], 422);
        }

        $typed = [];
        foreach ($articleIds as $id) {
            if (!is_int($id) || $id <= 0) {
                return $this->responseFactory->create(['error' => 'article_ids must be an array of positive integers'], 422);
            }
            $typed[] = $id;
        }

        $success = $this->repository->reorder($actorId, $typed);
        if (!$success) {
            return $this->responseFactory->create(['error' => 'article_ids must match exactly the current pinned articles'], 422);
        }

        $pins = $this->repository->listPins($actorId);
        $result = [];
        foreach ($pins as $pin) {
            $arr = (array) $pin;
            $result[] = [
                'article_id' => isset($arr['article_id']) ? (int) $arr['article_id'] : 0,
                'title' => isset($arr['title']) && is_string($arr['title']) ? $arr['title'] : '',
                'position' => isset($arr['position']) ? (int) $arr['position'] : 0,
            ];
        }

        return $this->responseFactory->create(['pins' => $result], 200);
    }
}
