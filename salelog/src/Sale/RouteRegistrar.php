<?php

declare(strict_types=1);

namespace Sale\Sale;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly SaleRepository    $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/products', $this->createProduct(...));
        $router->post('/sales', $this->createSale(...));
        $router->get('/sales/{saleId}', $this->getSale(...));
        $router->post('/sales/{saleId}/purchase', $this->purchase(...));
        $router->get('/sales/{saleId}/purchases', $this->listPurchases(...));
    }

    private function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
            return $this->responseFactory->create(['error' => 'name is required'], 422);
        }

        $id = $this->repository->createUser(trim($body['name']), date('c'));

        return $this->responseFactory->create(['id' => $id], 201);
    }

    private function createProduct(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
            return $this->responseFactory->create(['error' => 'name is required'], 422);
        }

        $id = $this->repository->createProduct(trim($body['name']), date('c'));

        return $this->responseFactory->create(['id' => $id], 201);
    }

    private function createSale(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        if (!isset($body['product_id']) || !is_int($body['product_id']) || $body['product_id'] <= 0) {
            return $this->responseFactory->create(['error' => 'product_id is required'], 422);
        }

        if (!isset($body['price']) || !is_int($body['price']) || $body['price'] < 0) {
            return $this->responseFactory->create(['error' => 'price must be a non-negative integer'], 422);
        }

        if (!isset($body['quantity']) || !is_int($body['quantity']) || $body['quantity'] <= 0) {
            return $this->responseFactory->create(['error' => 'quantity must be a positive integer'], 422);
        }

        if (!isset($body['starts_at']) || !is_string($body['starts_at']) || $body['starts_at'] === '') {
            return $this->responseFactory->create(['error' => 'starts_at is required'], 422);
        }

        if (!isset($body['ends_at']) || !is_string($body['ends_at']) || $body['ends_at'] === '') {
            return $this->responseFactory->create(['error' => 'ends_at is required'], 422);
        }

        if ($body['starts_at'] >= $body['ends_at']) {
            return $this->responseFactory->create(['error' => 'ends_at must be after starts_at'], 422);
        }

        $id = $this->repository->createSale(
            $body['product_id'],
            $body['price'],
            $body['quantity'],
            $body['starts_at'],
            $body['ends_at'],
            date('c'),
        );

        return $this->responseFactory->create(['id' => $id], 201);
    }

    private function getSale(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $saleId = isset($params['saleId']) ? (int) $params['saleId'] : 0;

        if ($saleId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid saleId'], 404);
        }

        $sale = $this->repository->findSaleById($saleId);

        if ($sale === null) {
            return $this->responseFactory->create(['error' => 'sale not found'], 404);
        }

        $purchased = $this->repository->countPurchases($saleId);
        $remaining = $sale['quantity'] - $purchased;
        $now       = date('c');
        $status    = match (true) {
            $now < $sale['starts_at'] => 'upcoming',
            $now > $sale['ends_at']   => 'ended',
            default                   => 'active',
        };

        return $this->responseFactory->create([
            'id'         => $sale['id'],
            'product_id' => $sale['product_id'],
            'price'      => $sale['price'],
            'quantity'   => $sale['quantity'],
            'remaining'  => max(0, $remaining),
            'status'     => $status,
            'starts_at'  => $sale['starts_at'],
            'ends_at'    => $sale['ends_at'],
        ], 200);
    }

    private function purchase(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');

        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 400);
        }

        if (!$this->repository->findUserById($actorId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $saleId = isset($params['saleId']) ? (int) $params['saleId'] : 0;

        if ($saleId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid saleId'], 404);
        }

        $sale = $this->repository->findSaleById($saleId);

        if ($sale === null) {
            return $this->responseFactory->create(['error' => 'sale not found'], 404);
        }

        $now = date('c');

        if ($now < $sale['starts_at']) {
            return $this->responseFactory->create(['error' => 'sale has not started yet'], 422);
        }

        if ($now > $sale['ends_at']) {
            return $this->responseFactory->create(['error' => 'sale has ended'], 422);
        }

        $purchased = $this->repository->countPurchases($saleId);

        if ($purchased >= $sale['quantity']) {
            return $this->responseFactory->create(['error' => 'sold out'], 422);
        }

        $success = $this->repository->purchase($saleId, $actorId, $now);

        if (!$success) {
            return $this->responseFactory->create(['error' => 'already purchased'], 409);
        }

        return $this->responseFactory->create(['ok' => true, 'sale_id' => $saleId], 201);
    }

    private function listPurchases(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $saleId = isset($params['saleId']) ? (int) $params['saleId'] : 0;

        if ($saleId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid saleId'], 404);
        }

        $sale = $this->repository->findSaleById($saleId);

        if ($sale === null) {
            return $this->responseFactory->create(['error' => 'sale not found'], 404);
        }

        $purchases = $this->repository->listPurchases($saleId);

        return $this->responseFactory->create([
            'items' => $purchases,
            'count' => count($purchases),
        ], 200);
    }
}
