<?php

declare(strict_types=1);

namespace Lockout\Lockout;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private LockoutRepository $repo,
        private JsonResponseFactory $json,
        private ProblemDetailsResponseFactory $problems,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/auth/login', $this->login(...));
        $router->get('/auth/status/{email}', $this->status(...));
    }

    private function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $pass  = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';

        if ($email === '' || $pass === '') {
            return $this->problems->create($request, 'validation-failed', 'email and password are required.', 422, '');
        }

        if (strlen($email) > 255) {
            return $this->problems->create($request, 'validation-failed', 'email must not exceed 255 characters.', 422, '');
        }

        $now  = date('Y-m-d H:i:s');
        $user = $this->repo->createUser($email, $pass, $now);

        return $this->json->create($user->toArray(), 201);
    }

    private function login(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $pass  = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';

        if ($email === '' || $pass === '') {
            return $this->problems->create($request, 'validation-failed', 'email and password are required.', 422, '');
        }

        if (strlen($email) > 255) {
            return $this->problems->create($request, 'validation-failed', 'email must not exceed 255 characters.', 422, '');
        }

        $now   = date('Y-m-d H:i:s');
        $state = $this->repo->findOrCreateAccountState($email, $now);

        if ($state->isLocked($now)) {
            return $this->problems->create(
                $request,
                'account-locked',
                'Account is temporarily locked. Try again later.',
                423,
                '',
            );
        }

        $user = $this->repo->findUserByEmail($email);

        if ($user === null || !$user->verifyPassword($pass)) {
            if ($user !== null) {
                $this->repo->recordFailure($email, $now);
            }

            return $this->problems->create($request, 'invalid-credentials', 'Invalid email or password.', 401, '');
        }

        $this->repo->resetState($email, $now);

        return $this->json->create(['id' => $user->id, 'email' => $user->email], 200);
    }

    private function status(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $email  = isset($params['email']) && is_string($params['email']) ? $params['email'] : '';

        if ($email === '') {
            return $this->problems->create($request, 'not-found', 'Email not found.', 404, '');
        }

        $now   = date('Y-m-d H:i:s');
        $state = $this->repo->findOrCreateAccountState($email, $now);

        return $this->json->create(array_merge($state->toArray(), ['is_locked' => $state->isLocked($now)]), 200);
    }
}
