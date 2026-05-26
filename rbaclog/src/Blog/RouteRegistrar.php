<?php

declare(strict_types=1);

namespace Rbac\Blog;

use Nene2\Auth\TokenIssuerInterface;
use Nene2\Auth\TokenVerificationException;
use Nene2\Auth\TokenVerifierInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    private const int TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PostRepository $posts,
        private readonly TokenIssuerInterface $issuer,
        private readonly TokenVerifierInterface $verifier,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/auth/login', $this->login(...));
        $router->get('/posts', $this->listPosts(...));
        $router->post('/posts', $this->createPost(...));
        $router->delete('/posts/{id}', $this->deletePost(...));
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

        $user = $this->users->findByEmail(trim($body['email']));

        $dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
        $hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

        if (!password_verify($body['password'], $hashToCheck) || $user === null) {
            return $this->problems->create(
                $request,
                'invalid-credentials',
                'Invalid Credentials',
                401,
                'The email or password is incorrect.'
            );
        }

        $now   = time();
        $token = $this->issuer->issue([
            'sub'   => $user->id,
            'email' => $user->email,
            'role'  => $user->role->value,  // include role in claims — avoid extra DB query per request
            'iat'   => $now,
            'exp'   => $now + self::TOKEN_TTL_SECONDS,
        ]);

        return $this->json->create(['token' => $token, 'token_type' => 'Bearer']);
    }

    private function listPosts(ServerRequestInterface $request): ResponseInterface
    {
        $postList = $this->posts->findAll();

        return $this->json->createList(array_map(
            static fn (Post $p) => [
                'id'         => $p->id,
                'title'      => $p->title,
                'body'       => $p->body,
                'author_id'  => $p->authorId,
                'created_at' => $p->createdAt,
            ],
            $postList,
        ));
    }

    private function createPost(ServerRequestInterface $request): ResponseInterface
    {
        // Any authenticated user (user or admin) may create a post.
        $claims = $this->requireAuth($request);
        if ($claims instanceof ResponseInterface) {
            return $claims;
        }

        $body = json_decode((string) $request->getBody(), true);

        if (
            !is_array($body) ||
            !isset($body['title'], $body['body']) ||
            !is_string($body['title']) ||
            !is_string($body['body']) ||
            trim($body['title']) === ''
        ) {
            return $this->problems->create($request, 'invalid-body', 'title and body (string) are required.', 400);
        }

        $post = $this->posts->create(trim($body['title']), $body['body'], (int) $claims['sub']);

        return $this->json->create([
            'id'         => $post->id,
            'title'      => $post->title,
            'body'       => $post->body,
            'author_id'  => $post->authorId,
            'created_at' => $post->createdAt,
        ], 201);
    }

    private function deletePost(ServerRequestInterface $request): ResponseInterface
    {
        // Only admins may delete any post.
        $claims = $this->requireRole($request, Role::Admin);
        if ($claims instanceof ResponseInterface) {
            return $claims;
        }

        $id   = (int) Router::param($request, 'id');
        $post = $this->posts->findById($id);

        if ($post === null) {
            return $this->problems->create(
                $request,
                'not-found',
                'Post Not Found',
                404,
                "Post {$id} does not exist."
            );
        }

        $this->posts->delete($id);

        return $this->json->createEmpty(204);
    }

    /**
     * Returns the claims array on success, or a 401 ResponseInterface on failure.
     *
     * Checks the middleware-set attribute first (for paths protected by BearerTokenMiddleware).
     * Falls back to manual token extraction for paths where the middleware is excluded
     * (BearerTokenMiddleware cannot differentiate between HTTP methods on the same path).
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
    {
        // Fast path: middleware already verified and stored the claims.
        /** @var array<string, mixed>|null $claims */
        $claims = $request->getAttribute('nene2.auth.claims');

        if (is_array($claims)) {
            return $claims;
        }

        // Slow path: path was excluded from BearerTokenMiddleware (e.g. POST /posts shares
        // the same path as public GET /posts). Verify the token manually.
        $authorization = $request->getHeaderLine('Authorization');

        if ($authorization === '' || !str_starts_with($authorization, 'Bearer ')) {
            return $this->problems->create(
                $request,
                'unauthorized',
                'Unauthorized',
                401,
                'Authentication required.'
            );
        }

        try {
            return $this->verifier->verify(substr($authorization, 7));
        } catch (TokenVerificationException) {
            return $this->problems->create(
                $request,
                'unauthorized',
                'Unauthorized',
                401,
                'Token is invalid or expired.'
            );
        }
    }

    /**
     * Returns the claims array if the user has the required role, or a 401/403 ResponseInterface.
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function requireRole(ServerRequestInterface $request, Role $required): array|ResponseInterface
    {
        $claims = $this->requireAuth($request);

        if ($claims instanceof ResponseInterface) {
            return $claims;
        }

        $actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

        if ($actualRole !== $required) {
            // 403 Forbidden — authenticated but insufficient permissions.
            // 401 would be wrong: the user IS authenticated, just not authorized.
            return $this->problems->create(
                $request,
                'forbidden',
                'Forbidden',
                403,
                "This action requires the '{$required->value}' role."
            );
        }

        return $claims;
    }
}
