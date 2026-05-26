<?php

declare(strict_types=1);

namespace Notification\Notification;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly NotificationRepository $repo,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/users/{userId}/notifications', $this->createNotification(...));
        $router->get('/users/{userId}/notifications', $this->listNotifications(...));
        $router->get('/users/{userId}/notifications/unread-count', $this->unreadCount(...));
        $router->patch('/users/{userId}/notifications/{id}/read', $this->markAsRead(...));
        $router->post('/users/{userId}/notifications/read-all', $this->markAllAsRead(...));
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

        return $this->responseFactory->create(['id' => $userId, 'name' => $name, 'created_at' => $now], 201);
    }

    private function createNotification(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $body  = JsonRequestBodyParser::parse($request);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        $text  = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : '';

        if ($title === '' || $text === '') {
            return $this->responseFactory->create(['error' => 'title and body are required'], 422);
        }

        $now          = date('Y-m-d H:i:s');
        $notification = $this->repo->create($userId, $title, $text, $now);

        return $this->responseFactory->create($notification->toArray(), 201);
    }

    private function listNotifications(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $query      = $request->getQueryParams();
        $unreadOnly = isset($query['unread']) && $query['unread'] === 'true' ? true : null;
        $items      = $this->repo->findByUserId($userId, $unreadOnly);
        $unreadCount = $this->repo->countUnread($userId);

        return $this->responseFactory->create([
            'items'        => array_map(fn(Notification $n) => $n->toArray(), $items),
            'unread_count' => $unreadCount,
        ]);
    }

    private function unreadCount(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $count = $this->repo->countUnread($userId);

        return $this->responseFactory->create(['unread_count' => $count]);
    }

    private function markAsRead(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;
        $id     = isset($params['id']) && is_numeric($params['id']) ? (int) $params['id'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $notification = $this->repo->findById($id);

        if ($notification === null || $notification->userId !== $userId) {
            return $this->responseFactory->create(['error' => 'notification not found'], 404);
        }

        $now    = date('Y-m-d H:i:s');
        $result = $this->repo->markAsRead($id, $now);

        return $this->responseFactory->create($result !== null ? $result->toArray() : $notification->toArray());
    }

    private function markAllAsRead(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $now         = date('Y-m-d H:i:s');
        $unreadCount = $this->repo->markAllAsRead($userId, $now);

        return $this->responseFactory->create(['unread_count' => $unreadCount]);
    }
}
