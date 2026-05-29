<?php

declare(strict_types=1);

namespace UnicodeLog\Profile;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly ProfileRepository $repo,
        private readonly UnicodeValidator $validator,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/profiles', $this->create(...));
        $router->get('/profiles', $this->list(...));
        $router->get('/profiles/{id}', $this->show(...));
        $router->patch('/profiles/{id}', $this->update(...));
        $router->delete('/profiles/{id}', $this->delete(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $name = $this->validator->name($body['name'] ?? null);
        $bio = $this->validator->bio($body['bio'] ?? null);
        $tags = $this->validator->tags($body['tags'] ?? null);
        $id = $this->repo->create($name, $bio, $tags, $this->now());
        return $this->json->create($this->view((array) $this->repo->find($id)), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $items = array_map($this->view(...), $this->repo->listAll());
        return $this->json->create(['items' => $items, 'total' => count($items)]);
    }

    private function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $profile = $id === 0 ? null : $this->repo->find($id);
        if ($profile === null) {
            return $this->notFound();
        }
        return $this->json->create($this->view($profile));
    }

    private function update(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($id === 0 || $this->repo->find($id) === null) {
            return $this->notFound();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $name = $this->validator->name($body['name'] ?? null);
        $bio = $this->validator->bio($body['bio'] ?? null);
        $tags = $this->validator->tags($body['tags'] ?? null);
        $this->repo->update($id, $name, $bio, $tags);
        return $this->json->create($this->view((array) $this->repo->find($id)));
    }

    private function delete(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($id === 0 || $this->repo->delete($id) === 0) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    /**
     * Stored text is returned verbatim — tags decoded back to an array so clients
     * never have to double-decode.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function view(array $p): array
    {
        $tags = json_decode((string) $p['tags'], true);
        return [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'bio' => (string) $p['bio'],
            'tags' => is_array($tags) ? array_values($tags) : [],
            'created_at' => (string) $p['created_at'],
        ];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        return ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : 0;
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'profile not found'], 404);
    }
}
