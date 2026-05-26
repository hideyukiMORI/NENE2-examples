<?php

declare(strict_types=1);

namespace FeatureFlag\FeatureFlag;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private FeatureFlagRepository         $repo,
        private FlagEvaluator                 $evaluator,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/flags', $this->createFlag(...));
        $router->get('/flags/{name}', $this->getFlag(...));
        $router->post('/flags/{name}/toggle', $this->toggleFlag(...));
        $router->put('/flags/{name}/rollout', $this->setRollout(...));
        $router->put('/flags/{name}/targets', $this->upsertTarget(...));
        $router->delete('/flags/{name}/targets/{type}/{id}', $this->deleteTarget(...));
        $router->post('/flags/{name}/evaluate', $this->evaluateFlag(...));
    }

    private function createFlag(ServerRequestInterface $request): ResponseInterface
    {
        $body        = JsonRequestBodyParser::parse($request);
        $name        = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        $description = isset($body['description']) && is_string($body['description']) ? trim($body['description']) : '';

        if ($name === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'name', 'code' => 'required', 'message' => 'name is required.']],
            ]);
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $flag = $this->repo->create($name, $description, $now);

        if ($flag === null) {
            return $this->problems->create($request, 'conflict', 'Flag already exists.', 409, '');
        }

        return $this->json->create($flag->toArray(), 201);
    }

    private function getFlag(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $name   = (string) ($params['name'] ?? '');
        $flag   = $this->repo->findByName($name);

        if ($flag === null) {
            return $this->problems->create($request, 'not-found', 'Flag not found.', 404, '');
        }

        $targets = $this->repo->findTargetsByFlag($flag->id);

        return $this->json->create([
            'flag'    => $flag->toArray(),
            'targets' => array_map(fn (FlagTarget $t) => $t->toArray(), $targets),
        ]);
    }

    private function toggleFlag(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $name    = (string) ($params['name'] ?? '');
        $body    = JsonRequestBodyParser::parse($request);
        $enabled = isset($body['enabled']) && is_bool($body['enabled']) ? $body['enabled'] : null;

        if ($enabled === null) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'enabled', 'code' => 'required', 'message' => 'enabled (boolean) is required.']],
            ]);
        }

        $flag = $this->repo->setGloballyEnabled($name, $enabled);

        if ($flag === null) {
            return $this->problems->create($request, 'not-found', 'Flag not found.', 404, '');
        }

        return $this->json->create($flag->toArray());
    }

    private function setRollout(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $name   = (string) ($params['name'] ?? '');
        $body   = JsonRequestBodyParser::parse($request);
        $pct    = isset($body['rollout_pct']) && is_int($body['rollout_pct']) ? $body['rollout_pct'] : null;

        if ($pct === null || $pct < 0 || $pct > 100) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'rollout_pct', 'code' => 'invalid', 'message' => 'rollout_pct must be an integer 0-100.']],
            ]);
        }

        $flag = $this->repo->setRolloutPct($name, $pct);

        if ($flag === null) {
            return $this->problems->create($request, 'not-found', 'Flag not found.', 404, '');
        }

        return $this->json->create($flag->toArray());
    }

    private function upsertTarget(ServerRequestInterface $request): ResponseInterface
    {
        $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $name       = (string) ($params['name'] ?? '');
        $body       = JsonRequestBodyParser::parse($request);
        $targetType = isset($body['target_type']) && is_string($body['target_type']) ? $body['target_type'] : '';
        $targetId   = isset($body['target_id']) && is_string($body['target_id']) ? trim($body['target_id']) : '';
        $enabled    = isset($body['enabled']) && is_bool($body['enabled']) ? $body['enabled'] : null;

        if (!in_array($targetType, ['user', 'tenant'], true)) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'target_type', 'code' => 'invalid', 'message' => "target_type must be 'user' or 'tenant'."]],
            ]);
        }

        if ($targetId === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'target_id', 'code' => 'required', 'message' => 'target_id is required.']],
            ]);
        }

        if ($enabled === null) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'enabled', 'code' => 'required', 'message' => 'enabled (boolean) is required.']],
            ]);
        }

        $flag = $this->repo->findByName($name);

        if ($flag === null) {
            return $this->problems->create($request, 'not-found', 'Flag not found.', 404, '');
        }

        $target = $this->repo->upsertTarget($flag->id, $targetType, $targetId, $enabled);

        return $this->json->create($target->toArray());
    }

    private function deleteTarget(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $name   = (string) ($params['name'] ?? '');
        $type   = (string) ($params['type'] ?? '');
        $id     = (string) ($params['id'] ?? '');

        $flag = $this->repo->findByName($name);

        if ($flag === null) {
            return $this->problems->create($request, 'not-found', 'Flag not found.', 404, '');
        }

        $deleted = $this->repo->deleteTarget($flag->id, $type, $id);

        if (!$deleted) {
            return $this->problems->create($request, 'not-found', 'Target not found.', 404, '');
        }

        return $this->json->create([], 204);
    }

    private function evaluateFlag(ServerRequestInterface $request): ResponseInterface
    {
        $params   = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $name     = (string) ($params['name'] ?? '');
        $body     = JsonRequestBodyParser::parse($request);
        $userId   = isset($body['user_id']) && is_string($body['user_id']) ? trim($body['user_id']) : '';
        $tenantId = isset($body['tenant_id']) && is_string($body['tenant_id']) ? trim($body['tenant_id']) : null;

        if ($userId === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'user_id', 'code' => 'required', 'message' => 'user_id is required.']],
            ]);
        }

        $flag = $this->repo->findByName($name);

        if ($flag === null) {
            return $this->problems->create($request, 'not-found', 'Flag not found.', 404, '');
        }

        $targets = $this->repo->findTargetsByFlag($flag->id);
        $result  = $this->evaluator->evaluate($flag, $targets, $userId, $tenantId ?: null);

        return $this->json->create([
            'flag'     => $name,
            'user_id'  => $userId,
            'enabled'  => $result,
        ]);
    }
}
