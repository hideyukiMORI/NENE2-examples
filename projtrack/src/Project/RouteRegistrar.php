<?php

declare(strict_types=1);

namespace ProjTrack\Project;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\QueryStringParser;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use ProjTrack\Task\TaskRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const array STATUSES = ['open', 'in_progress', 'done'];
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/projects', $this->listProjects(...));
        $router->post('/projects', $this->createProject(...));
        $router->get('/projects/{id}', $this->getProject(...));
        $router->delete('/projects/{id}', $this->deleteProject(...));
        $router->get('/projects/{projectId}/tasks', $this->listTasks(...));
        $router->post('/projects/{projectId}/tasks', $this->createTask(...));
        $router->get('/projects/{projectId}/tasks/{taskId}', $this->getTask(...));
        $router->patch('/projects/{projectId}/tasks/{taskId}', $this->patchTask(...));
        $router->delete('/projects/{projectId}/tasks/{taskId}', $this->deleteTask(...));
    }

    // ── projects ──────────────────────────────────────────────────────────

    private function listProjects(ServerRequestInterface $request): ResponseInterface
    {
        [$limit, $offset] = $this->pagination($request);
        return $this->json->create([
            'items' => array_map($this->projectView(...), $this->projects->list($limit, $offset)),
            'total' => $this->projects->count(),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    private function createProject(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new ValidationException([new ValidationError('name', 'name must be a non-empty string', 'invalid_value')]);
        }
        $description = $body['description'] ?? '';
        if (!is_string($description)) {
            throw new ValidationException([new ValidationError('description', 'description must be a string', 'invalid_type')]);
        }

        $id = $this->projects->create(trim($name), $description, $this->now());
        $project = $this->projects->findById($id);
        return $this->json->create($this->projectView((array) $project), 201);
    }

    private function getProject(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->findById($this->intParam($request, 'id'));
        if ($project === null) {
            return $this->projectNotFound();
        }
        return $this->json->create($this->projectView($project));
    }

    private function deleteProject(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->projects->delete($this->intParam($request, 'id'))) {
            return $this->projectNotFound();
        }
        return $this->json->createEmpty(204);
    }

    // ── tasks (nested) ──────────────────────────────────────────────────────

    private function listTasks(ServerRequestInterface $request): ResponseInterface
    {
        $projectId = $this->intParam($request, 'projectId');
        if ($this->projects->findById($projectId) === null) {
            return $this->projectNotFound();
        }
        $status = $this->validatedStatusFilter($request);
        [$limit, $offset] = $this->pagination($request);

        return $this->json->create([
            'items' => array_map($this->taskView(...), $this->tasks->findByProject($projectId, $status, $limit, $offset)),
            'total' => $this->tasks->countByProject($projectId, $status),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    private function createTask(ServerRequestInterface $request): ResponseInterface
    {
        $projectId = $this->intParam($request, 'projectId');
        if ($this->projects->findById($projectId) === null) {
            return $this->projectNotFound();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $title = null;
        if (!array_key_exists('title', $body) || !is_string($body['title']) || trim($body['title']) === '') {
            $errors[] = new ValidationError('title', 'title must be a non-empty string', 'invalid_value');
        } else {
            $title = trim($body['title']);
        }

        $status = 'open';
        if (array_key_exists('status', $body)) {
            if (!is_string($body['status']) || !in_array($body['status'], self::STATUSES, true)) {
                $errors[] = $this->statusError();
            } else {
                $status = $body['status'];
            }
        }

        $priority = 0;
        if (array_key_exists('priority', $body)) {
            if (!is_int($body['priority'])) {
                $errors[] = new ValidationError('priority', 'priority must be an integer', 'invalid_type');
            } else {
                $priority = $body['priority'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id = $this->tasks->create($projectId, (string) $title, $status, $priority, $this->now());
        $task = $this->tasks->findByProjectAndId($projectId, $id);
        return $this->json->create($this->taskView((array) $task), 201);
    }

    private function getTask(ServerRequestInterface $request): ResponseInterface
    {
        $projectId = $this->intParam($request, 'projectId');
        if ($this->projects->findById($projectId) === null) {
            return $this->projectNotFound();
        }
        $task = $this->tasks->findByProjectAndId($projectId, $this->intParam($request, 'taskId'));
        if ($task === null) {
            return $this->taskNotFound();
        }
        return $this->json->create($this->taskView($task));
    }

    private function patchTask(ServerRequestInterface $request): ResponseInterface
    {
        $projectId = $this->intParam($request, 'projectId');
        if ($this->projects->findById($projectId) === null) {
            return $this->projectNotFound();
        }
        $taskId = $this->intParam($request, 'taskId');
        $existing = $this->tasks->findByProjectAndId($projectId, $taskId);
        if ($existing === null) {
            return $this->taskNotFound();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        // array_key_exists (not isset) so an explicit null is distinguishable;
        // null here means "field not provided → keep existing".
        $title = null;
        if (array_key_exists('title', $body)) {
            if (!is_string($body['title']) || trim($body['title']) === '') {
                $errors[] = new ValidationError('title', 'title must be a non-empty string', 'invalid_value');
            } else {
                $title = trim($body['title']);
            }
        }

        $status = null;
        if (array_key_exists('status', $body)) {
            if (!is_string($body['status']) || !in_array($body['status'], self::STATUSES, true)) {
                $errors[] = $this->statusError();
            } else {
                $status = $body['status'];
            }
        }

        $priority = null;
        if (array_key_exists('priority', $body)) {
            if (!is_int($body['priority'])) {
                $errors[] = new ValidationError('priority', 'priority must be an integer', 'invalid_type');
            } else {
                $priority = $body['priority'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->tasks->update($projectId, $taskId, $existing, $title, $status, $priority, $this->now());
        $task = $this->tasks->findByProjectAndId($projectId, $taskId);
        return $this->json->create($this->taskView((array) $task));
    }

    private function deleteTask(ServerRequestInterface $request): ResponseInterface
    {
        $projectId = $this->intParam($request, 'projectId');
        if ($this->projects->findById($projectId) === null) {
            return $this->projectNotFound();
        }
        $taskId = $this->intParam($request, 'taskId');
        if ($this->tasks->findByProjectAndId($projectId, $taskId) === null) {
            return $this->taskNotFound();
        }
        $this->tasks->delete($projectId, $taskId);
        return $this->json->createEmpty(204);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array{int, int} */
    private function pagination(ServerRequestInterface $request): array
    {
        $limit = QueryStringParser::int($request, 'limit', 20) ?? 20;
        $offset = QueryStringParser::int($request, 'offset', 0) ?? 0;
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        return [$limit, $offset];
    }

    private function validatedStatusFilter(ServerRequestInterface $request): ?string
    {
        $status = QueryStringParser::string($request, 'status');
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new ValidationException([$this->statusError()]);
        }
        return $status;
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function projectView(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'description' => (string) $p['description'],
            'created_at' => (string) $p['created_at'],
            'updated_at' => (string) $p['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private function taskView(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'project_id' => (int) $t['project_id'],
            'title' => (string) $t['title'],
            'status' => (string) $t['status'],
            'priority' => (int) $t['priority'],
            'created_at' => (string) $t['created_at'],
            'updated_at' => (string) $t['updated_at'],
        ];
    }

    private function statusError(): ValidationError
    {
        return new ValidationError('status', 'status must be one of: ' . implode(', ', self::STATUSES), 'invalid_value');
    }

    private function projectNotFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Project not found'], 404);
    }

    private function taskNotFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Task not found'], 404);
    }

    private function intParam(ServerRequestInterface $request, string $key): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params[$key] ?? 0);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
