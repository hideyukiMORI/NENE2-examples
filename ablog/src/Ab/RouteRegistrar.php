<?php

declare(strict_types=1);

namespace AbLog\Ab;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const array VALID_TRANSITIONS = [
        'draft'   => ['active'],
        'active'  => ['stopped'],
        'stopped' => [],
    ];

    public function __construct(
        private readonly ExperimentRepository $repo,
        private readonly VariantAssigner $assigner,
        private readonly JsonResponseFactory $json,
    ) {}

    public function register(Router $router): void
    {
        $router->get('/experiments', $this->handleList(...));
        $router->post('/experiments', $this->handleCreate(...));
        $router->get('/experiments/{id}', $this->handleGet(...));
        $router->put('/experiments/{id}/status', $this->handleStatus(...));
        $router->post('/experiments/{id}/variants', $this->handleAddVariant(...));
        $router->post('/experiments/{id}/assign', $this->handleAssign(...));
        $router->post('/experiments/{id}/events', $this->handleEvent(...));
        $router->get('/experiments/{id}/results', $this->handleResults(...));
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $experiments = $this->repo->findAll();
        return $this->json->create(['experiments' => $experiments, 'count' => count($experiments)]);
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            throw new ValidationException([new ValidationError('name', 'name is required', 'required')]);
        }

        $description = isset($body['description']) && is_string($body['description'])
            ? trim($body['description']) : '';

        $now = $this->now();
        $id  = $this->repo->create($name, $description, $now);
        $exp = $this->repo->find($id);
        assert($exp !== null);
        return $this->json->create($exp, 201);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $id  = $this->id($request);
        $exp = $this->repo->find($id);
        if ($exp === null) {
            return $this->json->create(['error' => 'Experiment not found'], 404);
        }
        $variants = $this->repo->findVariants($id);
        return $this->json->create(array_merge($exp, ['variants' => $variants]));
    }

    private function handleStatus(ServerRequestInterface $request): ResponseInterface
    {
        $id   = $this->id($request);
        $exp  = $this->repo->find($id);
        if ($exp === null) {
            return $this->json->create(['error' => 'Experiment not found'], 404);
        }

        $body   = (array) ($request->getParsedBody() ?? []);
        $status = isset($body['status']) && is_string($body['status']) ? $body['status'] : '';

        $current   = (string) $exp['status'];
        $allowed   = self::VALID_TRANSITIONS[$current] ?? [];
        if (!in_array($status, $allowed, true)) {
            throw new ValidationException([new ValidationError(
                'status',
                "Cannot transition from {$current} to {$status}",
                'invalid',
            )]);
        }

        $this->repo->updateStatus($id, $status, $this->now());
        $updated = $this->repo->find($id);
        assert($updated !== null);
        return $this->json->create($updated);
    }

    private function handleAddVariant(ServerRequestInterface $request): ResponseInterface
    {
        $id  = $this->id($request);
        $exp = $this->repo->find($id);
        if ($exp === null) {
            return $this->json->create(['error' => 'Experiment not found'], 404);
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $errors  = [];

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            $errors[] = new ValidationError('name', 'name is required', 'required');
        }

        $weight = 100;
        if (isset($body['weight'])) {
            if (!is_numeric($body['weight']) || (int) $body['weight'] <= 0) {
                $errors[] = new ValidationError('weight', 'weight must be a positive integer', 'invalid');
            } else {
                $weight = (int) $body['weight'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $variantId = $this->repo->addVariant($id, $name, $weight);
        $variants  = $this->repo->findVariants($id);
        foreach ($variants as $v) {
            if ((int) $v['id'] === $variantId) {
                return $this->json->create($v, 201);
            }
        }

        // Should not happen
        return $this->json->create(['id' => $variantId], 201);
    }

    private function handleAssign(ServerRequestInterface $request): ResponseInterface
    {
        $id  = $this->id($request);
        $exp = $this->repo->find($id);
        if ($exp === null) {
            return $this->json->create(['error' => 'Experiment not found'], 404);
        }

        if ((string) $exp['status'] !== 'active') {
            return $this->json->create(['error' => 'Experiment is not active'], 409);
        }

        $body   = (array) ($request->getParsedBody() ?? []);
        $userId = isset($body['user_id']) && is_string($body['user_id']) ? trim($body['user_id']) : '';
        if ($userId === '') {
            throw new ValidationException([new ValidationError('user_id', 'user_id is required', 'required')]);
        }

        // Idempotent: return existing assignment
        $existing = $this->repo->findAssignment($id, $userId);
        if ($existing !== null) {
            return $this->json->create($existing);
        }

        $variants = $this->repo->findVariants($id);
        if ($variants === []) {
            return $this->json->create(['error' => 'Experiment has no variants'], 409);
        }

        $variant = $this->assigner->assign($variants, $userId, $id);
        if ($variant === null) {
            return $this->json->create(['error' => 'Variant assignment failed'], 500);
        }

        $assignmentId = $this->repo->createAssignment($id, $userId, (int) $variant['id'], $this->now());
        $assignment   = $this->repo->findAssignment($id, $userId);
        assert($assignment !== null);
        return $this->json->create($assignment, 201);
    }

    private function handleEvent(ServerRequestInterface $request): ResponseInterface
    {
        $id  = $this->id($request);
        $exp = $this->repo->find($id);
        if ($exp === null) {
            return $this->json->create(['error' => 'Experiment not found'], 404);
        }

        $body   = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $userId = isset($body['user_id']) && is_string($body['user_id']) ? trim($body['user_id']) : '';
        if ($userId === '') {
            $errors[] = new ValidationError('user_id', 'user_id is required', 'required');
        }

        $eventType = isset($body['event_type']) && is_string($body['event_type'])
            ? trim($body['event_type']) : '';
        if ($eventType === '') {
            $errors[] = new ValidationError('event_type', 'event_type is required', 'required');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $assignment = $this->repo->findAssignment($id, $userId);
        if ($assignment === null) {
            return $this->json->create(['error' => 'User is not assigned to this experiment'], 404);
        }

        $eventId = $this->repo->recordEvent($id, (int) $assignment['id'], $eventType, $this->now());
        return $this->json->create(['id' => $eventId, 'experiment_id' => $id,
            'user_id' => $userId, 'event_type' => $eventType,
            'variant_name' => $assignment['variant_name']], 201);
    }

    private function handleResults(ServerRequestInterface $request): ResponseInterface
    {
        $id  = $this->id($request);
        $exp = $this->repo->find($id);
        if ($exp === null) {
            return $this->json->create(['error' => 'Experiment not found'], 404);
        }

        $results = $this->repo->getResults($id);
        foreach ($results as &$row) {
            $assignments = (int) $row['assignments'];
            $events      = (int) $row['events'];
            $row['cvr']  = $assignments > 0 ? round($events / $assignments, 4) : 0.0;
        }
        unset($row);

        return $this->json->create([
            'experiment' => $exp,
            'variants'   => $results,
        ]);
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
