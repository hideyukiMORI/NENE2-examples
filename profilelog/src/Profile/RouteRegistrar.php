<?php

declare(strict_types=1);

namespace Profile\Profile;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly ProfileRepository $repo,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/users/{userId}/profile', $this->createProfile(...));
        $router->get('/users/{userId}/profile', $this->getProfile(...));
        $router->put('/users/{userId}/profile', $this->updateProfile(...));
    }

    private function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->responseFactory->create(['error' => 'valid email is required'], 422);
        }

        $now = date('Y-m-d H:i:s');

        try {
            $userId = $this->repo->createUser($email, $now);
        } catch (DatabaseConstraintException) {
            return $this->responseFactory->create(['error' => 'email already registered'], 409);
        }

        return $this->responseFactory->create(['id' => $userId, 'email' => $email], 201);
    }

    private function createProfile(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        if ($this->repo->findByUserId($userId) !== null) {
            return $this->responseFactory->create(['error' => 'profile already exists'], 409);
        }

        $body = JsonRequestBodyParser::parse($request);
        [$displayName, $bio, $avatarUrl, $err] = $this->extractProfileFields($body);

        if ($err !== null) {
            return $this->responseFactory->create(['error' => $err], 422);
        }

        $now     = date('Y-m-d H:i:s');
        $profile = $this->repo->createProfile($userId, $displayName, $bio, $avatarUrl, $now);

        return $this->responseFactory->create($profile->toArray(), 201);
    }

    private function getProfile(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $profile = $this->repo->findByUserId($userId);

        if ($profile === null) {
            return $this->responseFactory->create(['error' => 'profile not found'], 404);
        }

        return $this->responseFactory->create($profile->toArray());
    }

    private function updateProfile(ServerRequestInterface $request): ResponseInterface
    {
        $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $userId    = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;
        $actorId   = $this->resolveActorId($request);

        if ($userId <= 0 || !$this->repo->findUserById($userId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        // ownership check: X-User-Id header identifies the requesting user
        if ($actorId !== $userId) {
            return $this->responseFactory->create(['error' => 'forbidden'], 403);
        }

        if ($this->repo->findByUserId($userId) === null) {
            return $this->responseFactory->create(['error' => 'profile not found'], 404);
        }

        $body = JsonRequestBodyParser::parse($request);
        [$displayName, $bio, $avatarUrl, $err] = $this->extractProfileFields($body);

        if ($err !== null) {
            return $this->responseFactory->create(['error' => $err], 422);
        }

        $now     = date('Y-m-d H:i:s');
        $profile = $this->repo->updateProfile($userId, $displayName, $bio, $avatarUrl, $now);

        return $this->responseFactory->create($profile !== null ? $profile->toArray() : [], 200);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{string, string, string, ?string}
     */
    private function extractProfileFields(array $body): array
    {
        $displayName = isset($body['display_name']) && is_string($body['display_name'])
            ? trim($body['display_name']) : '';
        $bio = isset($body['bio']) && is_string($body['bio'])
            ? $body['bio'] : '';
        $avatarUrl = isset($body['avatar_url']) && is_string($body['avatar_url'])
            ? trim($body['avatar_url']) : '';

        if (mb_strlen($displayName) > UserProfile::MAX_DISPLAY_NAME_LENGTH) {
            return [$displayName, $bio, $avatarUrl, 'display_name must not exceed ' . UserProfile::MAX_DISPLAY_NAME_LENGTH . ' characters'];
        }

        if (mb_strlen($bio) > UserProfile::MAX_BIO_LENGTH) {
            return [$displayName, $bio, $avatarUrl, 'bio must not exceed ' . UserProfile::MAX_BIO_LENGTH . ' characters'];
        }

        if ($avatarUrl !== '' && !$this->isValidAvatarUrl($avatarUrl)) {
            return [$displayName, $bio, $avatarUrl, 'avatar_url must be a valid https URL'];
        }

        return [$displayName, $bio, $avatarUrl, null];
    }

    private function resolveActorId(ServerRequestInterface $request): int
    {
        $header = $request->getHeaderLine('X-User-Id');

        return is_numeric($header) ? (int) $header : 0;
    }

    private function isValidAvatarUrl(string $url): bool
    {
        if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
            return false;
        }

        // Only allow https to prevent javascript: and data: URI schemes
        if (!str_starts_with($url, 'https://')) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
