<?php

declare(strict_types=1);

namespace StepFlow\Flow;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly WorkflowRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/workflows', $this->handleCreateWorkflow(...));
        $router->get('/workflows/{id}', $this->handleGetWorkflow(...));
        $router->post('/workflows/{id}/steps', $this->handleAddStep(...));
        $router->post('/runs', $this->handleCreateRun(...));
        $router->get('/runs/{id}', $this->handleGetRun(...));
        $router->post('/runs/{id}/approve', $this->handleApprove(...));
        $router->post('/runs/{id}/reject', $this->handleReject(...));
    }

    private function handleCreateWorkflow(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            throw new ValidationException([new ValidationError('name', 'name is required', 'required')]);
        }
        $description = isset($body['description']) && is_string($body['description'])
            ? trim($body['description']) : '';

        $now = $this->now();
        $id  = $this->repo->createWorkflow($name, $description, $now);
        $wf  = $this->repo->findWorkflow($id);
        assert($wf !== null);
        return $this->json->create(array_merge($wf, ['steps' => []]), 201);
    }

    private function handleGetWorkflow(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->id($request);
        $wf = $this->repo->findWorkflow($id);
        if ($wf === null) {
            return $this->json->create(['error' => 'Workflow not found'], 404);
        }
        $steps = $this->repo->findSteps($id);
        return $this->json->create(array_merge($wf, ['steps' => $steps]));
    }

    private function handleAddStep(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->id($request);
        $wf = $this->repo->findWorkflow($id);
        if ($wf === null) {
            return $this->json->create(['error' => 'Workflow not found'], 404);
        }

        $body   = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            $errors[] = new ValidationError('name', 'name is required', 'required');
        }

        $existingSteps = $this->repo->findSteps($id);
        $maxOrder      = 0;
        foreach ($existingSteps as $s) {
            if ((int) $s['step_order'] > $maxOrder) {
                $maxOrder = (int) $s['step_order'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $stepOrder = $maxOrder + 1;
        $stepId    = $this->repo->addStep($id, $name, $stepOrder);
        $step      = $this->repo->findStep($stepId);
        assert($step !== null);
        return $this->json->create($step, 201);
    }

    private function handleCreateRun(ServerRequestInterface $request): ResponseInterface
    {
        $body   = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        if ($title === '') {
            $errors[] = new ValidationError('title', 'title is required', 'required');
        }

        $workflowId = 0;
        if (!isset($body['workflow_id']) || !is_numeric($body['workflow_id'])) {
            $errors[] = new ValidationError('workflow_id', 'workflow_id is required', 'required');
        } else {
            $workflowId = (int) $body['workflow_id'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $wf = $this->repo->findWorkflow($workflowId);
        if ($wf === null) {
            return $this->json->create(['error' => 'Workflow not found'], 404);
        }

        $steps = $this->repo->findSteps($workflowId);
        if ($steps === []) {
            return $this->json->create(['error' => 'Workflow has no steps'], 409);
        }

        $firstStep = $steps[0];
        $now       = $this->now();
        $runId     = $this->repo->createRun($workflowId, $title, (int) $firstStep['id'], $now);
        $run       = $this->repo->findRun($runId);
        assert($run !== null);
        return $this->json->create($run, 201);
    }

    private function handleGetRun(ServerRequestInterface $request): ResponseInterface
    {
        $id  = $this->id($request);
        $run = $this->repo->findRun($id);
        if ($run === null) {
            return $this->json->create(['error' => 'Run not found'], 404);
        }
        $actions = $this->repo->findActions($id);
        return $this->json->create(array_merge($run, ['history' => $actions]));
    }

    private function handleApprove(ServerRequestInterface $request): ResponseInterface
    {
        $id  = $this->id($request);
        $run = $this->repo->findRun($id);
        if ($run === null) {
            return $this->json->create(['error' => 'Run not found'], 404);
        }

        if ((string) $run['status'] !== 'in_progress') {
            return $this->json->create(['error' => 'Run is not in progress'], 409);
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $actor   = isset($body['actor']) && is_string($body['actor']) ? trim($body['actor']) : '';
        if ($actor === '') {
            throw new ValidationException([new ValidationError('actor', 'actor is required', 'required')]);
        }
        $comment = isset($body['comment']) && is_string($body['comment']) ? trim($body['comment']) : '';

        $currentStepId    = (int) $run['current_step_id'];
        $currentStepOrder = (int) $run['current_step_order'];
        $workflowId       = (int) $run['workflow_id'];

        $this->repo->recordAction($id, $currentStepId, 'approve', $actor, $comment, $this->now());

        $nextStep = $this->repo->findNextStep($workflowId, $currentStepOrder);
        if ($nextStep !== null) {
            $this->repo->updateRun($id, 'in_progress', (int) $nextStep['id'], $this->now());
        } else {
            $this->repo->updateRun($id, 'completed', null, $this->now());
        }

        $updated = $this->repo->findRun($id);
        assert($updated !== null);
        $actions = $this->repo->findActions($id);
        return $this->json->create(array_merge($updated, ['history' => $actions]));
    }

    private function handleReject(ServerRequestInterface $request): ResponseInterface
    {
        $id  = $this->id($request);
        $run = $this->repo->findRun($id);
        if ($run === null) {
            return $this->json->create(['error' => 'Run not found'], 404);
        }

        if ((string) $run['status'] !== 'in_progress') {
            return $this->json->create(['error' => 'Run is not in progress'], 409);
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $actor   = isset($body['actor']) && is_string($body['actor']) ? trim($body['actor']) : '';
        if ($actor === '') {
            throw new ValidationException([new ValidationError('actor', 'actor is required', 'required')]);
        }
        $comment = isset($body['comment']) && is_string($body['comment']) ? trim($body['comment']) : '';

        $currentStepId = (int) $run['current_step_id'];
        $this->repo->recordAction($id, $currentStepId, 'reject', $actor, $comment, $this->now());
        $this->repo->updateRun($id, 'rejected', null, $this->now());

        $updated = $this->repo->findRun($id);
        assert($updated !== null);
        $actions = $this->repo->findActions($id);
        return $this->json->create(array_merge($updated, ['history' => $actions]));
    }

    private function id(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['id'] ?? 0);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
