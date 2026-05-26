<?php

declare(strict_types=1);

namespace Hmac\Webhook;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly WebhookEventRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/webhook', $this->receive(...));
        $router->get('/webhook/events', $this->listEvents(...));
    }

    private function receive(ServerRequestInterface $request): ResponseInterface
    {
        $rawBody = (string) $request->getBody();

        try {
            $this->verifier->verify($request, $rawBody);
        } catch (SignatureException $e) {
            return $this->problems->create(
                $request,
                'invalid-signature',
                'Invalid webhook signature.',
                401,
                $e->getMessage(),
            );
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body) || !isset($body['event_type']) || !is_string($body['event_type'])) {
            return $this->problems->create($request, 'invalid-body', 'event_type (string) is required.', 400);
        }

        $event = $this->repo->store($body['event_type'], $rawBody);

        return $this->json->create(['id' => $event->id, 'status' => 'accepted'], 202);
    }

    private function listEvents(ServerRequestInterface $request): ResponseInterface
    {
        $events = $this->repo->findAll();

        return $this->json->createList(array_map(
            fn (WebhookEvent $e) => [
                'id'           => $e->id,
                'event_type'   => $e->eventType,
                'delivered_at' => $e->deliveredAt,
            ],
            $events,
        ));
    }
}
