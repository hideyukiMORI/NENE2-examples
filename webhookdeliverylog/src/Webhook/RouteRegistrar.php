<?php

declare(strict_types=1);

namespace Webhook\Webhook;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private WebhookRepository            $repo,
        private WebhookSigner                $signer,
        private UrlValidator                 $urlValidator,
        private JsonResponseFactory          $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/webhooks', $this->createEndpoint(...));
        $router->get('/webhooks/{id}', $this->getEndpoint(...));
        $router->delete('/webhooks/{id}', $this->deactivateEndpoint(...));
        $router->get('/webhooks/{id}/deliveries', $this->listDeliveries(...));

        // Simulate dispatching an event (creates delivery records + returns signed payload)
        $router->post('/events', $this->dispatchEvent(...));

        // Simulate worker recording delivery outcomes
        $router->post('/deliveries/{id}/delivered', $this->markDelivered(...));
        $router->post('/deliveries/{id}/failed', $this->markFailed(...));
    }

    private function createEndpoint(ServerRequestInterface $request): ResponseInterface
    {
        $body      = JsonRequestBodyParser::parse($request);
        $url       = isset($body['url']) && is_string($body['url']) ? trim($body['url']) : '';
        $eventType = isset($body['event_type']) && is_string($body['event_type']) ? trim($body['event_type']) : '';
        $secret    = isset($body['secret']) && is_string($body['secret']) ? $body['secret'] : '';

        if ($url === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'url', 'code' => 'required', 'message' => 'URL is required.']],
            ]);
        }

        if ($eventType === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'event_type', 'code' => 'required', 'message' => 'event_type is required.']],
            ]);
        }

        if ($secret === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'secret', 'code' => 'required', 'message' => 'secret is required.']],
            ]);
        }

        $urlError = $this->urlValidator->validate($url);
        if ($urlError !== null) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'url', 'code' => 'invalid', 'message' => $urlError]],
            ]);
        }

        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $endpoint = $this->repo->createEndpoint($url, $eventType, $secret, $now);

        return $this->json->create($endpoint->toArray(), 201);
    }

    private function getEndpoint(ServerRequestInterface $request): ResponseInterface
    {
        $params   = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id       = (int) ($params['id'] ?? 0);
        $endpoint = $this->repo->findEndpointById($id);

        if ($endpoint === null) {
            return $this->problems->create($request, 'not-found', 'Webhook endpoint not found.', 404, '');
        }

        return $this->json->create($endpoint->toArray());
    }

    private function deactivateEndpoint(ServerRequestInterface $request): ResponseInterface
    {
        $params   = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id       = (int) ($params['id'] ?? 0);
        $endpoint = $this->repo->findEndpointById($id);

        if ($endpoint === null) {
            return $this->problems->create($request, 'not-found', 'Webhook endpoint not found.', 404, '');
        }

        $this->repo->deactivateEndpoint($id);

        return $this->json->create([], 204);
    }

    private function listDeliveries(ServerRequestInterface $request): ResponseInterface
    {
        $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id         = (int) ($params['id'] ?? 0);
        $endpoint   = $this->repo->findEndpointById($id);

        if ($endpoint === null) {
            return $this->problems->create($request, 'not-found', 'Webhook endpoint not found.', 404, '');
        }

        $deliveries = $this->repo->findDeliveriesByEndpoint($id);

        return $this->json->create([
            'deliveries' => array_map(fn (WebhookDelivery $d) => $d->toArray(), $deliveries),
        ]);
    }

    private function dispatchEvent(ServerRequestInterface $request): ResponseInterface
    {
        $body      = JsonRequestBodyParser::parse($request);
        $eventType = isset($body['event_type']) && is_string($body['event_type']) ? trim($body['event_type']) : '';
        $payload   = isset($body['payload']) && is_array($body['payload']) ? $body['payload'] : [];
        $rawSecret = isset($body['secret']) && is_string($body['secret']) ? $body['secret'] : '';

        if ($eventType === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'event_type', 'code' => 'required', 'message' => 'event_type is required.']],
            ]);
        }

        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $timestamp = (string) time();
        $encoded   = json_encode($payload, JSON_THROW_ON_ERROR);
        $endpoints = $this->repo->findActiveEndpointsByEventType($eventType);

        $created = [];
        foreach ($endpoints as $endpoint) {
            $delivery  = $this->repo->createDelivery($endpoint->id, $eventType, $encoded, $now);
            $signature = $this->signer->sign($rawSecret, $encoded, $timestamp);
            $created[] = [
                'delivery_id' => $delivery->id,
                'endpoint_id' => $endpoint->id,
                'url'         => $endpoint->url,
                'signature'   => $signature,
                'timestamp'   => $timestamp,
            ];
        }

        return $this->json->create([
            'event_type' => $eventType,
            'dispatched' => count($created),
            'deliveries' => $created,
        ]);
    }

    private function markDelivered(ServerRequestInterface $request): ResponseInterface
    {
        $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id         = (int) ($params['id'] ?? 0);
        $body       = JsonRequestBodyParser::parse($request);
        $httpStatus = isset($body['http_status']) && is_int($body['http_status']) ? $body['http_status'] : 200;

        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $delivery = $this->repo->markDelivered($id, $httpStatus, $now);

        if ($delivery === null) {
            return $this->problems->create($request, 'not-found', 'Delivery not found.', 404, '');
        }

        return $this->json->create($delivery->toArray());
    }

    private function markFailed(ServerRequestInterface $request): ResponseInterface
    {
        $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id         = (int) ($params['id'] ?? 0);
        $body       = JsonRequestBodyParser::parse($request);
        $error      = isset($body['error']) && is_string($body['error']) ? $body['error'] : 'Unknown error';
        $httpStatus = isset($body['http_status']) && is_int($body['http_status']) ? $body['http_status'] : null;
        $maxRetries = isset($body['max_retries']) && is_int($body['max_retries']) ? $body['max_retries'] : 3;

        $delivery = $this->repo->findDeliveryById($id);
        if ($delivery === null) {
            return $this->problems->create($request, 'not-found', 'Delivery not found.', 404, '');
        }

        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $delivery = $this->repo->markFailed($id, $error, $httpStatus, $now, $maxRetries);
        if ($delivery === null) {
            return $this->problems->create($request, 'not-found', 'Delivery not found.', 404, '');
        }

        return $this->json->create($delivery->toArray());
    }
}
