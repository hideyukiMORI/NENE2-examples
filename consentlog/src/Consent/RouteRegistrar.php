<?php

declare(strict_types=1);

namespace ConsentLog\Consent;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly ConsentRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/consents', $this->grant(...));
        $router->delete('/consents/{purpose}', $this->withdraw(...));
        $router->get('/consents', $this->list(...));
        $router->get('/consents/{purpose}/history', $this->history(...));
    }

    private function grant(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $purpose = $this->purpose($body['purpose'] ?? null);
        if ($purpose === null) {
            throw $this->purposeError();
        }
        // The endpoint decides `granted` — never the body (mass-assignment guard).
        $this->repo->record($userId, $purpose, true, $this->now());
        return $this->json->create(['purpose' => $purpose, 'granted' => true], 201);
    }

    private function withdraw(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        $purpose = $this->purposeParam($request);
        if ($purpose === null) {
            throw $this->purposeError();
        }
        $this->repo->record($userId, $purpose, false, $this->now());
        return $this->json->create(['purpose' => $purpose, 'granted' => false]);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        // Unknown user → empty 200 (no enumeration oracle), never 404.
        $consents = array_map(
            static fn (array $c): array => [
                'purpose' => (string) $c['purpose'],
                'granted' => ((int) $c['granted']) === 1,
                'updated_at' => (string) $c['updated_at'],
            ],
            $this->repo->listCurrent($userId),
        );
        return $this->json->create(['consents' => $consents, 'count' => count($consents)]);
    }

    private function history(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        $purpose = $this->purposeParam($request);
        if ($purpose === null) {
            throw $this->purposeError();
        }
        $history = array_map(
            static fn (array $h): array => [
                'granted' => ((int) $h['granted']) === 1,
                'created_at' => (string) $h['created_at'],
            ],
            $this->repo->history($userId, $purpose),
        );
        return $this->json->create(['purpose' => $purpose, 'history' => $history, 'count' => count($history)]);
    }

    private function userId(ServerRequestInterface $request): ?int
    {
        return V::userId($request->getHeaderLine('X-User-Id'));
    }

    /** is_string → ctype_alnum → length (no regex, ReDoS-immune). */
    private function purpose(mixed $raw): ?string
    {
        if (!is_string($raw) || !ctype_alnum($raw) || strlen($raw) > 50) {
            return null;
        }
        return $raw;
    }

    private function purposeParam(ServerRequestInterface $request): ?string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return $this->purpose($params['purpose'] ?? null);
    }

    private function purposeError(): ValidationException
    {
        return new ValidationException([new ValidationError('purpose', 'purpose must be alphanumeric (<= 50 chars)', 'invalid_value')]);
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
