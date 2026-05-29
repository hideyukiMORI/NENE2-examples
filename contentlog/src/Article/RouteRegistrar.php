<?php

declare(strict_types=1);

namespace ContentLog\Article;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
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
        $router->get('/articles', $this->list(...));
        $router->get('/articles/{id}', $this->show(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        // Content negotiation on the *request* side: a JSON body is required.
        // An explicit non-JSON Content-Type is rejected with 415; a missing
        // Content-Type with a valid JSON body is accepted.
        $contentType = $request->getHeaderLine('Content-Type');
        if ($contentType !== '' && !$this->isJsonContentType($contentType)) {
            return $this->problems->create(
                $request,
                'unsupported-media-type',
                'Unsupported Media Type',
                415,
                'Content-Type must be application/json.',
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        if ($title === '') {
            return $this->problems->create(
                $request,
                'validation-failed',
                'Validation Failed',
                422,
                null,
                ['errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']]],
            );
        }
        $articleBody = isset($body['body']) && is_string($body['body']) ? $body['body'] : '';

        $id = $this->repo->create($title, $articleBody, $this->now());
        // Success responses are always application/json (Accept header ignored).
        return $this->json->create($this->view((array) $this->repo->find($id)), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $items = array_map($this->view(...), $this->repo->listAll());
        return $this->json->create(['items' => $items, 'total' => count($items)]);
    }

    private function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $article = $id === 0 ? null : $this->repo->find($id);
        if ($article === null) {
            return $this->problems->create($request, 'not-found', 'Article Not Found', 404);
        }
        return $this->json->create($this->view($article));
    }

    /** Accept `application/json` and any `application/json;…` parameters. */
    private function isJsonContentType(string $contentType): bool
    {
        $base = strtolower(trim(explode(';', $contentType)[0]));
        return $base === 'application/json';
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function view(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'title' => (string) $a['title'],
            'body' => (string) $a['body'],
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
}
