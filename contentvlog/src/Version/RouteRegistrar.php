<?php

declare(strict_types=1);

namespace ContentVLog\Version;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly ArticleRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/articles', $this->handleCreate(...));
        $router->get('/articles/{id}', $this->handleGet(...));
        $router->put('/articles/{id}', $this->handleUpdate(...));
        $router->get('/articles/{id}/versions', $this->handleListVersions(...));
        $router->get('/articles/{id}/versions/{version}', $this->handleGetVersion(...));
        $router->post('/articles/{id}/rollback', $this->handleRollback(...));
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        [$title, $body] = $this->parseBody($request);
        $now = $this->now();
        $id  = $this->repo->create($title, $body, $now);
        return $this->json->create($this->formatArticle($this->repo->find($id)), 201);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $id      = $this->id($request);
        $article = $this->repo->find($id);
        if ($article === null) {
            return $this->json->create(['error' => 'Article not found'], 404);
        }
        return $this->json->create($this->formatArticle($article));
    }

    private function handleUpdate(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->id($request);
        [$title, $body] = $this->parseBody($request);

        if (!$this->repo->update($id, $title, $body, $this->now())) {
            return $this->json->create(['error' => 'Article not found'], 404);
        }
        return $this->json->create($this->formatArticle($this->repo->find($id)));
    }

    private function handleListVersions(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->id($request);
        if ($this->repo->find($id) === null) {
            return $this->json->create(['error' => 'Article not found'], 404);
        }
        $versions = $this->repo->listVersions($id);
        return $this->json->create(['versions' => $versions, 'count' => count($versions)]);
    }

    private function handleGetVersion(ServerRequestInterface $request): ResponseInterface
    {
        $id      = $this->id($request);
        $version = $this->version($request);

        if ($this->repo->find($id) === null) {
            return $this->json->create(['error' => 'Article not found'], 404);
        }
        $row = $this->repo->findVersion($id, $version);
        if ($row === null) {
            return $this->json->create(['error' => 'Version not found'], 404);
        }
        return $this->json->create($row);
    }

    private function handleRollback(ServerRequestInterface $request): ResponseInterface
    {
        $id   = $this->id($request);
        $body = (array) ($request->getParsedBody() ?? []);

        if (!isset($body['version']) || !is_int($body['version']) && !ctype_digit((string) $body['version'])) {
            throw new ValidationException([new ValidationError('version', 'version is required', 'required')]);
        }
        $version = (int) $body['version'];

        if ($this->repo->find($id) === null) {
            return $this->json->create(['error' => 'Article not found'], 404);
        }
        if (!$this->repo->rollback($id, $version, $this->now())) {
            return $this->json->create(['error' => 'Version not found'], 404);
        }
        return $this->json->create(array_merge($this->formatArticle($this->repo->find($id)), ['rolled_back_from' => $version]));
    }

    /**
     * @param array<string, mixed>|null $article
     * @return array<string, mixed>
     */
    private function formatArticle(?array $article): array
    {
        if ($article === null) {
            return [];
        }
        return [
            'id'              => $article['id'],
            'title'           => $article['title'],
            'body'            => $article['body'],
            'current_version' => $article['current_version'],
            'created_at'      => $article['created_at'],
            'updated_at'      => $article['updated_at'],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $body  = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        if ($title === '') {
            $errors[] = new ValidationError('title', 'title is required', 'required');
        }

        $text = isset($body['body']) && is_string($body['body']) ? $body['body'] : '';
        if ($text === '') {
            $errors[] = new ValidationError('body', 'body is required', 'required');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$title, $text];
    }

    private function id(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['id'] ?? 0);
    }

    private function version(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['version'] ?? 0);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
