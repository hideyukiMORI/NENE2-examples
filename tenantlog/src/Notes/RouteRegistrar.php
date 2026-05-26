<?php

declare(strict_types=1);

namespace Tenant\Notes;

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
        private readonly NoteRepository $notes,
        private readonly TokenIssuerInterface $issuer,
        private readonly TokenVerifierInterface $verifier,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/auth/login', $this->login(...));
        $router->get('/notes', $this->listNotes(...));
        $router->post('/notes', $this->createNote(...));
        $router->get('/notes/{id}', $this->getNote(...));
        $router->delete('/notes/{id}', $this->deleteNote(...));
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
            'sub'       => $user->id,
            'tenant_id' => $user->tenantId,  // tenant identity travels in the JWT
            'email'     => $user->email,
            'iat'       => $now,
            'exp'       => $now + self::TOKEN_TTL_SECONDS,
        ]);

        return $this->json->create(['token' => $token, 'token_type' => 'Bearer']);
    }

    private function listNotes(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenantId($request);

        if ($tenantId === null) {
            return $this->unauthorized($request);
        }

        return $this->json->createList(array_map(
            static fn (Note $n) => [
                'id'         => $n->id,
                'title'      => $n->title,
                'body'       => $n->body,
                'created_at' => $n->createdAt,
                // tenant_id intentionally omitted from responses
            ],
            $this->notes->findAllForTenant($tenantId),
        ));
    }

    private function createNote(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenantIdFromHeader($request);

        if ($tenantId === null) {
            return $this->unauthorized($request);
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

        $note = $this->notes->create($tenantId, trim($body['title']), $body['body']);

        return $this->json->create([
            'id'         => $note->id,
            'title'      => $note->title,
            'body'       => $note->body,
            'created_at' => $note->createdAt,
        ], 201);
    }

    private function getNote(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenantId($request);

        if ($tenantId === null) {
            return $this->unauthorized($request);
        }

        $id   = (int) Router::param($request, 'id');
        $note = $this->notes->findByIdForTenant($id, $tenantId);

        if ($note === null) {
            // Return 404 — not 403. Revealing that a resource exists but is forbidden
            // leaks cross-tenant information. 404 is always safe.
            return $this->problems->create(
                $request,
                'not-found',
                'Note Not Found',
                404,
                "Note {$id} does not exist."
            );
        }

        return $this->json->create([
            'id'         => $note->id,
            'title'      => $note->title,
            'body'       => $note->body,
            'created_at' => $note->createdAt,
        ]);
    }

    private function deleteNote(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenantId($request);

        if ($tenantId === null) {
            return $this->unauthorized($request);
        }

        $id      = (int) Router::param($request, 'id');
        $deleted = $this->notes->delete($id, $tenantId);

        if (!$deleted) {
            return $this->problems->create(
                $request,
                'not-found',
                'Note Not Found',
                404,
                "Note {$id} does not exist."
            );
        }

        return $this->json->createEmpty(204);
    }

    // Reads tenant_id from the middleware-set claims attribute (protected path).
    private function tenantId(ServerRequestInterface $request): ?int
    {
        /** @var array<string, mixed>|null $claims */
        $claims = $request->getAttribute('nene2.auth.claims');

        if (!is_array($claims) || !isset($claims['tenant_id']) || !is_int($claims['tenant_id'])) {
            return null;
        }

        return $claims['tenant_id'];
    }

    // Reads tenant_id from the Authorization header (for paths excluded from BearerTokenMiddleware).
    private function tenantIdFromHeader(ServerRequestInterface $request): ?int
    {
        $claims = $this->tenantId($request);

        if ($claims !== null) {
            return $claims;
        }

        $authorization = $request->getHeaderLine('Authorization');

        if ($authorization === '' || !str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        try {
            $decoded = $this->verifier->verify(substr($authorization, 7));
        } catch (TokenVerificationException) {
            return null;
        }

        if (!isset($decoded['tenant_id']) || !is_int($decoded['tenant_id'])) {
            return null;
        }

        return $decoded['tenant_id'];
    }

    private function unauthorized(ServerRequestInterface $request): ResponseInterface
    {
        return $this->problems->create(
            $request,
            'unauthorized',
            'Unauthorized',
            401,
            'Authentication required.'
        );
    }
}
