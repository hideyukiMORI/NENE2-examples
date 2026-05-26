<?php

declare(strict_types=1);

namespace SoftDelete;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private SqliteNoteRepository         $repo,
        private JsonResponseFactory          $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/notes', $this->create(...));
        $router->get('/notes', $this->listActive(...));
        $router->get('/notes/trash', $this->listTrashed(...));
        $router->get('/notes/{id}', $this->get(...));
        $router->delete('/notes/{id}', $this->softDelete(...));
        $router->post('/notes/{id}/restore', $this->restore(...));
        $router->delete('/notes/{id}/purge', $this->purge(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        $noteBody = isset($body['body']) && is_string($body['body']) ? $body['body'] : '';

        if ($title === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'Title is required.']],
            ]);
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $note = $this->repo->create($title, $noteBody, $now);

        return $this->json->create($note->toArray(), 201);
    }

    private function listActive(ServerRequestInterface $request): ResponseInterface
    {
        $notes = $this->repo->listActive();

        return $this->json->create(['items' => array_map(static fn (Note $n): array => $n->toArray(), $notes)]);
    }

    private function listTrashed(ServerRequestInterface $request): ResponseInterface
    {
        $notes = $this->repo->listTrashed();

        return $this->json->create(['items' => array_map(static fn (Note $n): array => $n->toArray(), $notes)]);
    }

    private function get(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) Router::param($request, 'id');
        $note   = $this->repo->findById($id);

        if ($note === null) {
            return $this->problems->create($request, 'not-found', 'Note not found.', 404, '');
        }

        return $this->json->create($note->toArray());
    }

    private function softDelete(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) Router::param($request, 'id');
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $note   = $this->repo->softDelete($id, $now);

        if ($note === null) {
            return $this->problems->create($request, 'not-found', 'Note not found.', 404, '');
        }

        return $this->json->create($note->toArray());
    }

    private function restore(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) Router::param($request, 'id');
        $note   = $this->repo->restore($id);

        if ($note === null) {
            return $this->problems->create($request, 'not-found', 'Note not found or not deleted.', 404, '');
        }

        return $this->json->create($note->toArray());
    }

    private function purge(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) Router::param($request, 'id');
        $ok     = $this->repo->purge($id);

        if (!$ok) {
            return $this->problems->create($request, 'not-found', 'Note not found or not in trash.', 404, '');
        }

        return $this->json->create(['deleted' => true]);
    }
}
