<?php

declare(strict_types=1);

namespace Jwt\Auth;

use Nene2\Auth\TokenIssuerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    // Token TTL: 1 hour. Long-lived tokens increase the blast radius of theft.
    private const int TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly UserRepository $repo,
        private readonly TokenIssuerInterface $issuer,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/auth/login', $this->login(...));
        $router->get('/auth/me', $this->me(...));
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

        // Always run password_verify — prevents timing-based user enumeration.
        $dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
        $hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

        if (!password_verify($body['password'], $hashToCheck) || $user === null) {
            return $this->problems->create(
                $request,
                'invalid-credentials',
                'Invalid Credentials',
                401,
                'The email or password is incorrect.',
            );
        }

        $now       = time();
        $expiresAt = $now + self::TOKEN_TTL_SECONDS;

        // sub (subject) = user ID, iat = issued-at, exp = expiry — all Unix timestamps.
        $token = $this->issuer->issue([
            'sub'   => $user->id,
            'email' => $user->email,
            'iat'   => $now,
            'exp'   => $expiresAt,
        ]);

        return $this->json->create([
            'token'      => $token,
            'expires_at' => $expiresAt,
            'token_type' => 'Bearer',
        ]);
    }

    private function me(ServerRequestInterface $request): ResponseInterface
    {
        // BearerTokenMiddleware stores decoded claims here after successful verification.
        /** @var array<string, mixed>|null $claims */
        $claims = $request->getAttribute('nene2.auth.claims');

        if (!is_array($claims)) {
            // Should not happen — middleware already rejected missing/invalid tokens.
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, 'No authenticated user.');
        }

        return $this->json->create([
            'id'    => $claims['sub'],
            'email' => $claims['email'],
        ]);
    }
}
