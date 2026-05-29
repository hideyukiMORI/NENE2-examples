<?php

declare(strict_types=1);

namespace ShortLog\Link;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const string SLUG_PATTERN = '/\A[a-z0-9_-]{3,20}\z/';

    public function __construct(
        private readonly LinkRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly UrlValidator $validator,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/links', $this->create(...));
        $router->get('/links', $this->list(...));
        $router->get('/links/{slug}', $this->get(...));
        $router->delete('/links/{slug}', $this->delete(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $url = V::str($body['url'] ?? null, 2048);
        if ($url === null || $url === '') {
            throw new ValidationException([new ValidationError('url', 'url must be a non-empty string', 'invalid_value')]);
        }
        if (!$this->validator->isSafe($url)) {
            throw new ValidationException([new ValidationError('url', 'url must be a public http(s) URL (SSRF protection)', 'invalid_value')]);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        // Retry a few times on the (astronomically unlikely) slug collision.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $slug = bin2hex(random_bytes(4));
            if ($this->repo->create($userId, $slug, $url, $now) !== null) {
                return $this->json->create($this->view((array) $this->repo->findBySlug($slug)), 201);
            }
        }
        return $this->json->create(['error' => 'could not allocate a unique slug'], 503);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $limit = V::queryInt($request->getQueryParams(), 'limit', 1, 100, 20);
        if ($limit === null) {
            throw new ValidationException([new ValidationError('limit', 'limit must be 1..100', 'invalid_value')]);
        }
        return $this->json->create(['links' => array_map($this->view(...), $this->repo->listOwned($userId, $limit))]);
    }

    private function get(ServerRequestInterface $request): ResponseInterface
    {
        $slug = $this->slugParam($request);
        if ($slug === null) {
            return $this->notFound();
        }
        $link = $this->repo->findBySlug($slug);
        if ($link === null) {
            return $this->notFound();
        }
        return $this->json->create($this->view($link));
    }

    private function delete(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $slug = $this->slugParam($request);
        if ($slug === null || !$this->repo->deleteOwned($slug, $userId)) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    private function slugParam(ServerRequestInterface $request): ?string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $slug = (string) ($params['slug'] ?? '');
        return preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : null;
    }

    /**
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    private function view(array $link): array
    {
        return [
            'id' => (int) $link['id'],
            'slug' => (string) $link['slug'],
            'url' => (string) $link['url'],
            'click_count' => (int) $link['click_count'],
            'created_at' => (string) $link['created_at'],
        ];
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Link not found'], 404);
    }
}
