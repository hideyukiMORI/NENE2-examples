<?php

declare(strict_types=1);

namespace Invitation\Invitation;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private InvitationRepository          $repo,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/users/{id}/invitations', $this->sendInvitation(...));
        $router->get('/invitations/{token}', $this->getInvitation(...));
        $router->post('/invitations/{token}/accept', $this->acceptInvitation(...));
        $router->delete('/invitations/{token}', $this->cancelInvitation(...));
    }

    private function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $name  = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';

        if ($email === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'email', 'code' => 'required', 'message' => 'email is required.']],
            ]);
        }

        if ($name === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'name', 'code' => 'required', 'message' => 'name is required.']],
            ]);
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $user = $this->repo->createUser($email, $name, $now);

        if ($user === null) {
            return $this->problems->create($request, 'conflict', 'Email already registered.', 409, '');
        }

        return $this->json->create($user->toArray(), 201);
    }

    private function sendInvitation(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $body   = JsonRequestBodyParser::parse($request);
        $email  = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';

        if ($email === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'email', 'code' => 'required', 'message' => 'email is required.']],
            ]);
        }

        $inviter = $this->repo->findUserById($id);

        if ($inviter === null) {
            return $this->problems->create($request, 'not-found', 'Inviter not found.', 404, '');
        }

        // Prevent inviting already-registered users
        if ($this->repo->findUserByEmail($email) !== null) {
            return $this->problems->create($request, 'conflict', 'Email already registered.', 409, '');
        }

        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $expiresAt = (new \DateTimeImmutable())->modify('+24 hours')->format('Y-m-d H:i:s');
        $token     = bin2hex(random_bytes(32));
        $invite    = $this->repo->createInvitation($id, $email, $token, $expiresAt, $now);

        return $this->json->create($invite->toArray(), 201);
    }

    private function getInvitation(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $token  = (string) ($params['token'] ?? '');
        $invite = $this->repo->findByTokenOrNull($token);

        if ($invite === null) {
            return $this->problems->create($request, 'not-found', 'Invitation not found.', 404, '');
        }

        return $this->json->create($invite->toArray());
    }

    private function acceptInvitation(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $token  = (string) ($params['token'] ?? '');
        $body   = JsonRequestBodyParser::parse($request);
        $name   = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';

        if ($name === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'name', 'code' => 'required', 'message' => 'name is required.']],
            ]);
        }

        $invite = $this->repo->findByTokenOrNull($token);

        if ($invite === null) {
            return $this->problems->create($request, 'not-found', 'Invitation not found.', 404, '');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($invite->isExpired($now)) {
            return $this->problems->create($request, 'gone', 'Invitation has expired.', 410, '');
        }

        if (!$invite->isPending()) {
            return $this->problems->create($request, 'conflict', 'Invitation is no longer valid.', 409, '');
        }

        $user = $this->repo->createUser($invite->email, $name, $now);

        if ($user === null) {
            return $this->problems->create($request, 'conflict', 'Email already registered.', 409, '');
        }

        $this->repo->accept($token, $now);

        return $this->json->create($user->toArray(), 201);
    }

    private function cancelInvitation(ServerRequestInterface $request): ResponseInterface
    {
        $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $token     = (string) ($params['token'] ?? '');
        $body      = JsonRequestBodyParser::parse($request);
        $inviterId = isset($body['inviter_id']) && is_int($body['inviter_id']) ? $body['inviter_id'] : 0;

        $invite = $this->repo->findByTokenOrNull($token);

        if ($invite === null) {
            return $this->problems->create($request, 'not-found', 'Invitation not found.', 404, '');
        }

        if ($invite->inviterId !== $inviterId) {
            return $this->problems->create($request, 'forbidden', 'Only the inviter may cancel this invitation.', 403, '');
        }

        if (!$invite->isPending()) {
            return $this->problems->create($request, 'conflict', 'Invitation is already ' . $invite->status . '.', 409, '');
        }

        $this->repo->cancel($token);

        return $this->json->create([], 204);
    }
}
