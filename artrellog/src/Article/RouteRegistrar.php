<?php

declare(strict_types=1);

namespace Relations\Article;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\QueryStringParser;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly ArticleRepository $repo,
        private readonly RelationService $service,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/articles', $this->createArticle(...));
        $router->get('/articles/{id}', $this->getArticle(...));
        $router->post('/articles/{id}/relations', $this->addRelation(...));
        $router->get('/articles/{id}/relations', $this->listRelations(...));
        $router->delete('/articles/{id}/relations/{relatedId}', $this->removeRelation(...));
    }

    private function createArticle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];
        $title = is_string($body['title'] ?? null) ? trim((string) $body['title']) : '';
        if ($title === '') {
            $errors[] = new ValidationError('title', 'title must not be empty', 'required');
        }
        $content = is_string($body['body'] ?? null) ? (string) $body['body'] : null;
        if ($content === null || trim($content) === '') {
            $errors[] = new ValidationError('body', 'body must not be empty', 'required');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($content));

        $id = $this->repo->create($title, $content, $this->now());
        return $this->json->create($this->articleView((array) $this->repo->find($id)), 201);
    }

    private function getArticle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request, 'id');
        if ($id === null) {
            return $this->notFound();
        }
        $article = $this->repo->find($id);
        if ($article === null) {
            return $this->notFound();
        }
        return $this->json->create([
            'data' => $this->articleView($article),
            'relations' => $this->relationViews($id, null),
        ]);
    }

    private function addRelation(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request, 'id');
        if ($id === null || $this->repo->find($id) === null) {
            return $this->notFound();
        }
        $body = (array) ($request->getParsedBody() ?? []);

        $relatedId = $body['related_id'] ?? null;
        $type = $body['relation_type'] ?? null;
        $errors = [];
        if (!is_int($relatedId) || $relatedId <= 0) {
            $errors[] = new ValidationError('related_id', 'related_id must be a positive integer', 'invalid_value');
        }
        if (!is_string($type) || !array_key_exists($type, RelationService::INVERSE)) {
            $errors[] = new ValidationError('relation_type', 'relation_type must be one of: ' . implode(', ', array_keys(RelationService::INVERSE)), 'invalid_value');
        }
        if (is_int($relatedId) && $relatedId === $id) {
            $errors[] = new ValidationError('related_id', 'an article cannot relate to itself', 'invalid_value');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($type));

        if ($this->repo->find($relatedId) === null) {
            return $this->json->create(['error' => 'related article not found'], 404);
        }
        if ($this->repo->relationExists($id, $relatedId, $type)) {
            return $this->json->create(['error' => 'relation already exists'], 409);
        }

        $this->service->add($id, $relatedId, $type, $this->now());
        return $this->json->create(['relations' => $this->relationViews($id, null)], 201);
    }

    private function listRelations(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request, 'id');
        if ($id === null || $this->repo->find($id) === null) {
            return $this->notFound();
        }
        $type = QueryStringParser::string($request, 'type');
        if ($type !== null && !array_key_exists($type, RelationService::INVERSE)) {
            throw new ValidationException([new ValidationError('type', 'unknown relation_type', 'invalid_value')]);
        }
        return $this->json->create(['relations' => $this->relationViews($id, $type)]);
    }

    private function removeRelation(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request, 'id');
        $relatedId = $this->idParam($request, 'relatedId');
        if ($id === null || $relatedId === null || $this->repo->find($id) === null) {
            return $this->notFound();
        }
        $type = QueryStringParser::string($request, 'type');
        if ($type === null || !array_key_exists($type, RelationService::INVERSE)) {
            throw new ValidationException([new ValidationError('type', 'type query parameter is required and must be a known relation_type', 'invalid_value')]);
        }
        if (!$this->repo->relationExists($id, $relatedId, $type)) {
            return $this->notFound();
        }
        $this->service->remove($id, $relatedId, $type);
        return $this->json->createEmpty(204);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relationViews(int $articleId, ?string $type): array
    {
        return array_map(
            static fn (array $r): array => [
                'relation' => ['relation_type' => (string) $r['relation_type']],
                'related' => ['id' => (int) $r['related_id'], 'title' => (string) $r['related_title']],
            ],
            $this->repo->relations($articleId, $type),
        );
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function articleView(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'title' => (string) $a['title'],
            'body' => (string) $a['body'],
            'created_at' => (string) $a['created_at'],
        ];
    }

    private function idParam(ServerRequestInterface $request, string $key): ?int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params[$key] ?? '');
        if (!ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Article not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
