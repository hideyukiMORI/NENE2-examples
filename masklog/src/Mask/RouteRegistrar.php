<?php

declare(strict_types=1);

namespace MaskLog\Mask;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly CustomerRepository $repo,
        private readonly MaskService $masker,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/customers', $this->handleCreate(...));
        $router->get('/customers/{id}', $this->handleGet(...));
        $router->get('/customers/{id}/audit', $this->handleAudit(...));
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $body   = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            $errors[] = new ValidationError('name', 'name is required', 'required');
        }

        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        if ($email === '') {
            $errors[] = new ValidationError('email', 'email is required', 'required');
        }

        $phone = isset($body['phone']) && is_string($body['phone']) ? trim($body['phone']) : '';
        if ($phone === '') {
            $errors[] = new ValidationError('phone', 'phone is required', 'required');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $customer = $this->repo->create($name, $email, $phone, $this->now());
        return $this->json->create($this->masker->applyMask($customer), 201);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $id       = $this->id($request);
        $customer = $this->repo->find($id);
        if ($customer === null) {
            return $this->json->create(['error' => 'Customer not found'], 404);
        }

        $role     = $request->getHeaderLine('X-Role');
        $accessor = trim($request->getHeaderLine('X-Accessor'));

        if ($role === 'admin') {
            if ($accessor === '') {
                return $this->json->create(['error' => 'X-Accessor header required for admin access'], 403);
            }
            $this->repo->logAccess((int) $customer['id'], $accessor, $this->now());
            return $this->json->create($customer);
        }

        return $this->json->create($this->masker->applyMask($customer));
    }

    private function handleAudit(ServerRequestInterface $request): ResponseInterface
    {
        $id       = $this->id($request);
        $customer = $this->repo->find($id);
        if ($customer === null) {
            return $this->json->create(['error' => 'Customer not found'], 404);
        }

        $role = $request->getHeaderLine('X-Role');
        if ($role !== 'admin') {
            return $this->json->create(['error' => 'admin role required'], 403);
        }

        $log = $this->repo->getAuditLog($id);
        return $this->json->create(['customer_id' => $id, 'count' => count($log), 'entries' => $log]);
    }

    private function id(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['id'] ?? 0);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
