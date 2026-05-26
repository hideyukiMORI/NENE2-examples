<?php

declare(strict_types=1);

namespace PaymentLog\Tests\Payment;

use PaymentLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PaymentWebhookTest extends TestCase
{
    private const string SECRET = 'test-webhook-secret';

    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/paymentlog_test_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $this->app = AppFactory::createSqlite($this->dbFile, self::SECRET);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, mixed> $body */
    private function req(string $method, string $path, array $body = [], string $sig = ''): ResponseInterface
    {
        $psr17  = new Psr17Factory();
        $uri    = $psr17->createUri('http://localhost' . $path);
        $rawBody = $body !== [] ? json_encode($body) : '';
        assert(is_string($rawBody));
        $stream  = $psr17->createStream($rawBody);
        $request = $psr17->createServerRequest($method, $uri)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');

        if ($sig !== '') {
            $request = $request->withHeader('X-Webhook-Signature', $sig);
        }
        return $this->app->handle($request);
    }

    /** @param array<string, mixed> $body */
    private function signedReq(string $path, array $body): ResponseInterface
    {
        $rawBody = json_encode($body);
        assert(is_string($rawBody));
        $sig = 'sha256=' . hash_hmac('sha256', $rawBody, self::SECRET);
        return $this->req('POST', $path, $body, $sig);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $res->getBody(), true);
    }

    /** @param array<string, mixed> $data */
    private function event(string $eventId, string $eventType, array $data): ResponseInterface
    {
        return $this->signedReq('/webhooks/payment', [
            'event_id'   => $eventId,
            'event_type' => $eventType,
            'data'       => $data,
        ]);
    }

    // =========================================================================
    // Signature verification

    public function testValidSignatureAccepted(): void
    {
        $res = $this->event('evt_001', 'payment.created', ['id' => 'pay_001', 'amount' => 1000, 'currency' => 'usd']);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testInvalidSignatureReturns401(): void
    {
        $res = $this->req('POST', '/webhooks/payment', [
            'event_id' => 'evt_x', 'event_type' => 'payment.created', 'data' => [],
        ], 'sha256=badhash');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testMissingSignatureReturns401(): void
    {
        $res = $this->req('POST', '/webhooks/payment', [
            'event_id' => 'evt_x', 'event_type' => 'payment.created', 'data' => [],
        ]);
        $this->assertSame(401, $res->getStatusCode());
    }

    // =========================================================================
    // payment.created

    public function testPaymentCreatedStoresPendingPayment(): void
    {
        $res = $this->event('evt_c1', 'payment.created', ['id' => 'pay_A', 'amount' => 5000, 'currency' => 'jpy']);
        $this->assertSame(200, $res->getStatusCode());

        $payments = $this->json($this->req('GET', '/payments'))['payments'];
        $this->assertCount(1, $payments);
        $this->assertSame('pay_A', $payments[0]['external_id']);
        $this->assertSame('pending', $payments[0]['status']);
        $this->assertSame(5000, $payments[0]['amount']);
    }

    public function testPaymentCreatedRequiresId(): void
    {
        $res = $this->event('evt_c2', 'payment.created', ['amount' => 1000]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testPaymentCreatedRequiresPositiveAmount(): void
    {
        $res = $this->event('evt_c3', 'payment.created', ['id' => 'pay_Z', 'amount' => 0]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // =========================================================================
    // payment.succeeded

    public function testPaymentSucceededTransitionFromPending(): void
    {
        $this->event('evt_c4', 'payment.created', ['id' => 'pay_B', 'amount' => 2000]);
        $res = $this->event('evt_s1', 'payment.succeeded', ['id' => 'pay_B']);
        $this->assertSame(200, $res->getStatusCode());

        $payments = $this->json($this->req('GET', '/payments'))['payments'];
        $this->assertSame('succeeded', $payments[0]['status']);
    }

    public function testPaymentSucceededRejectsNonPendingStatus(): void
    {
        $this->event('evt_c5', 'payment.created', ['id' => 'pay_C', 'amount' => 3000]);
        $this->event('evt_f1', 'payment.failed', ['id' => 'pay_C']);

        $res = $this->event('evt_s2', 'payment.succeeded', ['id' => 'pay_C']);
        $this->assertSame(409, $res->getStatusCode());
    }

    // =========================================================================
    // payment.failed

    public function testPaymentFailedTransitionFromPending(): void
    {
        $this->event('evt_c6', 'payment.created', ['id' => 'pay_D', 'amount' => 1500]);
        $res = $this->event('evt_f2', 'payment.failed', ['id' => 'pay_D']);
        $this->assertSame(200, $res->getStatusCode());

        $payments = $this->json($this->req('GET', '/payments'))['payments'];
        $this->assertSame('failed', $payments[0]['status']);
    }

    public function testPaymentFailedRejectsSucceededStatus(): void
    {
        $this->event('evt_c7', 'payment.created', ['id' => 'pay_E', 'amount' => 999]);
        $this->event('evt_s3', 'payment.succeeded', ['id' => 'pay_E']);

        $res = $this->event('evt_f3', 'payment.failed', ['id' => 'pay_E']);
        $this->assertSame(409, $res->getStatusCode());
    }

    // =========================================================================
    // payment.refunded

    public function testPaymentRefundedTransitionFromSucceeded(): void
    {
        $this->event('evt_c8', 'payment.created', ['id' => 'pay_F', 'amount' => 8000]);
        $this->event('evt_s4', 'payment.succeeded', ['id' => 'pay_F']);
        $res = $this->event('evt_r1', 'payment.refunded', ['id' => 'pay_F']);
        $this->assertSame(200, $res->getStatusCode());

        $payments = $this->json($this->req('GET', '/payments'))['payments'];
        $this->assertSame('refunded', $payments[0]['status']);
    }

    public function testPaymentRefundedRejectsPendingStatus(): void
    {
        $this->event('evt_c9', 'payment.created', ['id' => 'pay_G', 'amount' => 100]);
        $res = $this->event('evt_r2', 'payment.refunded', ['id' => 'pay_G']);
        $this->assertSame(409, $res->getStatusCode());
    }

    // =========================================================================
    // Idempotency

    public function testDuplicateEventIdIsIgnored(): void
    {
        $this->event('evt_idem', 'payment.created', ['id' => 'pay_H', 'amount' => 500]);

        // Same event_id → already_processed
        $res  = $this->event('evt_idem', 'payment.succeeded', ['id' => 'pay_H']);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('already_processed', $data['status']);

        // Status must still be pending (second event was ignored)
        $payments = $this->json($this->req('GET', '/payments'))['payments'];
        $this->assertSame('pending', $payments[0]['status']);
    }

    public function testSamePaymentCreatedTwiceIsIdempotent(): void
    {
        $this->event('evt_dc1', 'payment.created', ['id' => 'pay_I', 'amount' => 700]);
        $this->event('evt_dc2', 'payment.created', ['id' => 'pay_I', 'amount' => 700]);

        $payments = $this->json($this->req('GET', '/payments'))['payments'];
        $this->assertCount(1, $payments);
    }

    // =========================================================================
    // Unknown event type

    public function testUnknownEventTypeIsAcknowledgedSilently(): void
    {
        $res = $this->event('evt_unk', 'payment.disputed', ['id' => 'pay_J']);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('processed', $data['status']);
    }

    // =========================================================================
    // Payment queries

    public function testGetPaymentById(): void
    {
        $this->event('evt_g1', 'payment.created', ['id' => 'pay_K', 'amount' => 3333]);

        $payments = $this->json($this->req('GET', '/payments'))['payments'];
        $id  = (int) $payments[0]['id'];
        $res = $this->req('GET', "/payments/{$id}");
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('pay_K', $this->json($res)['external_id']);
    }

    public function testGetNonexistentPaymentReturns404(): void
    {
        $res = $this->req('GET', '/payments/9999');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testPaymentNotFoundForTransitionReturns404(): void
    {
        $res = $this->event('evt_nf', 'payment.succeeded', ['id' => 'pay_NONEXISTENT']);
        $this->assertSame(404, $res->getStatusCode());
    }
}
