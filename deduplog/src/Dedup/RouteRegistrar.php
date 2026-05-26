<?php

declare(strict_types=1);

namespace DedupLog\Dedup;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    private const int TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly IdempotencyRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/payments', $this->handlePayment(...));
        $router->post('/orders', $this->handleOrder(...));
    }

    private function handlePayment(ServerRequestInterface $request): ResponseInterface
    {
        $key = trim($request->getHeaderLine('Idempotency-Key'));
        if ($key === '') {
            return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
        }

        $cached = $this->getCachedResponse($key, $request);
        if ($cached !== null) {
            return $cached;
        }

        $body   = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $rawAmount = $body['amount'] ?? null;
        $amount    = 0;
        if (!is_int($rawAmount) && !(is_string($rawAmount) && ctype_digit($rawAmount))) {
            $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
        } else {
            $amount = (int) $rawAmount;
            if ($amount <= 0) {
                $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
            }
        }

        $currency = isset($body['currency']) && is_string($body['currency'])
            ? strtoupper(trim($body['currency'])) : 'USD';

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now       = $this->now();
        $paymentId = $this->repo->createPayment($amount, $currency, $key, $now);
        $data      = [
            'id' => $paymentId, 'amount' => $amount, 'currency' => $currency,
            'status' => 'completed', 'idempotency_key' => $key, 'created_at' => $now,
        ];

        $this->cacheResponse($key, 'POST', '/payments', 201, $data, $now);
        return $this->json->create($data, 201);
    }

    private function handleOrder(ServerRequestInterface $request): ResponseInterface
    {
        $key = trim($request->getHeaderLine('Idempotency-Key'));
        if ($key === '') {
            return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
        }

        $cached = $this->getCachedResponse($key, $request);
        if ($cached !== null) {
            return $cached;
        }

        $body   = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $item = isset($body['item']) && is_string($body['item']) ? trim($body['item']) : '';
        if ($item === '') {
            $errors[] = new ValidationError('item', 'item is required', 'required');
        }

        $quantity = isset($body['quantity']) ? (int) $body['quantity'] : 0;
        if ($quantity <= 0) {
            $errors[] = new ValidationError('quantity', 'quantity must be a positive integer', 'invalid');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now     = $this->now();
        $orderId = $this->repo->createOrder($item, $quantity, $key, $now);
        $data    = [
            'id' => $orderId, 'item' => $item, 'quantity' => $quantity,
            'idempotency_key' => $key, 'created_at' => $now,
        ];

        $this->cacheResponse($key, 'POST', '/orders', 201, $data, $now);
        return $this->json->create($data, 201);
    }

    private function getCachedResponse(
        string $key,
        ServerRequestInterface $request,
    ): ?ResponseInterface {
        $cached = $this->repo->find($key);
        if ($cached === null) {
            return null;
        }

        // Expired entries are treated as fresh (re-processable)
        if ($cached['expires_at'] < $this->now()) {
            return null;
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $cached['response_body'], true) ?? [];
        return $this->json->create(
            array_merge($body, ['replayed' => true]),
            (int) $cached['status_code']
        );
    }

    /** @param array<string, mixed> $data */
    private function cacheResponse(
        string $key,
        string $method,
        string $path,
        int $statusCode,
        array $data,
        string $now,
    ): void {
        $expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
            ->modify('+' . self::TTL_SECONDS . ' seconds')
            ->format('Y-m-d\TH:i:s\Z');
        $this->repo->store($key, $method, $path, $statusCode, (string) json_encode($data), $now, $expiresAt);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
