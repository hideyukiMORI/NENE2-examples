<?php

declare(strict_types=1);

namespace Injection\Product;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\QueryStringParser;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private SqliteProductRepository $repo,
        private JsonResponseFactory $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/products', $this->listProducts(...));
        $router->post('/products', $this->createProduct(...));
        $router->get('/products/{id}', $this->getProduct(...));
        $router->delete('/products/{id}', $this->deleteProduct(...));
    }

    private function listProducts(ServerRequestInterface $request): ResponseInterface
    {
        $q     = QueryStringParser::string($request, 'q') ?? '';
        $sort  = QueryStringParser::string($request, 'sort') ?? 'id';
        $order = QueryStringParser::string($request, 'order') ?? 'asc';

        try {
            $products = $this->repo->search($q, $sort, $order);
        } catch (InvalidSortFieldException) {
            return $this->problems->create(
                $request,
                'invalid-sort-field',
                'Invalid sort field',
                400,
                'Allowed sort fields: id, name, category, price.',
            );
        }

        return $this->json->createList(
            array_map(static fn (Product $p) => $p->toArray(), $products),
        );
    }

    private function createProduct(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        $name        = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        $category    = isset($body['category']) && is_string($body['category']) ? trim($body['category']) : '';
        $price       = isset($body['price']) && (is_int($body['price']) || is_float($body['price'])) ? (float) $body['price'] : -1.0;
        $description = isset($body['description']) && is_string($body['description']) ? trim($body['description']) : '';

        $errors = [];
        if ($name === '') {
            $errors[] = ['field' => 'name', 'code' => 'required', 'message' => 'Name is required.'];
        }
        if ($category === '') {
            $errors[] = ['field' => 'category', 'code' => 'required', 'message' => 'Category is required.'];
        }
        if ($price < 0.0) {
            $errors[] = ['field' => 'price', 'code' => 'invalid', 'message' => 'Price must be a non-negative number.'];
        }

        if ($errors !== []) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => $errors,
            ]);
        }

        $product = $this->repo->create($name, $category, $price, $description);

        return $this->json->create($product->toArray(), 201);
    }

    private function getProduct(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            return $this->problems->create($request, 'not-found', 'Not Found', 404, 'Product not found.');
        }

        $product = $this->repo->findById($id);

        if ($product === null) {
            return $this->problems->create($request, 'not-found', 'Not Found', 404, 'Product not found.');
        }

        return $this->json->create($product->toArray());
    }

    private function deleteProduct(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            return $this->problems->create($request, 'not-found', 'Not Found', 404, 'Product not found.');
        }

        if (!$this->repo->delete($id)) {
            return $this->problems->create($request, 'not-found', 'Not Found', 404, 'Product not found.');
        }

        return $this->json->create(['deleted' => true]);
    }
}
