<?php

declare(strict_types=1);

namespace Tag\Tag;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private TagRepository                 $repo,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/posts', $this->createPost(...));
        $router->get('/posts/{id}', $this->getPost(...));
        $router->post('/tags', $this->createTag(...));
        $router->get('/tags', $this->listTags(...));
        $router->put('/posts/{id}/tags', $this->setPostTags(...));
        $router->get('/tags/{name}/posts', $this->listPostsByTag(...));
    }

    private function createPost(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        $text  = isset($body['body']) && is_string($body['body']) ? $body['body'] : '';

        if ($title === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
            ]);
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $post = $this->repo->createPost($title, $text, $now);

        return $this->json->create($post->toArray(), 201);
    }

    private function getPost(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $post   = $this->repo->findPostById($id);

        if ($post === null) {
            return $this->problems->create($request, 'not-found', 'Post not found.', 404, '');
        }

        return $this->json->create($post->toArray());
    }

    private function createTag(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);
        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';

        if ($name === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'name', 'code' => 'required', 'message' => 'name is required.']],
            ]);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $tag = $this->repo->createTag($name, $now);

        if ($tag === null) {
            return $this->problems->create($request, 'conflict', 'Tag name already exists.', 409, '');
        }

        return $this->json->create($tag->toArray(), 201);
    }

    private function listTags(ServerRequestInterface $request): ResponseInterface
    {
        $tags = $this->repo->findAllTags();

        return $this->json->create(['tags' => array_map(static fn (Tag $t) => $t->toArray(), $tags)]);
    }

    private function setPostTags(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $post   = $this->repo->findPostById($id);

        if ($post === null) {
            return $this->problems->create($request, 'not-found', 'Post not found.', 404, '');
        }

        $body     = JsonRequestBodyParser::parse($request);
        $tagNames = isset($body['tags']) && is_array($body['tags']) ? $body['tags'] : [];

        // Accept only string tag names
        $tagNames = array_filter($tagNames, static fn (mixed $v) => is_string($v));
        $tagNames = array_values(array_unique($tagNames));

        $tags     = $this->repo->setPostTags($id, $tagNames);
        $updated  = $this->repo->findPostById($id);

        return $this->json->create(($updated ?? $post)->toArray());
    }

    private function listPostsByTag(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $tagName = (string) ($params['name'] ?? '');
        $tag     = $this->repo->findTagByName($tagName);

        if ($tag === null) {
            return $this->problems->create($request, 'not-found', 'Tag not found.', 404, '');
        }

        $posts = $this->repo->findPostsByTag($tagName);

        return $this->json->create(['posts' => array_map(static fn (Post $p) => $p->toArray(), $posts)]);
    }
}
