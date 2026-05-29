<?php

declare(strict_types=1);

namespace DraftLog\Article;

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
        private readonly ArticleRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/articles', $this->create(...));
        $router->get('/articles', $this->list(...));
        $router->get('/articles/{id}', $this->show(...));
        $router->put('/articles/{id}', $this->update(...));
        $router->post('/articles/{id}/publish', $this->publish(...));
        $router->post('/articles/{id}/archive', $this->archive(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $authorId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($authorId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            throw new ValidationException([new ValidationError('title', 'title must be a non-empty string', 'invalid_value')]);
        }
        $text = V::str($body['body'] ?? '', 20000) ?? '';

        $now = $this->now();
        $id = $this->repo->create($authorId, $title, $text, $now); // always starts as draft
        return $this->json->create($this->view((array) $this->repo->find($id)), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        // Public list: published only, no auth required.
        $params = $request->getQueryParams();
        $limit = V::queryInt($params, 'limit', 1, 100, 20);
        $offset = V::queryInt($params, 'offset', 0, 1000000, 0);
        if ($limit === null || $offset === null) {
            throw new ValidationException([new ValidationError('limit', 'limit (1..100) / offset (>=0) must be valid', 'invalid_value')]);
        }
        $items = array_map($this->view(...), $this->repo->listPublished($limit, $offset));
        return $this->json->create(['articles' => $items, 'count' => count($items)]);
    }

    private function show(ServerRequestInterface $request): ResponseInterface
    {
        // Actor is optional: an author may read their own non-published articles.
        $actorId = null;
        $header = $request->getHeaderLine('X-User-Id');
        if ($header !== '') {
            $actorId = V::userId($header);
            if ($actorId === null) {
                return $this->unauthorized();
            }
        }
        $id = $this->idParam($request);
        $article = $id === 0 ? null : $this->repo->find($id);
        // Non-authors only see published. Return 404 (not 403) to avoid leaking existence.
        if ($article === null || ((string) $article['status'] !== 'published' && (int) $article['author_id'] !== $actorId)) {
            return $this->notFound();
        }
        return $this->json->create($this->view($article));
    }

    private function update(ServerRequestInterface $request): ResponseInterface
    {
        [$article, $error] = $this->authorOwned($request);
        if ($article === null) {
            assert($error !== null);
            return $error;
        }
        $status = ArticleStatus::tryFrom((string) $article['status']) ?? ArticleStatus::Draft;
        if (!$status->canEdit()) {
            return $this->json->create(['error' => 'only draft articles can be edited'], 422);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            throw new ValidationException([new ValidationError('title', 'title must be a non-empty string', 'invalid_value')]);
        }
        $text = V::str($body['body'] ?? '', 20000) ?? '';
        $id = (int) $article['id'];
        $this->repo->updateContent($id, $title, $text, $this->now());
        return $this->json->create($this->view((array) $this->repo->find($id)));
    }

    private function publish(ServerRequestInterface $request): ResponseInterface
    {
        [$article, $error] = $this->authorOwned($request);
        if ($article === null) {
            assert($error !== null);
            return $error;
        }
        $status = ArticleStatus::tryFrom((string) $article['status']) ?? ArticleStatus::Draft;
        if (!$status->canPublish()) {
            return $this->json->create(['error' => 'only draft articles can be published'], 422);
        }
        $id = (int) $article['id'];
        $this->repo->publish($id, $this->now());
        return $this->json->create($this->view((array) $this->repo->find($id)));
    }

    private function archive(ServerRequestInterface $request): ResponseInterface
    {
        [$article, $error] = $this->authorOwned($request);
        if ($article === null) {
            assert($error !== null);
            return $error;
        }
        $status = ArticleStatus::tryFrom((string) $article['status']) ?? ArticleStatus::Draft;
        if (!$status->canArchive()) {
            return $this->json->create(['error' => 'only published articles can be archived'], 422);
        }
        $id = (int) $article['id'];
        $this->repo->archive($id, $this->now());
        return $this->json->create($this->view((array) $this->repo->find($id)));
    }

    /**
     * Resolve the article and assert the caller is its author. Returns a 404 for
     * both "missing" and "not yours" to avoid existence leakage.
     *
     * @return array{0: array<string, mixed>, 1: null}|array{0: null, 1: ResponseInterface}
     */
    private function authorOwned(ServerRequestInterface $request): array
    {
        $actorId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($actorId === null) {
            return [null, $this->unauthorized()];
        }
        $id = $this->idParam($request);
        $article = $id === 0 ? null : $this->repo->find($id);
        if ($article === null || (int) $article['author_id'] !== $actorId) {
            return [null, $this->notFound()];
        }
        return [$article, null];
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function view(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'author_id' => (int) $a['author_id'],
            'title' => (string) $a['title'],
            'body' => (string) $a['body'],
            'status' => (string) $a['status'],
            'published_at' => $a['published_at'] !== null ? (string) $a['published_at'] : null,
            'archived_at' => $a['archived_at'] !== null ? (string) $a['archived_at'] : null,
            'created_at' => (string) $a['created_at'],
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

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'article not found'], 404);
    }
}
