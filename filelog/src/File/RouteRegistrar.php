<?php

declare(strict_types=1);

namespace FileLog\File;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const array VALID_VISIBILITY = ['private', 'public'];
    private const int MAX_NAME_LENGTH = 255;
    private const int MAX_MIME_LENGTH = 100;

    public function __construct(
        private readonly FileRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/files', $this->handleListFiles(...));
        $router->post('/files', $this->handleCreateFile(...));
        $router->get('/files/{fileId}', $this->handleGetFile(...));
        $router->put('/files/{fileId}', $this->handleUpdateFile(...));
        $router->delete('/files/{fileId}', $this->handleDeleteFile(...));
        $router->post('/files/{fileId}/shares', $this->handleAddShare(...));
        $router->delete('/files/{fileId}/shares/{userId}', $this->handleRemoveShare(...));
    }

    private function handleListFiles(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }
        if ($this->repo->findUserById($userId) === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        $files = $this->repo->listAccessibleFiles($userId);
        return $this->json->create([
            'files' => array_map($this->formatFile(...), $files),
            'count' => count($files),
        ]);
    }

    private function handleCreateFile(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }
        if ($this->repo->findUserById($userId) === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        [$name, $size, $mimeType, $description, $visibility, $errors] = $this->parseFileBody($body);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $id = $this->repo->create($userId, $name, $size, $mimeType, $description, $visibility, $now);
        $file = $this->repo->findFileById($id);
        assert($file !== null);

        return $this->json->create($this->formatFile($file), 201);
    }

    private function handleGetFile(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        $fileId = (int) $this->routeParam($request, 'fileId');
        $file = $this->repo->findFileById($fileId);
        if ($file === null) {
            return $this->json->create(['error' => 'File not found'], 404);
        }

        $ownerId = (int) $file['user_id'];
        if ($ownerId === $userId) {
            $shares = $this->repo->listShares($fileId);
            return $this->json->create($this->formatFileWithShares($file, $shares, true));
        }

        // Public files visible to anyone (authenticated)
        if ($file['visibility'] === 'public') {
            return $this->json->create($this->formatFile($file));
        }

        // Check share
        $share = $this->repo->findShare($fileId, $userId);
        if ($share === null) {
            return $this->json->create(['error' => 'File not found'], 404);
        }

        return $this->json->create($this->formatFile($file));
    }

    private function handleUpdateFile(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        $fileId = (int) $this->routeParam($request, 'fileId');
        $file = $this->repo->findFileById($fileId);
        if ($file === null) {
            return $this->json->create(['error' => 'File not found'], 404);
        }

        $ownerId = (int) $file['user_id'];
        if ($ownerId !== $userId) {
            // Check edit share
            $share = $this->repo->findShare($fileId, $userId);
            if ($share === null) {
                return $this->json->create(['error' => 'File not found'], 404);
            }
            if ((int) $share['can_edit'] !== 1) {
                return $this->json->create(['error' => 'Edit permission required'], 403);
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        [$name, $size, $mimeType, $description, $visibility, $errors] = $this->parseFileBody($body);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Only owner can change visibility
        if ($ownerId !== $userId) {
            $visibility = (string) $file['visibility'];
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $this->repo->update($fileId, $name, $size, $mimeType, $description, $visibility, $now);
        $updated = $this->repo->findFileById($fileId);
        assert($updated !== null);

        return $this->json->create($this->formatFile($updated));
    }

    private function handleDeleteFile(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        $fileId = (int) $this->routeParam($request, 'fileId');
        $file = $this->repo->findFileById($fileId);
        if ($file === null) {
            return $this->json->create(['error' => 'File not found'], 404);
        }

        if ((int) $file['user_id'] !== $userId) {
            return $this->json->create(['error' => 'File not found'], 404);
        }

        $this->repo->delete($fileId);
        return $this->json->createEmpty(204);
    }

    private function handleAddShare(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        $fileId = (int) $this->routeParam($request, 'fileId');
        $file = $this->repo->findFileById($fileId);
        if ($file === null) {
            return $this->json->create(['error' => 'File not found'], 404);
        }

        if ((int) $file['user_id'] !== $userId) {
            return $this->json->create(['error' => 'File not found'], 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        if (!isset($body['user_id']) || !is_int($body['user_id'])) {
            throw new ValidationException([
                new ValidationError('user_id', 'user_id must be an integer', 'invalid_type'),
            ]);
        }

        $targetUserId = $body['user_id'];
        if ($targetUserId <= 0) {
            throw new ValidationException([
                new ValidationError('user_id', 'user_id must be positive', 'invalid_value'),
            ]);
        }

        if ($targetUserId === $userId) {
            return $this->json->create(['error' => 'Cannot share with yourself'], 422);
        }

        if ($this->repo->findUserById($targetUserId) === null) {
            return $this->json->create(['error' => 'Target user not found'], 404);
        }

        $canEdit = isset($body['can_edit']) && $body['can_edit'] === true;

        $existing = $this->repo->findShare($fileId, $targetUserId);
        if ($existing !== null) {
            return $this->json->create(['error' => 'Already shared with this user'], 409);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $this->repo->addShare($fileId, $targetUserId, $canEdit, $now);

        $share = $this->repo->findShare($fileId, $targetUserId);
        assert($share !== null);

        return $this->json->create([
            'file_id' => $fileId,
            'shared_with_user_id' => (int) $share['shared_with_user_id'],
            'can_edit' => (bool) $share['can_edit'],
        ], 201);
    }

    private function handleRemoveShare(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        $fileId = (int) $this->routeParam($request, 'fileId');
        $file = $this->repo->findFileById($fileId);
        if ($file === null) {
            return $this->json->create(['error' => 'File not found'], 404);
        }

        if ((int) $file['user_id'] !== $userId) {
            return $this->json->create(['error' => 'File not found'], 404);
        }

        $targetUserId = (int) $this->routeParam($request, 'userId');
        $existing = $this->repo->findShare($fileId, $targetUserId);
        if ($existing === null) {
            return $this->json->create(['error' => 'Share not found'], 404);
        }

        $this->repo->removeShare($fileId, $targetUserId);
        return $this->json->createEmpty(204);
    }

    private function requireUserId(ServerRequestInterface $request): ?int
    {
        $header = $request->getHeaderLine('X-User-Id');
        if ($header === '') {
            return null;
        }
        $id = (int) $header;
        return $id > 0 ? $id : null;
    }

    private function routeParam(ServerRequestInterface $request, string $key): string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (string) ($params[$key] ?? '');
    }

    /**
     * @param array<string, mixed> $body
     * @return array{string, int, string, string|null, string, list<ValidationError>}
     */
    private function parseFileBody(array $body): array
    {
        $errors = [];

        $name = '';
        if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
            $errors[] = new ValidationError('name', 'name is required', 'required');
        } elseif (mb_strlen($body['name']) > self::MAX_NAME_LENGTH) {
            $errors[] = new ValidationError('name', 'name is too long', 'too_long');
        } else {
            $name = trim($body['name']);
        }

        $size = 0;
        if (!isset($body['size']) || !is_int($body['size'])) {
            $errors[] = new ValidationError('size', 'size must be an integer', 'invalid_type');
        } elseif ($body['size'] < 0) {
            $errors[] = new ValidationError('size', 'size must be non-negative', 'invalid_value');
        } else {
            $size = $body['size'];
        }

        $mimeType = '';
        if (!isset($body['mime_type']) || !is_string($body['mime_type']) || trim($body['mime_type']) === '') {
            $errors[] = new ValidationError('mime_type', 'mime_type is required', 'required');
        } elseif (mb_strlen($body['mime_type']) > self::MAX_MIME_LENGTH) {
            $errors[] = new ValidationError('mime_type', 'mime_type is too long', 'too_long');
        } else {
            $mimeType = trim($body['mime_type']);
        }

        $description = null;
        if (isset($body['description'])) {
            if (!is_string($body['description'])) {
                $errors[] = new ValidationError('description', 'description must be a string', 'invalid_type');
            } else {
                $description = $body['description'];
            }
        }

        $visibility = 'private';
        if (isset($body['visibility'])) {
            if (!is_string($body['visibility']) || !in_array($body['visibility'], self::VALID_VISIBILITY, true)) {
                $errors[] = new ValidationError(
                    'visibility',
                    'visibility must be private or public',
                    'invalid_value'
                );
            } else {
                $visibility = $body['visibility'];
            }
        }

        return [$name, $size, $mimeType, $description, $visibility, $errors];
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function formatFile(array $file): array
    {
        return [
            'id' => (int) $file['id'],
            'user_id' => (int) $file['user_id'],
            'owner_name' => (string) $file['owner_name'],
            'name' => (string) $file['name'],
            'size' => (int) $file['size'],
            'mime_type' => (string) $file['mime_type'],
            'description' => isset($file['description']) ? (string) $file['description'] : null,
            'visibility' => (string) $file['visibility'],
            'created_at' => (string) $file['created_at'],
            'updated_at' => (string) $file['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $file
     * @param list<array<string, mixed>> $shares
     * @return array<string, mixed>
     */
    private function formatFileWithShares(array $file, array $shares, bool $isOwner): array
    {
        $formatted = $this->formatFile($file);
        $formatted['is_owner'] = $isOwner;
        $formatted['shares'] = array_map(
            static fn (array $s): array => [
                'user_id' => (int) $s['shared_with_user_id'],
                'user_name' => (string) $s['user_name'],
                'can_edit' => (bool) $s['can_edit'],
                'created_at' => (string) $s['created_at'],
            ],
            $shares
        );
        return $formatted;
    }
}
