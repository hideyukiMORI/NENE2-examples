<?php

declare(strict_types=1);

namespace Throttle\Notes;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly NoteRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {}

    public function register(Router $router): void
    {
        $router->get('/notes', $this->list(...));
        $router->post('/notes', $this->create(...));
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json->createList(array_map(
            fn(Note $n): array => $this->serialize($n),
            $this->repo->findAll(),
        ));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body) || !isset($body['content']) || !is_string($body['content'])) {
            return $this->problems->create($request, 'invalid-body', 'content (string) is required.', 400);
        }

        $note = $this->repo->create(trim($body['content']));
        return $this->json->create($this->serialize($note), 201);
    }

    /** @return array<string, mixed> */
    private function serialize(Note $note): array
    {
        return [
            'id'         => $note->id,
            'content'    => $note->content,
            'created_at' => $note->createdAt,
        ];
    }
}
