<?php

declare(strict_types=1);

namespace Version\V2;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Version\Shared\Note;
use Version\Shared\NoteRepository;

final readonly class RouteRegistrar
{
    public function __construct(
        private NoteRepository                $notes,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/v2/notes', $this->list(...));
        $router->post('/v2/notes', $this->create(...));
        $router->get('/v2/notes/{id}', $this->get(...));
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $limit  = max(1, min((int) ($q['limit'] ?? 20), 100));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $notes = $this->notes->findAll($limit, $offset);

        return $this->json->create([
            'data'   => array_map(fn (Note $n) => $this->toV2($n), $notes),
            'meta'   => ['limit' => $limit, 'offset' => $offset],
        ]);
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body  = json_decode((string) $request->getBody(), true);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        // v2 uses "body" field name (renamed from "content" in v1)
        $text = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : '';
        /** @var list<string> $tags */
        $tags = isset($body['tags']) && is_array($body['tags'])
            ? array_values(array_filter($body['tags'], fn ($t) => is_string($t)))
            : [];

        if ($title === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
            ]);
        }

        $note = $this->notes->create($title, $text, $tags);

        return $this->json->create(['data' => $this->toV2($note)], 201);
    }

    private function get(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $note   = $this->notes->findById($id);

        if ($note === null) {
            return $this->problems->create($request, 'not-found', 'Note not found.', 404);
        }

        return $this->json->create(['data' => $this->toV2($note)]);
    }

    /** @return array<string, mixed> */
    private function toV2(Note $note): array
    {
        return [
            'id'         => $note->id,
            'title'      => $note->title,
            'body'       => $note->body,      // renamed from "content" in v1
            'tags'       => $note->tags,      // new in v2
            'created_at' => $note->createdAt,
            'updated_at' => $note->updatedAt, // new in v2
        ];
    }
}
