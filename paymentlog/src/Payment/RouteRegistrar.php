<?php

declare(strict_types=1);

namespace PaymentLog\Payment;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const array VALID_TRANSITIONS = [
        'payment.succeeded' => ['from' => 'pending',   'to' => 'succeeded'],
        'payment.failed'    => ['from' => 'pending',   'to' => 'failed'],
        'payment.refunded'  => ['from' => 'succeeded', 'to' => 'refunded'],
    ];

    public function __construct(
        private readonly PaymentRepository $repo,
        private readonly string $webhookSecret,
        private readonly JsonResponseFactory $json,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/webhooks/payment', $this->handleWebhook(...));
        $router->get('/payments', $this->handleList(...));
        $router->get('/payments/{id}', $this->handleGet(...));
    }

    private function handleWebhook(ServerRequestInterface $request): ResponseInterface
    {
        $rawBody   = (string) $request->getBody();
        $sigHeader = $request->getHeaderLine('X-Webhook-Signature');

        if (!$this->verifySignature($rawBody, $sigHeader)) {
            return $this->json->create(['error' => 'Invalid webhook signature'], 401);
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->json->create(['error' => 'Invalid JSON payload'], 400);
        }

        $eventId   = isset($payload['event_id']) && is_string($payload['event_id']) ? $payload['event_id'] : '';
        $eventType = isset($payload['event_type']) && is_string($payload['event_type']) ? $payload['event_type'] : '';

        if ($eventId === '' || $eventType === '') {
            return $this->json->create(['error' => 'event_id and event_type are required'], 400);
        }

        // Idempotent: already processed
        if ($this->repo->isEventProcessed($eventId)) {
            return $this->json->create(['status' => 'already_processed']);
        }

        $now  = $this->now();
        /** @var array<string, mixed> $data */
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        $result = $this->processEvent($eventType, $data, $now);
        if ($result !== null) {
            return $result;
        }

        $this->repo->recordEvent($eventId, $eventType, $payload, $now);
        return $this->json->create(['status' => 'processed', 'event_type' => $eventType]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function processEvent(string $eventType, array $data, string $now): ?ResponseInterface
    {
        if ($eventType === 'payment.created') {
            $externalId = isset($data['id']) && is_string($data['id']) ? $data['id'] : '';
            $amount     = isset($data['amount']) && is_int($data['amount']) ? $data['amount'] : 0;
            $currency   = isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : 'usd';

            if ($externalId === '' || $amount <= 0) {
                return $this->json->create(['error' => 'payment.created requires id and positive amount'], 422);
            }

            $existing = $this->repo->findByExternalId($externalId);
            if ($existing === null) {
                $this->repo->createPayment($externalId, $amount, $currency, $now);
            }
            return null;
        }

        if (isset(self::VALID_TRANSITIONS[$eventType])) {
            $transition = self::VALID_TRANSITIONS[$eventType];
            $externalId = isset($data['id']) && is_string($data['id']) ? $data['id'] : '';
            if ($externalId === '') {
                return $this->json->create(['error' => 'payment id is required'], 422);
            }
            $payment = $this->repo->findByExternalId($externalId);
            if ($payment === null) {
                return $this->json->create(['error' => 'Payment not found'], 404);
            }
            if ((string) $payment['status'] !== $transition['from']) {
                return $this->json->create([
                    'error'   => "Invalid status transition: {$payment['status']} → {$transition['to']}",
                    'current' => $payment['status'],
                ], 409);
            }
            $this->repo->updateStatus($externalId, $transition['to'], $now);
            return null;
        }

        // Unknown event type — acknowledge without processing
        return null;
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $payments = $this->repo->findAll();
        return $this->json->create(['payments' => $payments, 'count' => count($payments)]);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);
        $payment = $this->repo->find($id);
        if ($payment === null) {
            return $this->json->create(['error' => 'Payment not found'], 404);
        }
        return $this->json->create($payment);
    }

    private function verifySignature(string $body, string $header): bool
    {
        if (!str_starts_with($header, 'sha256=')) {
            return false;
        }
        $provided = substr($header, 7);
        $expected = hash_hmac('sha256', $body, $this->webhookSecret);
        return hash_equals($expected, $provided);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
