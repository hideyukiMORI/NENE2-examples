<?php

declare(strict_types=1);

namespace Group\Group;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly GroupRepository $repo,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/groups', $this->createGroup(...));
        $router->get('/groups/{groupId}/members', $this->listMembers(...));
        $router->post('/groups/{groupId}/members', $this->addMember(...));
        $router->delete('/groups/{groupId}/members/{userId}', $this->removeMember(...));
        $router->put('/groups/{groupId}/members/{userId}/role', $this->changeRole(...));
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

    private function createGroup(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->resolveActorId($request);

        if ($actorId <= 0 || !$this->repo->findUserById($actorId)) {
            return $this->responseFactory->create(['error' => 'actor not found'], 404);
        }

        $body = JsonRequestBodyParser::parse($request);
        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';

        if ($name === '') {
            return $this->responseFactory->create(['error' => 'name is required'], 422);
        }

        $now     = date('Y-m-d H:i:s');
        $groupId = $this->repo->createGroup($name, $actorId, $now);
        $group   = $this->repo->findGroupById($groupId);

        return $this->responseFactory->create($group ?? ['id' => $groupId], 201);
    }

    private function listMembers(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $groupId = isset($params['groupId']) && is_numeric($params['groupId']) ? (int) $params['groupId'] : 0;
        $actorId = $this->resolveActorId($request);

        if ($groupId <= 0 || $this->repo->findGroupById($groupId) === null) {
            return $this->responseFactory->create(['error' => 'group not found'], 404);
        }

        if ($actorId <= 0 || $this->repo->findMembership($groupId, $actorId) === null) {
            return $this->responseFactory->create(['error' => 'not a member'], 403);
        }

        $members = $this->repo->listMembers($groupId);

        return $this->responseFactory->create(['items' => $members, 'count' => count($members)]);
    }

    private function addMember(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $groupId = isset($params['groupId']) && is_numeric($params['groupId']) ? (int) $params['groupId'] : 0;
        $actorId = $this->resolveActorId($request);

        if ($groupId <= 0 || $this->repo->findGroupById($groupId) === null) {
            return $this->responseFactory->create(['error' => 'group not found'], 404);
        }

        $actorMembership = $actorId > 0 ? $this->repo->findMembership($groupId, $actorId) : null;

        if ($actorMembership === null) {
            return $this->responseFactory->create(['error' => 'not a member'], 403);
        }

        $actorRole = MemberRole::tryFrom($actorMembership['role']) ?? MemberRole::Member;

        if (!$actorRole->canManageMembers()) {
            return $this->responseFactory->create(['error' => 'only owner or admin can add members'], 403);
        }

        $body   = JsonRequestBodyParser::parse($request);
        $userId = isset($body['user_id']) && is_int($body['user_id']) ? $body['user_id'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $roleValue = isset($body['role']) && is_string($body['role']) ? $body['role'] : 'member';
        $role      = MemberRole::tryFrom($roleValue);

        if ($role === null || $role === MemberRole::Owner) {
            return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
        }

        if ($this->repo->findMembership($groupId, $userId) !== null) {
            return $this->responseFactory->create(['error' => 'user is already a member'], 409);
        }

        $now = date('Y-m-d H:i:s');
        $this->repo->addMember($groupId, $userId, $role, $now);

        return $this->responseFactory->create([
            'group_id'  => $groupId,
            'user_id'   => $userId,
            'role'      => $role->value,
            'joined_at' => $now,
        ], 201);
    }

    private function removeMember(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $groupId = isset($params['groupId']) && is_numeric($params['groupId']) ? (int) $params['groupId'] : 0;
        $userId  = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;
        $actorId = $this->resolveActorId($request);

        if ($groupId <= 0 || $this->repo->findGroupById($groupId) === null) {
            return $this->responseFactory->create(['error' => 'group not found'], 404);
        }

        $actorMembership = $actorId > 0 ? $this->repo->findMembership($groupId, $actorId) : null;

        if ($actorMembership === null) {
            return $this->responseFactory->create(['error' => 'not a member'], 403);
        }

        $actorRole    = MemberRole::tryFrom($actorMembership['role']) ?? MemberRole::Member;
        $isSelfLeave  = $actorId === $userId;

        if (!$isSelfLeave && !$actorRole->canManageMembers()) {
            return $this->responseFactory->create(['error' => 'only owner or admin can remove members'], 403);
        }

        $targetMembership = $this->repo->findMembership($groupId, $userId);

        if ($targetMembership === null) {
            return $this->responseFactory->create(['error' => 'user is not a member'], 404);
        }

        $targetRole = MemberRole::tryFrom($targetMembership['role']) ?? MemberRole::Member;

        // Owner cannot be removed
        if ($targetRole === MemberRole::Owner) {
            return $this->responseFactory->create(['error' => 'cannot remove the group owner'], 422);
        }

        $this->repo->removeMember($groupId, $userId);

        return $this->responseFactory->createEmpty(204);
    }

    private function changeRole(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $groupId = isset($params['groupId']) && is_numeric($params['groupId']) ? (int) $params['groupId'] : 0;
        $userId  = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;
        $actorId = $this->resolveActorId($request);

        if ($groupId <= 0 || $this->repo->findGroupById($groupId) === null) {
            return $this->responseFactory->create(['error' => 'group not found'], 404);
        }

        $actorMembership = $actorId > 0 ? $this->repo->findMembership($groupId, $actorId) : null;

        if ($actorMembership === null) {
            return $this->responseFactory->create(['error' => 'not a member'], 403);
        }

        $actorRole = MemberRole::tryFrom($actorMembership['role']) ?? MemberRole::Member;

        if (!$actorRole->canChangeRoles()) {
            return $this->responseFactory->create(['error' => 'only owner can change roles'], 403);
        }

        $targetMembership = $this->repo->findMembership($groupId, $userId);

        if ($targetMembership === null) {
            return $this->responseFactory->create(['error' => 'user is not a member'], 404);
        }

        $body      = JsonRequestBodyParser::parse($request);
        $roleValue = isset($body['role']) && is_string($body['role']) ? $body['role'] : '';
        $role      = MemberRole::tryFrom($roleValue);

        if ($role === null || $role === MemberRole::Owner) {
            return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
        }

        $this->repo->changeRole($groupId, $userId, $role);

        return $this->responseFactory->create([
            'group_id' => $groupId,
            'user_id'  => $userId,
            'role'     => $role->value,
        ]);
    }

    private function resolveActorId(ServerRequestInterface $request): int
    {
        $header = $request->getHeaderLine('X-User-Id');

        return is_numeric($header) ? (int) $header : 0;
    }
}
