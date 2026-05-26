<?php

declare(strict_types=1);

namespace Signed\SignedUrl;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private FileRepository               $files,
        private HmacSigner                  $signer,
        private JsonResponseFactory          $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/files', $this->createFile(...));
        $router->post('/files/{id}/sign', $this->signUrl(...));
        $router->get('/download', $this->download(...));
    }

    private function createFile(ServerRequestInterface $request): ResponseInterface
    {
        $body      = JsonRequestBodyParser::parse($request);
        $name      = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        $mimeType  = isset($body['mime_type']) && is_string($body['mime_type']) ? $body['mime_type'] : 'application/octet-stream';
        $sizeBytes = isset($body['size_bytes']) && is_int($body['size_bytes']) ? $body['size_bytes'] : 0;
        $ownerId   = isset($body['owner_id']) && is_int($body['owner_id']) ? $body['owner_id'] : 0;

        if ($name === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'name', 'code' => 'required', 'message' => 'File name is required.']],
            ]);
        }

        if ($ownerId <= 0) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'owner_id', 'code' => 'required', 'message' => 'owner_id must be a positive integer.']],
            ]);
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $file = $this->files->create($name, $mimeType, $sizeBytes, $ownerId, $now);

        return $this->json->create($file->toArray(), 201);
    }

    private function signUrl(ServerRequestInterface $request): ResponseInterface
    {
        $routeParams = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id          = (int) ($routeParams['id'] ?? 0);
        $body        = JsonRequestBodyParser::parse($request);

        $file = $this->files->findById($id);
        if ($file === null) {
            return $this->problems->create($request, 'not-found', 'File not found.', 404, '');
        }

        $ttlSeconds = isset($body['ttl_seconds']) && is_int($body['ttl_seconds']) && $body['ttl_seconds'] > 0
            ? $body['ttl_seconds']
            : 3600;

        $expiresAt = (new \DateTimeImmutable())
            ->add(new \DateInterval("PT{$ttlSeconds}S"))
            ->format('Y-m-d H:i:s');

        $token = $this->signer->sign($file->id, $expiresAt);

        return $this->json->create([
            'token'      => $token,
            'expires_at' => $expiresAt,
            'ttl_seconds' => $ttlSeconds,
            'url'        => '/download?token=' . urlencode($token),
        ]);
    }

    private function download(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $token  = isset($params['token']) && is_string($params['token']) ? $params['token'] : '';

        if ($token === '') {
            return $this->problems->create($request, 'unauthorized', 'Missing token parameter.', 401, '');
        }

        $now        = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $resourceId = $this->signer->verify($token, $now);

        if ($resourceId === null) {
            // Check if the token is structurally valid but expired → 410 Gone
            $expiresAt = $this->signer->extractExpiresAt($token);
            if ($expiresAt !== null && $expiresAt < $now) {
                return $this->problems->create($request, 'gone', 'This link has expired.', 410, '');
            }

            return $this->problems->create($request, 'unauthorized', 'Invalid or expired token.', 401, '');
        }

        $file = $this->files->findById($resourceId);
        if ($file === null) {
            return $this->problems->create($request, 'not-found', 'File not found.', 404, '');
        }

        return $this->json->create($file->toArray());
    }
}
