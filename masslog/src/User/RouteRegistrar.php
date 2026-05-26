<?php

declare(strict_types=1);

namespace Mass\User;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly UserRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->get('/users', $this->listUsers(...));
    }

    private function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->problems->create($request, 'invalid-body', 'Request body must be a JSON object.', 400);
        }

        $errors = [];

        if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
            $errors[] = ['field' => 'name', 'code' => 'required', 'message' => 'name is required.'];
        }
        if (!isset($body['email']) || !is_string($body['email']) || !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['field' => 'email', 'code' => 'invalid-email', 'message' => 'email must be a valid email address.'];
        }

        if ($errors !== []) {
            return $this->problems->create(
                $request,
                'validation-failed',
                'Validation failed.',
                422,
                null,
                ['errors' => $errors],
            );
        }

        // Only allowed fields are mapped to the DTO — extra fields (role, is_active) are silently ignored
        $input = new CreateUserInput(
            name:  trim((string) $body['name']),
            email: strtolower(trim((string) $body['email'])),
        );

        $user = $this->repo->create($input);

        return $this->json->create([
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'role'       => $user->role,
            'is_active'  => $user->isActive,
            'created_at' => $user->createdAt,
        ], 201);
    }

    private function listUsers(ServerRequestInterface $request): ResponseInterface
    {
        $users = $this->repo->findAll();

        return $this->json->createList(array_map(
            fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $u->role,
                'is_active'  => $u->isActive,
                'created_at' => $u->createdAt,
            ],
            $users,
        ));
    }
}
