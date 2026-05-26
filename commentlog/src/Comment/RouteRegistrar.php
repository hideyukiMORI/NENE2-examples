<?php

declare(strict_types=1);

namespace Comment\Comment;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private CommentRepository             $repo,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/posts', $this->createPost(...));
        $router->get('/posts/{id}', $this->getPost(...));
        $router->post('/posts/{id}/comments', $this->addComment(...));
        $router->get('/posts/{id}/comments', $this->listComments(...));
        $router->post('/comments/{id}/replies', $this->addReply(...));
        $router->delete('/comments/{id}', $this->deleteComment(...));
    }

    private function createPost(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';

        if ($title === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
            ]);
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $post = $this->repo->createPost($title, $now);

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

    private function addComment(ServerRequestInterface $request): ResponseInterface
    {
        $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $postId     = (int) ($params['id'] ?? 0);
        $post       = $this->repo->findPostById($postId);

        if ($post === null) {
            return $this->problems->create($request, 'not-found', 'Post not found.', 404, '');
        }

        $body       = JsonRequestBodyParser::parse($request);
        $authorName = isset($body['author_name']) && is_string($body['author_name']) ? trim($body['author_name']) : '';
        $text       = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : '';

        if ($authorName === '' || $text === '') {
            $errors = [];
            if ($authorName === '') {
                $errors[] = ['field' => 'author_name', 'code' => 'required', 'message' => 'author_name is required.'];
            }

            if ($text === '') {
                $errors[] = ['field' => 'body', 'code' => 'required', 'message' => 'body is required.'];
            }

            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, ['errors' => $errors]);
        }

        $now     = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $comment = $this->repo->addComment($postId, null, $authorName, $text, 0, $now);

        return $this->json->create($comment->toArray(), 201);
    }

    private function listComments(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $postId = (int) ($params['id'] ?? 0);
        $post   = $this->repo->findPostById($postId);

        if ($post === null) {
            return $this->problems->create($request, 'not-found', 'Post not found.', 404, '');
        }

        $tree = $this->repo->findCommentTree($postId);

        return $this->json->create(['comments' => array_map(static fn (Comment $c) => $c->toArray(), $tree)]);
    }

    private function addReply(ServerRequestInterface $request): ResponseInterface
    {
        $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $parentId  = (int) ($params['id'] ?? 0);
        $parent    = $this->repo->findCommentById($parentId);

        if ($parent === null) {
            return $this->problems->create($request, 'not-found', 'Comment not found.', 404, '');
        }

        if ($parent->isDeleted()) {
            return $this->problems->create($request, 'conflict', 'Cannot reply to a deleted comment.', 409, '');
        }

        if (!$parent->canHaveReplies()) {
            return $this->problems->create($request, 'unprocessable-entity', 'Maximum comment depth reached.', 422, '');
        }

        $body       = JsonRequestBodyParser::parse($request);
        $authorName = isset($body['author_name']) && is_string($body['author_name']) ? trim($body['author_name']) : '';
        $text       = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : '';

        if ($authorName === '' || $text === '') {
            $errors = [];
            if ($authorName === '') {
                $errors[] = ['field' => 'author_name', 'code' => 'required', 'message' => 'author_name is required.'];
            }

            if ($text === '') {
                $errors[] = ['field' => 'body', 'code' => 'required', 'message' => 'body is required.'];
            }

            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, ['errors' => $errors]);
        }

        $now     = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $comment = $this->repo->addComment($parent->postId, $parentId, $authorName, $text, $parent->depth + 1, $now);

        return $this->json->create($comment->toArray(), 201);
    }

    private function deleteComment(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id      = (int) ($params['id'] ?? 0);
        $comment = $this->repo->findCommentById($id);

        if ($comment === null) {
            return $this->problems->create($request, 'not-found', 'Comment not found.', 404, '');
        }

        if ($comment->isDeleted()) {
            return $this->problems->create($request, 'conflict', 'Comment already deleted.', 409, '');
        }

        $this->repo->softDelete($id);

        return $this->json->create([], 204);
    }
}
