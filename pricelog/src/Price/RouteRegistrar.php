<?php

declare(strict_types=1);

namespace PriceLog\Price;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\QueryStringParser;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const array CURRENCIES = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];

    public function __construct(
        private readonly PriceRepository $repo,
        private readonly PriceService $service,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/products', $this->createProduct(...));
        $router->get('/products', $this->listProducts(...));
        $router->get('/products/{id}', $this->getProduct(...));
        $router->post('/products/{id}/prices', $this->setPrice(...));
        $router->get('/products/{id}/prices', $this->history(...));
        $router->get('/products/{id}/prices/current', $this->current(...));
        $router->get('/products/{id}/prices/at', $this->priceAt(...));
    }

    private function createProduct(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new ValidationException([new ValidationError('name', 'name must be a non-empty string', 'invalid_value')]);
        }
        $id = $this->repo->createProduct(trim($name), $this->now());
        return $this->json->create($this->productView((array) $this->repo->findProduct($id)), 201);
    }

    private function listProducts(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json->create(['products' => array_map($this->productView(...), $this->repo->listProducts())]);
    }

    private function getProduct(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $product = $id === null ? null : $this->repo->findProduct($id);
        if ($product === null) {
            return $this->notFound();
        }
        return $this->json->create($this->productView($product));
    }

    private function setPrice(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $id = $this->idParam($request);
        if ($id === null || $this->repo->findProduct($id) === null) {
            return $this->notFound();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $amount = $body['amount'] ?? null;
        if (!is_int($amount) || $amount < 0) {
            $errors[] = new ValidationError('amount', 'amount must be a non-negative integer (cents)', 'invalid_value');
        }
        $currency = $body['currency'] ?? 'USD';
        if (!is_string($currency) || !in_array($currency, self::CURRENCIES, true)) {
            $errors[] = new ValidationError('currency', 'currency must be one of: ' . implode(', ', self::CURRENCIES), 'invalid_value');
        }
        $effectiveFrom = $body['effective_from'] ?? null;
        if (!is_string($effectiveFrom) || !$this->isIsoUtc($effectiveFrom)) {
            $errors[] = new ValidationError('effective_from', 'effective_from must be ISO 8601 UTC (e.g. 2026-05-27T00:00:00Z)', 'invalid_value');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($currency) && is_string($effectiveFrom));

        $this->service->setPrice($id, $amount, $currency, $effectiveFrom, $this->now());
        $tier = $this->repo->priceAt($id, $effectiveFrom);
        return $this->json->create($this->tierView((array) $tier), 201);
    }

    private function history(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($id === null || $this->repo->findProduct($id) === null) {
            return $this->notFound();
        }
        return $this->json->create(['prices' => array_map($this->tierView(...), $this->repo->history($id))]);
    }

    private function current(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($id === null || $this->repo->findProduct($id) === null) {
            return $this->notFound();
        }
        $tier = $this->repo->priceAt($id, $this->now());
        if ($tier === null) {
            return $this->json->create(['error' => 'No active price'], 404);
        }
        return $this->json->create($this->tierView($tier));
    }

    private function priceAt(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($id === null || $this->repo->findProduct($id) === null) {
            return $this->notFound();
        }
        $datetime = QueryStringParser::string($request, 'datetime');
        if ($datetime === null || !$this->isIsoUtc($datetime)) {
            throw new ValidationException([new ValidationError('datetime', 'datetime must be ISO 8601 UTC', 'invalid_value')]);
        }
        $tier = $this->repo->priceAt($id, $datetime);
        if ($tier === null) {
            return $this->json->create(['error' => 'No price at that time'], 404);
        }
        return $this->json->create($this->tierView($tier));
    }

    /** Strict ISO 8601 UTC: round-trip via the literal-Z format. */
    private function isIsoUtc(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        $provided = $request->getHeaderLine('X-Admin-Key');
        return $provided !== '' && hash_equals($this->adminKey, $provided);
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function productView(array $p): array
    {
        return ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'created_at' => (string) $p['created_at']];
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private function tierView(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'amount' => (int) $t['amount'],
            'currency' => (string) $t['currency'],
            'effective_from' => (string) $t['effective_from'],
            'effective_to' => $t['effective_to'] === null ? null : (string) $t['effective_to'],
        ];
    }

    private function idParam(ServerRequestInterface $request): ?int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        if (!ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function forbidden(): ResponseInterface
    {
        return $this->json->create(['error' => 'Admin key required'], 403);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Product not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
