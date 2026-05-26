<?php

declare(strict_types=1);

namespace Emoji\Emoji;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly EmojiRepository   $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/posts', $this->createPost(...));
        $router->post('/posts/{postId}/reactions', $this->addReaction(...));
        $router->delete('/posts/{postId}/reactions/{emoji}', $this->removeReaction(...));
        $router->get('/posts/{postId}/reactions', $this->getReactions(...));
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

    private function createPost(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');

        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 400);
        }

        if (!$this->repository->findUserById($actorId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $body = JsonRequestBodyParser::parse($request);

        if (!isset($body['content']) || !is_string($body['content']) || trim($body['content']) === '') {
            return $this->responseFactory->create(['error' => 'content is required'], 422);
        }

        $id = $this->repository->createPost($actorId, trim($body['content']), date('c'));

        return $this->responseFactory->create(['id' => $id], 201);
    }

    private function addReaction(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');

        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 400);
        }

        if (!$this->repository->findUserById($actorId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $postId = isset($params['postId']) ? (int) $params['postId'] : 0;

        if ($postId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid postId'], 404);
        }

        if (!$this->repository->findPostById($postId)) {
            return $this->responseFactory->create(['error' => 'post not found'], 404);
        }

        $body = JsonRequestBodyParser::parse($request);

        if (!isset($body['emoji']) || !is_string($body['emoji']) || trim($body['emoji']) === '') {
            return $this->responseFactory->create(['error' => 'emoji is required'], 422);
        }

        $emoji = trim($body['emoji']);

        if (mb_strlen($emoji) > 8) {
            return $this->responseFactory->create(['error' => 'emoji too long'], 422);
        }

        $added = $this->repository->addReaction($postId, $actorId, $emoji, date('c'));

        if (!$added) {
            return $this->responseFactory->create(['error' => 'already reacted with this emoji'], 409);
        }

        return $this->responseFactory->create(['ok' => true], 201);
    }

    private function removeReaction(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');

        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 400);
        }

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $postId = isset($params['postId']) ? (int) $params['postId'] : 0;
        $emoji  = isset($params['emoji']) && is_string($params['emoji']) ? urldecode($params['emoji']) : '';

        if ($postId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid postId'], 404);
        }

        if ($emoji === '') {
            return $this->responseFactory->create(['error' => 'invalid emoji'], 404);
        }

        if (!$this->repository->findPostById($postId)) {
            return $this->responseFactory->create(['error' => 'post not found'], 404);
        }

        $removed = $this->repository->removeReaction($postId, $actorId, $emoji);

        if (!$removed) {
            return $this->responseFactory->create(['error' => 'reaction not found'], 404);
        }

        return $this->responseFactory->createEmpty(204);
    }

    private function getReactions(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $postId = isset($params['postId']) ? (int) $params['postId'] : 0;

        if ($postId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid postId'], 404);
        }

        if (!$this->repository->findPostById($postId)) {
            return $this->responseFactory->create(['error' => 'post not found'], 404);
        }

        $counts = $this->repository->getReactionCounts($postId);

        $actorId      = (int) $request->getHeaderLine('X-User-Id');
        $userReactions = $actorId > 0 ? $this->repository->getUserReactions($postId, $actorId) : [];

        return $this->responseFactory->create([
            'counts'         => $counts,
            'total'          => array_sum($counts),
            'user_reactions' => $userReactions,
        ], 200);
    }
}
