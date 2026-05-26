<?php

declare(strict_types=1);

namespace Export\Export;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private ExportRepository              $repo,
        private JsonResponseFactory           $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->get('/users/{id}', $this->getUser(...));
        $router->post('/users/{id}/export', $this->requestExport(...));
        $router->post('/exports/{token}/process', $this->processExport(...));
        $router->get('/exports/{token}', $this->downloadExport(...));
    }

    private function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $body     = JsonRequestBodyParser::parse($request);
        $email    = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $name     = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        $phone    = isset($body['phone']) && is_string($body['phone']) ? trim($body['phone']) : '';
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';

        if ($email === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'email', 'code' => 'required', 'message' => 'email is required.']],
            ]);
        }

        if ($name === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'name', 'code' => 'required', 'message' => 'name is required.']],
            ]);
        }

        if ($password === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'password', 'code' => 'required', 'message' => 'password is required.']],
            ]);
        }

        $now          = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
        $user         = $this->repo->createUser($email, $name, $phone, $passwordHash, $now);

        if ($user === null) {
            return $this->problems->create($request, 'conflict', 'Email already registered.', 409, '');
        }

        return $this->json->create($user->toPublicArray(), 201);
    }

    private function getUser(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $user   = $this->repo->findUserById($id);

        if ($user === null) {
            return $this->problems->create($request, 'not-found', 'User not found.', 404, '');
        }

        return $this->json->create($user->toPublicArray());
    }

    private function requestExport(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $user   = $this->repo->findUserById($id);

        if ($user === null) {
            return $this->problems->create($request, 'not-found', 'User not found.', 404, '');
        }

        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $expiresAt = (new \DateTimeImmutable())->modify('+24 hours')->format('Y-m-d H:i:s');
        $token     = bin2hex(random_bytes(32));
        $export    = $this->repo->createExport($user->id, $token, $expiresAt, $now);

        return $this->json->create($export->toArray(), 202);
    }

    private function processExport(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $token  = (string) ($params['token'] ?? '');
        $export = $this->repo->findExportByTokenOrNull($token);

        if ($export === null) {
            return $this->problems->create($request, 'not-found', 'Export not found.', 404, '');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($export->isExpired($now)) {
            return $this->problems->create($request, 'gone', 'Export request has expired. Please request a new export.', 410, '');
        }

        $user = $this->repo->findUserById($export->userId);

        if ($user === null) {
            return $this->problems->create($request, 'not-found', 'User not found.', 404, '');
        }

        $result = $this->repo->processExport($token, $user, [], $now);

        return $this->json->create($result->toArray());
    }

    private function downloadExport(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $token  = (string) ($params['token'] ?? '');
        $export = $this->repo->findExportByTokenOrNull($token);

        if ($export === null) {
            return $this->problems->create($request, 'not-found', 'Export not found.', 404, '');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($export->isExpired($now)) {
            return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
        }

        if ($export->status !== 'ready') {
            return $this->problems->create($request, 'conflict', 'Export is not yet ready.', 409, '');
        }

        $payload = json_decode((string) $export->payload, true, 512, JSON_THROW_ON_ERROR);

        return $this->json->create(['export' => $payload]);
    }
}
