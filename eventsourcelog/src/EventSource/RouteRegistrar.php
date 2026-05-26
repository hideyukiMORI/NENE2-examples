<?php

declare(strict_types=1);

namespace EventSource\EventSource;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private EventSourceRepository $repo,
        private JsonResponseFactory $json,
        private ProblemDetailsResponseFactory $problems,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/accounts', $this->createAccount(...));
        $router->post('/accounts/{id}/deposit', $this->deposit(...));
        $router->post('/accounts/{id}/withdraw', $this->withdraw(...));
        $router->get('/accounts/{id}/balance', $this->balance(...));
        $router->get('/accounts/{id}/events', $this->events(...));
    }

    private function createAccount(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $owner = isset($body['owner']) && is_string($body['owner']) ? trim($body['owner']) : '';

        if ($owner === '') {
            return $this->problems->create($request, 'validation-failed', 'owner is required.', 422, '');
        }

        $now     = date('Y-m-d H:i:s');
        $account = $this->repo->createAccount($owner, $now);
        $this->repo->appendEvent($account->id, DomainEvent::TYPE_ACCOUNT_CREATED, ['owner' => $owner], $now);

        return $this->json->create($account->toArray(), 201);
    }

    private function deposit(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $account = $this->repo->findAccountById($id);

        if ($account === null) {
            return $this->problems->create($request, 'not-found', 'Account not found.', 404, '');
        }

        $body   = JsonRequestBodyParser::parse($request);
        $amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

        if ($amount <= 0 || $amount > 1_000_000_000) {
            return $this->problems->create($request, 'validation-failed', 'amount must be a positive integer not exceeding 1000000000.', 422, '');
        }

        $now   = date('Y-m-d H:i:s');
        $event = $this->repo->appendEvent($id, DomainEvent::TYPE_DEPOSITED, ['amount' => $amount], $now);

        return $this->json->create($event->toArray(), 201);
    }

    private function withdraw(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id      = (int) ($params['id'] ?? 0);
        $account = $this->repo->findAccountById($id);

        if ($account === null) {
            return $this->problems->create($request, 'not-found', 'Account not found.', 404, '');
        }

        $body   = JsonRequestBodyParser::parse($request);
        $amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

        if ($amount <= 0 || $amount > 1_000_000_000) {
            return $this->problems->create($request, 'validation-failed', 'amount must be a positive integer not exceeding 1000000000.', 422, '');
        }

        $balance = $this->repo->replayBalance($id);

        if ($amount > $balance) {
            return $this->problems->create($request, 'insufficient-funds', 'Insufficient funds.', 422, '');
        }

        $now   = date('Y-m-d H:i:s');
        $event = $this->repo->appendEvent($id, DomainEvent::TYPE_WITHDRAWN, ['amount' => $amount], $now);

        return $this->json->create($event->toArray(), 201);
    }

    private function balance(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id      = (int) ($params['id'] ?? 0);
        $account = $this->repo->findAccountById($id);

        if ($account === null) {
            return $this->problems->create($request, 'not-found', 'Account not found.', 404, '');
        }

        $balance = $this->repo->replayBalance($id);

        return $this->json->create(['account_id' => $id, 'balance' => $balance]);
    }

    private function events(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id      = (int) ($params['id'] ?? 0);
        $account = $this->repo->findAccountById($id);

        if ($account === null) {
            return $this->problems->create($request, 'not-found', 'Account not found.', 404, '');
        }

        $events = $this->repo->findEventsByAggregateId($id);

        return $this->json->create(['events' => array_map(static fn(DomainEvent $e) => $e->toArray(), $events)]);
    }
}
