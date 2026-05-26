<?php

declare(strict_types=1);

namespace Opt\Article;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly ArticleRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/articles', $this->create(...));
        $router->patch('/articles/{id}', $this->update(...));
        $router->get('/articles/{id}', $this->get(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body) || !isset($body['title'], $body['body']) || !is_string($body['title']) || !is_string($body['body'])) {
            return $this->problems->create($request, 'invalid-body', 'title and body (string) are required.', 400);
        }

        $article = $this->repo->create(trim($body['title']), trim($body['body']));
        return $this->json->create($this->serialize($article), 201);
    }

    private function update(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) Router::param($request, 'id');
        $body = json_decode((string) $request->getBody(), true);

        if (
            !is_array($body) ||
            !isset($body['title'], $body['body'], $body['version']) ||
            !is_string($body['title']) ||
            !is_string($body['body']) ||
            !is_int($body['version'])
        ) {
            return $this->problems->create(
                $request,
                'invalid-body',
                'title (string), body (string), and version (int) are required.',
                400,
            );
        }

        try {
            $article = $this->repo->update($id, trim($body['title']), trim($body['body']), $body['version']);
            return $this->json->create($this->serialize($article));
        } catch (ConflictException $e) {
            $current = $this->repo->findById($id);
            return $this->problems->create(
                $request,
                'conflict',
                'Optimistic lock conflict.',
                409,
                $e->getMessage(),
                $current !== null ? ['current_version' => $current->version] : [],
            );
        } catch (\RuntimeException $e) {
            return $this->problems->create($request, 'not-found', 'Article not found.', 404);
        }
    }

    private function get(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) Router::param($request, 'id');
        $article = $this->repo->findById($id);

        if ($article === null) {
            return $this->problems->create($request, 'not-found', 'Article not found.', 404);
        }

        return $this->json->create($this->serialize($article));
    }

    /** @return array<string, mixed> */
    private function serialize(Article $article): array
    {
        return [
            'id'         => $article->id,
            'title'      => $article->title,
            'body'       => $article->body,
            'version'    => $article->version,
            'updated_at' => $article->updatedAt,
        ];
    }
}
