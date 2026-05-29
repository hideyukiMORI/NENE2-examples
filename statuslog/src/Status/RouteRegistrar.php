<?php

declare(strict_types=1);

namespace StatusLog\Status;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly StatusRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/components', $this->listComponents(...));
        $router->post('/components', $this->createComponent(...));
        $router->patch('/components/{id}', $this->updateComponent(...));
        $router->get('/incidents', $this->listIncidents(...));
        $router->get('/incidents/{id}', $this->getIncident(...));
        $router->post('/incidents', $this->createIncident(...));
        $router->patch('/incidents/{id}', $this->updateIncident(...));
        $router->post('/incidents/{id}/updates', $this->addUpdate(...));
    }

    // ── components ────────────────────────────────────────────────────────

    private function listComponents(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json->create(['components' => array_map($this->componentView(...), $this->repo->listComponents())]);
    }

    private function createComponent(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $name = V::str($body['name'] ?? null, 100);
        if ($name === null || $name === '') {
            throw new ValidationException([new ValidationError('name', 'name must be a non-empty string', 'invalid_value')]);
        }
        $status = ComponentStatus::Operational;
        if (array_key_exists('status', $body)) {
            $parsed = V::enum($body['status'], ComponentStatus::class);
            if (!$parsed instanceof ComponentStatus) {
                throw $this->enumError('status', ComponentStatus::values());
            }
            $status = $parsed;
        }
        $id = $this->repo->createComponent($name, $status->value, $this->now());
        return $this->json->create($this->componentView((array) $this->repo->findComponent($id)), 201);
    }

    private function updateComponent(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $id = $this->idParam($request);
        if ($id === null || $this->repo->findComponent($id) === null) {
            return $this->notFound('Component');
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $status = V::enum($body['status'] ?? null, ComponentStatus::class);
        if (!$status instanceof ComponentStatus) {
            throw $this->enumError('status', ComponentStatus::values());
        }
        $this->repo->updateComponentStatus($id, $status->value, $this->now());
        return $this->json->create($this->componentView((array) $this->repo->findComponent($id)));
    }

    // ── incidents ─────────────────────────────────────────────────────────

    private function listIncidents(ServerRequestInterface $request): ResponseInterface
    {
        $openOnly = (V::queryInt($request->getQueryParams(), 'open', 0, 1, 0) ?? 0) === 1;
        $incidents = array_map($this->incidentView(...), $this->repo->listIncidents($openOnly));
        return $this->json->create(['incidents' => $incidents, 'count' => count($incidents)]);
    }

    private function getIncident(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($id === null) {
            return $this->notFound('Incident');
        }
        $incident = $this->repo->findIncident($id);
        if ($incident === null) {
            return $this->notFound('Incident');
        }
        $view = $this->incidentView($incident);
        $view['updates'] = array_map(
            static fn (array $u): array => [
                'status' => (string) $u['status'],
                'message' => (string) $u['message'],
                'created_at' => (string) $u['created_at'],
            ],
            $this->repo->updates($id),
        );
        return $this->json->create($view);
    }

    private function createIncident(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            throw new ValidationException([new ValidationError('title', 'title must be a non-empty string', 'invalid_value')]);
        }
        $impact = V::enum($body['impact'] ?? null, ImpactLevel::class);
        if (!$impact instanceof ImpactLevel) {
            throw $this->enumError('impact', ImpactLevel::values());
        }
        $id = $this->repo->createIncident($title, IncidentStatus::Investigating->value, $impact->value, $this->now());
        return $this->json->create($this->incidentView((array) $this->repo->findIncident($id)), 201);
    }

    private function updateIncident(ServerRequestInterface $request): ResponseInterface
    {
        [$id, $incident, $status, $error] = $this->resolveTransition($request);
        if ($error !== null) {
            return $error;
        }
        $this->repo->updateIncidentStatus($id, $status->value, $this->resolvedAt($status), $this->now());
        return $this->json->create($this->incidentView((array) $this->repo->findIncident($id)));
    }

    private function addUpdate(ServerRequestInterface $request): ResponseInterface
    {
        [$id, $incident, $status, $error] = $this->resolveTransition($request);
        if ($error !== null) {
            return $error;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $message = V::str($body['message'] ?? null, 2000);
        if ($message === null || $message === '') {
            throw new ValidationException([new ValidationError('message', 'message must be a non-empty string', 'invalid_value')]);
        }
        $now = $this->now();
        $this->repo->addUpdate($id, $status->value, $message, $now);
        $this->repo->updateIncidentStatus($id, $status->value, $this->resolvedAt($status), $now);
        return $this->json->create($this->incidentView((array) $this->repo->findIncident($id)), 201);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Shared admin + existence + transition-guard + status-parse for the two
     * status-changing endpoints.
     *
     * @return array{0: int, 1: array<string, mixed>, 2: IncidentStatus, 3: ResponseInterface|null}
     */
    private function resolveTransition(ServerRequestInterface $request): array
    {
        $fallback = [0, [], IncidentStatus::Investigating, null];
        if (!$this->isAdmin($request)) {
            return [...$fallback, 3 => $this->forbidden()];
        }
        $id = $this->idParam($request);
        $incident = $id === null ? null : $this->repo->findIncident($id);
        if ($id === null || $incident === null) {
            return [...$fallback, 3 => $this->notFound('Incident')];
        }
        // Transition guard: resolved incidents are immutable.
        $current = IncidentStatus::from((string) $incident['status']);
        if ($current->isResolved()) {
            return [$id, $incident, IncidentStatus::Resolved, $this->json->create(['error' => 'Resolved incidents cannot be updated'], 409)];
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $status = V::enum($body['status'] ?? null, IncidentStatus::class);
        if (!$status instanceof IncidentStatus) {
            return [$id, $incident, $current, $this->json->create(['error' => 'status must be one of: ' . implode(', ', IncidentStatus::values())], 422)];
        }
        return [$id, $incident, $status, null];
    }

    private function resolvedAt(IncidentStatus $status): ?string
    {
        return $status->isResolved() ? $this->now() : null;
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        return V::secret($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function componentView(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'name' => (string) $c['name'],
            'status' => (string) $c['status'],
            'updated_at' => (string) $c['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $i
     * @return array<string, mixed>
     */
    private function incidentView(array $i): array
    {
        return [
            'id' => (int) $i['id'],
            'title' => (string) $i['title'],
            'status' => (string) $i['status'],
            'impact' => (string) $i['impact'],
            'resolved_at' => $i['resolved_at'] === null ? null : (string) $i['resolved_at'],
            'created_at' => (string) $i['created_at'],
        ];
    }

    /** @param list<string> $values */
    private function enumError(string $field, array $values): ValidationException
    {
        return new ValidationException([new ValidationError($field, $field . ' must be one of: ' . implode(', ', $values), 'invalid_value')]);
    }

    private function idParam(ServerRequestInterface $request): ?int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        if (!ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function forbidden(): ResponseInterface
    {
        return $this->json->create(['error' => 'Admin key required'], 403);
    }

    private function notFound(string $what): ResponseInterface
    {
        return $this->json->create(['error' => $what . ' not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
