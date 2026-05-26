<?php

declare(strict_types=1);

namespace Audit\AuditLog;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private AuditRepository               $audit,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/audit', $this->list(...));
        $router->get('/audit/{resource_type}/{resource_id}', $this->byResource(...));
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $q            = $request->getQueryParams();
        $actorId      = isset($q['actor_id']) && is_numeric($q['actor_id']) ? (int) $q['actor_id'] : null;
        $action       = isset($q['action']) && is_string($q['action']) ? $q['action'] : null;
        $resourceType = isset($q['resource_type']) && is_string($q['resource_type']) ? $q['resource_type'] : null;
        $limit        = max(1, min((int) ($q['limit'] ?? 50), 100));
        $offset       = max(0, (int) ($q['offset'] ?? 0));

        $entries = $this->audit->search($actorId, $action, $resourceType, $limit, $offset);

        return $this->json->create([
            'entries' => array_map(fn (AuditEntry $e) => $e->toArray(), $entries),
            'limit'   => $limit,
            'offset'  => $offset,
        ]);
    }

    private function byResource(ServerRequestInterface $request): ResponseInterface
    {
        $params       = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $resourceType = isset($params['resource_type']) && is_string($params['resource_type']) ? $params['resource_type'] : '';
        $resourceId   = isset($params['resource_id']) ? (int) $params['resource_id'] : 0;

        if ($resourceType === '' || $resourceId <= 0) {
            return $this->problems->create($request, 'not-found', 'Not found.', 404);
        }

        $entries = $this->audit->findByResource($resourceType, $resourceId);

        return $this->json->create([
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'entries'       => array_map(fn (AuditEntry $e) => $e->toArray(), $entries),
        ]);
    }
}
