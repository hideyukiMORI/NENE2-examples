<?php

declare(strict_types=1);

namespace Audit\Auth;

use Nene2\Auth\TokenIssuerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private UserRepository                $users,
        private TokenIssuerInterface          $issuer,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/auth/login', $this->login(...));
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

        // Pre-computed valid Argon2id hash — must be real so password_verify() runs the full KDF,
        // preventing timing side-channels that reveal whether the email exists.
        $dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$VkZVLkx3L3FPaVA5NndVSA$vwBHHeAqq1DpGTf7G55ZPAUad+CGLvEJle2m5NA8ulA';
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

        $now   = time();
        $token = $this->issuer->issue([
            'sub'   => $user->id,
            'email' => $user->email,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]);

        return $this->json->create(['access_token' => $token, 'token_type' => 'Bearer']);
    }
}
