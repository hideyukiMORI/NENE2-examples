<?php

declare(strict_types=1);

namespace Audit\Task;

use Audit\AuditLog\AuditRepository;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private TaskRepository                $tasks,
        private AuditRepository               $audit,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/tasks', $this->list(...));
        $router->post('/tasks', $this->create(...));
        $router->put('/tasks/{id}', $this->update(...));
        $router->delete('/tasks/{id}', $this->delete(...));
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401);
        }

        $tasks = $this->tasks->findByActor($actorId);

        return $this->json->create(['tasks' => array_map(fn (Task $t) => $t->toArray(), $tasks)]);
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401);
        }

        $body  = json_decode((string) $request->getBody(), true);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        $text  = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : '';

        if ($title === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
            ]);
        }

        $task = $this->tasks->create($title, $text, $actorId);

        // Audit: record creation — do not include actor_id in payload (redundant with audit record)
        $this->audit->record($actorId, 'created', 'task', $task->id, [
            'title'  => $task->title,
            'body'   => $task->body,
            'status' => $task->status,
        ]);

        return $this->json->create($task->toArray(), 201);
    }

    private function update(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401);
        }

        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);

        $before = $this->tasks->findById($id);
        if ($before === null) {
            return $this->problems->create($request, 'not-found', 'Task not found.', 404);
        }

        // 403 instead of 404: the resource exists but the actor doesn't own it.
        // We use 404 here to avoid confirming resource existence to unauthorized actors.
        if ($before->actorId !== $actorId) {
            return $this->problems->create($request, 'not-found', 'Task not found.', 404);
        }

        $body   = json_decode((string) $request->getBody(), true);
        $title  = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : $before->title;
        $text   = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : $before->body;
        $status = isset($body['status']) && is_string($body['status']) ? trim($body['status']) : $before->status;

        if ($title === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
            ]);
        }

        $after = $this->tasks->update($id, $title, $text, $status);
        if ($after === null) {
            return $this->problems->create($request, 'not-found', 'Task not found.', 404);
        }

        // Audit: record what changed — before/after snapshot for diff visibility
        $this->audit->record($actorId, 'updated', 'task', $id, [
            'before' => ['title' => $before->title, 'body' => $before->body, 'status' => $before->status],
            'after'  => ['title' => $after->title, 'body' => $after->body, 'status' => $after->status],
        ]);

        return $this->json->create($after->toArray());
    }

    private function delete(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401);
        }

        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);

        $task = $this->tasks->findById($id);
        if ($task === null) {
            return $this->problems->create($request, 'not-found', 'Task not found.', 404);
        }

        if ($task->actorId !== $actorId) {
            return $this->problems->create($request, 'not-found', 'Task not found.', 404);
        }

        $this->tasks->delete($id);

        // Audit: snapshot of deleted task — recorded AFTER deletion so resource_id remains valid
        $this->audit->record($actorId, 'deleted', 'task', $id, [
            'title'  => $task->title,
            'status' => $task->status,
        ]);

        return $this->json->createEmpty(204);
    }

    private function actorId(ServerRequestInterface $request): ?int
    {
        /** @var array<string, mixed>|null $claims */
        $claims = $request->getAttribute('nene2.auth.claims');

        if (!is_array($claims) || !isset($claims['sub']) || !is_int($claims['sub'])) {
            return null;
        }

        return $claims['sub'];
    }
}
