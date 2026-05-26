<?php

declare(strict_types=1);

namespace Version\V1;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Version\Shared\Note;
use Version\Shared\NoteRepository;

final readonly class RouteRegistrar
{
    // Sunset date for v1 (RFC 8594)
    private const string SUNSET = 'Sat, 31 Dec 2026 23:59:59 GMT';

    public function __construct(
        private NoteRepository                $notes,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/v1/notes', $this->list(...));
        $router->post('/v1/notes', $this->create(...));
        $router->get('/v1/notes/{id}', $this->get(...));
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $notes = $this->notes->findAll();

        $response = $this->json->create([
            'notes' => array_map(fn (Note $n) => $this->toV1($n), $notes),
        ]);

        return $this->withDeprecationHeaders($response);
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body  = json_decode((string) $request->getBody(), true);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        // v1 uses "content" field name
        $content = isset($body['content']) && is_string($body['content']) ? trim($body['content']) : '';

        if ($title === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
            ]);
        }

        $note     = $this->notes->create($title, $content);
        $response = $this->json->create($this->toV1($note), 201);

        return $this->withDeprecationHeaders($response);
    }

    private function get(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $note   = $this->notes->findById($id);

        if ($note === null) {
            return $this->problems->create($request, 'not-found', 'Note not found.', 404);
        }

        $response = $this->json->create($this->toV1($note));

        return $this->withDeprecationHeaders($response);
    }

    /** @return array<string, mixed> */
    private function toV1(Note $note): array
    {
        return [
            'id'         => $note->id,
            'title'      => $note->title,
            'content'    => $note->body,     // v1 uses "content" (renamed to "body" in v2)
            'created_at' => $note->createdAt,
        ];
        // v1 omits: tags, updated_at (added in v2)
    }

    private function withDeprecationHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Deprecation', 'true')
            ->withHeader('Sunset', self::SUNSET)
            ->withHeader('Link', '</v2/notes>; rel="successor-version"');
    }
}
