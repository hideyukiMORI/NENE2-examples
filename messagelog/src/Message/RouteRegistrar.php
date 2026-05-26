<?php

declare(strict_types=1);

namespace Message\Message;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly MessageRepository $repo,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/conversations', $this->startConversation(...));
        $router->post('/conversations/{conversationId}/messages', $this->sendMessage(...));
        $router->get('/conversations/{conversationId}/messages', $this->listMessages(...));
        $router->get('/users/{userId}/conversations', $this->listUserConversations(...));
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

    private function startConversation(ServerRequestInterface $request): ResponseInterface
    {
        $body        = JsonRequestBodyParser::parse($request);
        $initiatorId = isset($body['initiator_id']) && is_int($body['initiator_id']) ? $body['initiator_id'] : 0;
        $recipientId = isset($body['recipient_id']) && is_int($body['recipient_id']) ? $body['recipient_id'] : 0;

        if ($initiatorId <= 0 || !$this->repo->findUserById($initiatorId)) {
            return $this->responseFactory->create(['error' => 'initiator not found'], 404);
        }

        if ($recipientId <= 0 || !$this->repo->findUserById($recipientId)) {
            return $this->responseFactory->create(['error' => 'recipient not found'], 404);
        }

        if ($initiatorId === $recipientId) {
            return $this->responseFactory->create(['error' => 'cannot message yourself'], 422);
        }

        $now            = date('Y-m-d H:i:s');
        $existing       = $this->repo->findConversation($initiatorId, $recipientId);
        $conversationId = $this->repo->findOrCreateConversation($initiatorId, $recipientId, $now);
        $status         = $existing === null ? 201 : 200;
        $conv           = $this->repo->findConversationById($conversationId);

        return $this->responseFactory->create($conv ?? ['id' => $conversationId], $status);
    }

    private function sendMessage(ServerRequestInterface $request): ResponseInterface
    {
        $params         = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $conversationId = isset($params['conversationId']) && is_numeric($params['conversationId'])
            ? (int) $params['conversationId'] : 0;

        if ($conversationId <= 0 || $this->repo->findConversationById($conversationId) === null) {
            return $this->responseFactory->create(['error' => 'conversation not found'], 404);
        }

        $body     = JsonRequestBodyParser::parse($request);
        $senderId = isset($body['sender_id']) && is_int($body['sender_id']) ? $body['sender_id'] : 0;
        $content  = isset($body['content']) && is_string($body['content']) ? trim($body['content']) : '';

        if ($senderId <= 0 || !$this->repo->findUserById($senderId)) {
            return $this->responseFactory->create(['error' => 'sender not found'], 404);
        }

        if (!$this->repo->isParticipant($conversationId, $senderId)) {
            return $this->responseFactory->create(['error' => 'not a participant'], 403);
        }

        if ($content === '') {
            return $this->responseFactory->create(['error' => 'content is required'], 422);
        }

        $now       = date('Y-m-d H:i:s');
        $messageId = $this->repo->sendMessage($conversationId, $senderId, $content, $now);

        return $this->responseFactory->create([
            'id'              => $messageId,
            'conversation_id' => $conversationId,
            'sender_id'       => $senderId,
            'content'         => $content,
            'created_at'      => $now,
        ], 201);
    }

    private function listMessages(ServerRequestInterface $request): ResponseInterface
    {
        $params         = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $conversationId = isset($params['conversationId']) && is_numeric($params['conversationId'])
            ? (int) $params['conversationId'] : 0;

        if ($conversationId <= 0 || $this->repo->findConversationById($conversationId) === null) {
            return $this->responseFactory->create(['error' => 'conversation not found'], 404);
        }

        $actorId = $this->resolveActorId($request);

        if ($actorId <= 0 || !$this->repo->findUserById($actorId)) {
            return $this->responseFactory->create(['error' => 'actor not found'], 404);
        }

        if (!$this->repo->isParticipant($conversationId, $actorId)) {
            return $this->responseFactory->create(['error' => 'not a participant'], 403);
        }

        $messages = $this->repo->listMessages($conversationId);

        return $this->responseFactory->create(['items' => $messages, 'count' => count($messages)]);
    }

    private function listUserConversations(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $actorId = $this->resolveActorId($request);

        if ($actorId !== $userId) {
            return $this->responseFactory->create(['error' => 'forbidden'], 403);
        }

        $conversations = $this->repo->listUserConversations($userId);

        return $this->responseFactory->create(['items' => $conversations, 'count' => count($conversations)]);
    }

    private function resolveActorId(ServerRequestInterface $request): int
    {
        $header = $request->getHeaderLine('X-User-Id');

        return is_numeric($header) ? (int) $header : 0;
    }
}
