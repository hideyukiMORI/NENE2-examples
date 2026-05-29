<?php

declare(strict_types=1);

namespace TemplateLog\Template;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const string PLACEHOLDER_PATTERN = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';

    public function __construct(
        private readonly TemplateRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/templates', $this->handleCreate(...));
        $router->get('/templates', $this->handleList(...));
        $router->get('/templates/{id}', $this->handleGet(...));
        $router->put('/templates/{id}', $this->handleUpdate(...));
        $router->delete('/templates/{id}', $this->handleDelete(...));
        $router->post('/templates/{id}/render', $this->handleRender(...));
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $name = $body['name'] ?? null;
        $tmplBody = $body['body'] ?? null;

        $errors = [];
        if (!is_string($name) || trim($name) === '') {
            $errors[] = new ValidationError('name', 'name must be a non-empty string', 'invalid_value');
        }
        if (!is_string($tmplBody)) {
            $errors[] = new ValidationError('body', 'body must be a string', 'invalid_type');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($name) && is_string($tmplBody));

        $id = $this->repo->create(trim($name), $tmplBody, $this->now());
        if ($id === null) {
            return $this->json->create(['error' => 'A template with that name already exists'], 409);
        }
        $tmpl = $this->repo->findById($id);
        assert($tmpl !== null);
        return $this->json->create($this->project($tmpl), 201);
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $templates = array_map(
            static fn (array $t): array => [
                'id' => (int) $t['id'],
                'name' => (string) $t['name'],
                'updated_at' => (string) $t['updated_at'],
            ],
            $this->repo->listAll(),
        );
        return $this->json->create(['templates' => $templates, 'count' => count($templates)]);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $tmpl = $this->repo->findById($this->idParam($request));
        if ($tmpl === null) {
            return $this->notFound();
        }
        return $this->json->create($this->project($tmpl));
    }

    private function handleUpdate(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $id = $this->idParam($request);
        if ($this->repo->findById($id) === null) {
            return $this->notFound();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $tmplBody = $body['body'] ?? null;
        if (!is_string($tmplBody)) {
            throw new ValidationException([new ValidationError('body', 'body must be a string', 'invalid_type')]);
        }

        $this->repo->updateBody($id, $tmplBody, $this->now());
        // The row's existence was confirmed above; re-read it for the response.
        $tmpl = (array) $this->repo->findById($id);
        return $this->json->create($this->project($tmpl));
    }

    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        if (!$this->repo->delete($this->idParam($request))) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    private function handleRender(ServerRequestInterface $request): ResponseInterface
    {
        // Render is public.
        $tmpl = $this->repo->findById($this->idParam($request));
        if ($tmpl === null) {
            return $this->notFound();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $vars = $body['vars'] ?? [];
        if (!is_array($vars)) {
            throw new ValidationException([new ValidationError('vars', 'vars must be an object', 'invalid_type')]);
        }

        return $this->json->create([
            'id' => (int) $tmpl['id'],
            'rendered' => $this->renderBody((string) $tmpl['body'], $vars),
        ]);
    }

    /**
     * Substitute `{{ key }}` placeholders with the matching var. Unknown
     * placeholders are left verbatim (no error), per the howto.
     *
     * @param array<array-key, mixed> $vars
     */
    private function renderBody(string $body, array $vars): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static function (array $m) use ($vars): string {
                $key = $m[1];
                if (!array_key_exists($key, $vars) || !is_scalar($vars[$key])) {
                    return $m[0]; // leave unknown / non-scalar placeholders as-is
                }
                return (string) $vars[$key];
            },
            $body,
        );
    }

    /**
     * @param array<string, mixed> $tmpl
     * @return array<string, mixed>
     */
    private function project(array $tmpl): array
    {
        return [
            'id' => (int) $tmpl['id'],
            'name' => (string) $tmpl['name'],
            'body' => (string) $tmpl['body'],
            'created_at' => (string) $tmpl['created_at'],
            'updated_at' => (string) $tmpl['updated_at'],
        ];
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        $provided = $request->getHeaderLine('X-Admin-Key');
        return $provided !== '' && hash_equals($this->adminKey, $provided);
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['id'] ?? 0);
    }

    private function forbidden(): ResponseInterface
    {
        return $this->json->create(['error' => 'Admin key required'], 403);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Template not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
