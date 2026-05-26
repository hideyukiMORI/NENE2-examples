<?php

declare(strict_types=1);

namespace ReviewLog\Review;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int DEFAULT_LIMIT = 20;
    private const int MAX_LIMIT = 50;

    public function __construct(
        private readonly ReviewRepository $repository,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/products/{productId}/reviews', $this->handleCreate(...));
        $router->get('/products/{productId}/reviews', $this->handleList(...));
        $router->get('/products/{productId}/reviews/summary', $this->handleSummary(...));
        $router->put('/products/{productId}/reviews/{reviewId}', $this->handleUpdate(...));
        $router->delete('/products/{productId}/reviews/{reviewId}', $this->handleDelete(...));
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (int) ($request->getHeaderLine('X-User-Id') ?: 0);
        if ($userId === 0) {
            return $this->json->create(['error' => 'authentication required'], 401);
        }

        $productId = (int) $this->routeParam($request, 'productId');
        if ($this->repository->findProductById($productId) === null) {
            return $this->json->create(['error' => 'product not found'], 404);
        }

        if ($this->repository->findByProductAndUser($productId, $userId) !== null) {
            return $this->json->create(['error' => 'already reviewed'], 409);
        }

        $body = (array) (json_decode((string) $request->getBody(), true) ?? []);
        [$rating, $reviewBody, $errors] = $this->parseBody($body);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now = date('Y-m-d H:i:s');
        $id = $this->repository->create($productId, $userId, $rating, $reviewBody, $now);

        $review = $this->repository->findReviewById($id);
        /** @var array<string, mixed> $review */
        return $this->json->create($this->format($review), 201);
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (int) ($request->getHeaderLine('X-User-Id') ?: 0);
        if ($userId === 0) {
            return $this->json->create(['error' => 'authentication required'], 401);
        }

        $productId = (int) $this->routeParam($request, 'productId');
        if ($this->repository->findProductById($productId) === null) {
            return $this->json->create(['error' => 'product not found'], 404);
        }

        $query = $request->getQueryParams();
        $limit = min((int) ($query['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $beforeId = isset($query['before_id']) && $query['before_id'] !== '' ? (int) $query['before_id'] : null;

        $items = $this->repository->listByProduct($productId, $limit, $beforeId);
        $nextCursor = count($items) === $limit && $items !== [] ? (int) end($items)['id'] : null;

        return $this->json->create([
            'items' => array_map($this->format(...), $items),
            'next_cursor' => $nextCursor,
        ]);
    }

    private function handleSummary(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (int) ($request->getHeaderLine('X-User-Id') ?: 0);
        if ($userId === 0) {
            return $this->json->create(['error' => 'authentication required'], 401);
        }

        $productId = (int) $this->routeParam($request, 'productId');
        if ($this->repository->findProductById($productId) === null) {
            return $this->json->create(['error' => 'product not found'], 404);
        }

        return $this->json->create($this->repository->getSummary($productId));
    }

    private function handleUpdate(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (int) ($request->getHeaderLine('X-User-Id') ?: 0);
        if ($userId === 0) {
            return $this->json->create(['error' => 'authentication required'], 401);
        }

        $productId = (int) $this->routeParam($request, 'productId');
        $reviewId = (int) $this->routeParam($request, 'reviewId');

        $review = $this->repository->findReviewById($reviewId);
        if ($review === null || (int) $review['product_id'] !== $productId) {
            return $this->json->create(['error' => 'review not found'], 404);
        }
        if ((int) $review['user_id'] !== $userId) {
            return $this->json->create(['error' => 'forbidden'], 403);
        }

        $body = (array) (json_decode((string) $request->getBody(), true) ?? []);
        [$rating, $reviewBody, $errors] = $this->parseBody($body);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now = date('Y-m-d H:i:s');
        $this->repository->update($reviewId, $rating, $reviewBody, $now);

        $updated = $this->repository->findReviewById($reviewId);
        /** @var array<string, mixed> $updated */
        return $this->json->create($this->format($updated));
    }

    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (int) ($request->getHeaderLine('X-User-Id') ?: 0);
        if ($userId === 0) {
            return $this->json->create(['error' => 'authentication required'], 401);
        }

        $productId = (int) $this->routeParam($request, 'productId');
        $reviewId = (int) $this->routeParam($request, 'reviewId');

        $review = $this->repository->findReviewById($reviewId);
        if ($review === null || (int) $review['product_id'] !== $productId) {
            return $this->json->create(['error' => 'review not found'], 404);
        }
        if ((int) $review['user_id'] !== $userId) {
            return $this->json->create(['error' => 'forbidden'], 403);
        }

        $this->repository->delete($reviewId);

        return $this->json->createEmpty(204);
    }

    private function routeParam(ServerRequestInterface $request, string $key): string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (string) ($params[$key] ?? '');
    }

    /**
     * @param array<string, mixed> $body
     * @return array{int, string|null, list<ValidationError>}
     */
    private function parseBody(array $body): array
    {
        $errors = [];
        $rating = 0;
        $reviewBody = null;

        if (!isset($body['rating']) || !is_int($body['rating'])) {
            $errors[] = new ValidationError('rating', 'Rating must be an integer.', 'required');
        } elseif ($body['rating'] < 1 || $body['rating'] > 5) {
            $errors[] = new ValidationError('rating', 'Rating must be between 1 and 5.', 'out_of_range');
        } else {
            $rating = $body['rating'];
        }

        if (isset($body['body'])) {
            if (!is_string($body['body'])) {
                $errors[] = new ValidationError('body', 'Body must be a string.', 'invalid_type');
            } else {
                $reviewBody = trim($body['body']) !== '' ? trim($body['body']) : null;
            }
        }

        return [$rating, $reviewBody, $errors];
    }

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    private function format(array $review): array
    {
        return [
            'id' => (int) $review['id'],
            'product_id' => (int) $review['product_id'],
            'user_id' => (int) $review['user_id'],
            'user_name' => $review['user_name'] ?? null,
            'rating' => (int) $review['rating'],
            'body' => $review['body'] ?? null,
            'created_at' => $review['created_at'],
            'updated_at' => $review['updated_at'],
        ];
    }
}
