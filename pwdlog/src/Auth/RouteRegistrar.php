<?php

declare(strict_types=1);

namespace Pwd\Auth;

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
    ) {}

    public function register(Router $router): void
    {
        $router->post('/register', $this->handleRegister(...));
        $router->post('/login', $this->login(...));
    }

    private function handleRegister(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);

        if (
            !is_array($body) ||
            !isset($body['email'], $body['password']) ||
            !is_string($body['email']) ||
            !is_string($body['password'])
        ) {
            return $this->problems->create($request, 'invalid-body', 'email and password (string) are required.', 400);
        }

        $email    = trim($body['email']);
        $password = $body['password'];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'email', 'code' => 'invalid', 'message' => 'A valid email address is required.']],
            ]);
        }

        if (strlen($password) < 8) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'password', 'code' => 'too-short', 'message' => 'Password must be at least 8 characters.']],
            ]);
        }

        // Argon2id is the recommended algorithm: memory-hard, resistant to GPU attacks.
        // Never use MD5, SHA-1, or even SHA-256 — they are too fast for password hashing.
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        try {
            $user = $this->repo->create($email, $hash);
        } catch (DuplicateEmailException) {
            return $this->problems->create(
                $request,
                'email-taken',
                'Email Already Registered',
                409,
                'An account with this email address already exists.',
            );
        }

        return $this->json->create([
            'id'         => $user->id,
            'email'      => $user->email,
            'created_at' => $user->createdAt,
            // password_hash is NEVER returned in the response
        ], 201);
    }

    private function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);

        if (
            !is_array($body) ||
            !isset($body['email'], $body['password']) ||
            !is_string($body['email']) ||
            !is_string($body['password'])
        ) {
            return $this->problems->create($request, 'invalid-body', 'email and password (string) are required.', 400);
        }

        $user = $this->repo->findByEmail(trim($body['email']));

        // Always run password_verify even when user is not found.
        // This prevents timing-based user enumeration: without a dummy hash,
        // not-found responses return instantly while wrong-password responses
        // take bcrypt/argon2 time — leaking whether the email exists.
        $dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
        $hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

        if (!password_verify($body['password'], $hashToCheck) || $user === null) {
            // Return the same error whether the email was not found or the password was wrong.
            // Never reveal which one failed — that leaks user existence.
            return $this->problems->create(
                $request,
                'invalid-credentials',
                'Invalid Credentials',
                401,
                'The email or password is incorrect.',
            );
        }

        // In a real app, generate and return a session token or JWT here.
        return $this->json->create([
            'id'    => $user->id,
            'email' => $user->email,
        ]);
    }
}
