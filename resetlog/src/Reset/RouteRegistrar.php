<?php

declare(strict_types=1);

namespace Reset\Reset;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private ResetRepository               $repo,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/password-reset', $this->requestReset(...));
        $router->get('/password-reset/{token}', $this->getResetStatus(...));
        $router->post('/password-reset/{token}', $this->completeReset(...));
    }

    private function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $body     = JsonRequestBodyParser::parse($request);
        $email    = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $name     = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';

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

        if (strlen($password) < 8) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'password', 'code' => 'min-length', 'message' => 'password must be at least 8 characters.']],
            ]);
        }

        $now          = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
        $user         = $this->repo->createUser($email, $name, $passwordHash, $now);

        if ($user === null) {
            return $this->problems->create($request, 'conflict', 'Email already registered.', 409, '');
        }

        return $this->json->create($user->toPublicArray(), 201);
    }

    private function requestReset(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';

        if ($email === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'email', 'code' => 'required', 'message' => 'email is required.']],
            ]);
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $user = $this->repo->findUserByEmail($email);

        // Always return 202 to prevent user enumeration
        if ($user === null) {
            return $this->json->create(['status' => 'pending'], 202);
        }

        $expiresAt = (new \DateTimeImmutable())->modify('+1 hour')->format('Y-m-d H:i:s');
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $this->repo->createReset($user->id, $tokenHash, $expiresAt, $now);

        return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
    }

    private function getResetStatus(ServerRequestInterface $request): ResponseInterface
    {
        $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $rawToken  = (string) ($params['token'] ?? '');
        $tokenHash = hash('sha256', $rawToken);
        $reset     = $this->repo->findByTokenHashOrNull($tokenHash);

        if ($reset === null) {
            return $this->problems->create($request, 'not-found', 'Reset token not found.', 404, '');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($reset->isExpired($now)) {
            return $this->problems->create($request, 'gone', 'Reset token has expired.', 410, '');
        }

        if ($reset->isUsed()) {
            return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
        }

        return $this->json->create($reset->toArray());
    }

    private function completeReset(ServerRequestInterface $request): ResponseInterface
    {
        $params      = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $rawToken    = (string) ($params['token'] ?? '');
        $body        = JsonRequestBodyParser::parse($request);
        $newPassword = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';

        if (strlen($newPassword) < 8) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'password', 'code' => 'min-length', 'message' => 'password must be at least 8 characters.']],
            ]);
        }

        $tokenHash = hash('sha256', $rawToken);
        $reset     = $this->repo->findByTokenHashOrNull($tokenHash);

        if ($reset === null) {
            return $this->problems->create($request, 'not-found', 'Reset token not found.', 404, '');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($reset->isExpired($now)) {
            return $this->problems->create($request, 'gone', 'Reset token has expired.', 410, '');
        }

        if ($reset->isUsed()) {
            return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
        }

        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
        $this->repo->updatePasswordHash($reset->userId, $newHash);
        $this->repo->markUsed($tokenHash, $now);

        return $this->json->create(['status' => 'completed'], 200);
    }
}
