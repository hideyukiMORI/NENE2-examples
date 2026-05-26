<?php

declare(strict_types=1);

namespace Refresh\Auth;

use Nene2\Auth\TokenIssuerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    // Access token is intentionally short-lived — refresh tokens extend sessions
    private const int ACCESS_TOKEN_TTL_SECONDS = 300; // 5 minutes

    public function __construct(
        private readonly UserRepository $users,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly TokenIssuerInterface $issuer,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/auth/login', $this->login(...));
        $router->post('/auth/refresh', $this->refresh(...));
        $router->post('/auth/logout', $this->logout(...));
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
            return $this->problems->create($request, 'invalid-body', 'email and password are required.', 400);
        }

        $user = $this->users->findByEmail(trim($body['email']));

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

        return $this->json->create($this->issueTokenPair($user));
    }

    private function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);

        if (
            !is_array($body) ||
            !isset($body['refresh_token']) ||
            !is_string($body['refresh_token'])
        ) {
            return $this->problems->create($request, 'invalid-body', 'refresh_token (string) is required.', 400);
        }

        $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

        if ($stored === null || !$stored->isValid()) {
            // A token used after revocation may indicate a replay attack —
            // revoke all tokens for the user to force re-authentication.
            if ($stored !== null && $stored->revoked) {
                $this->refreshTokens->revokeAllForUser($stored->userId);
            }

            return $this->problems->create(
                $request,
                'invalid-refresh-token',
                'Invalid or Expired Refresh Token',
                401,
                'The refresh token is invalid, expired, or has already been used.',
            );
        }

        $user = $this->users->findById($stored->userId);

        if ($user === null) {
            return $this->problems->create($request, 'invalid-refresh-token', 'Invalid Refresh Token', 401);
        }

        // Rotation: revoke the old token before issuing a new one
        $this->refreshTokens->revoke($stored->id);

        return $this->json->create($this->issueTokenPair($user));
    }

    private function logout(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);

        if (
            !is_array($body) ||
            !isset($body['refresh_token']) ||
            !is_string($body['refresh_token'])
        ) {
            return $this->problems->create($request, 'invalid-body', 'refresh_token (string) is required.', 400);
        }

        $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

        if ($stored !== null && !$stored->revoked) {
            $this->refreshTokens->revoke($stored->id);
        }

        // Always return 204 — never leak whether the token was valid
        return $this->json->createEmpty(204);
    }

    private function me(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $claims */
        $claims = $request->getAttribute('nene2.auth.claims');

        if (!is_array($claims) || !isset($claims['sub']) || !is_int($claims['sub'])) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, 'Authentication required.');
        }

        $user = $this->users->findById($claims['sub']);

        if ($user === null) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, 'Authentication required.');
        }

        return $this->json->create(['id' => $user->id, 'email' => $user->email]);
    }

    /** @return array<string, mixed> */
    private function issueTokenPair(User $user): array
    {
        $now         = time();
        $accessToken = $this->issuer->issue([
            'jti'   => bin2hex(random_bytes(8)),  // unique token ID — enables future revocation tracking
            'sub'   => $user->id,
            'email' => $user->email,
            'iat'   => $now,
            'exp'   => $now + self::ACCESS_TOKEN_TTL_SECONDS,
        ]);

        $refreshToken = $this->refreshTokens->issue($user->id);

        return [
            'access_token'  => $accessToken,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TOKEN_TTL_SECONDS,
            'refresh_token' => $refreshToken,
        ];
    }
}
