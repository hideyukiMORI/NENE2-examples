<?php

declare(strict_types=1);

namespace InboundLog\Tests\Inbound;

use InboundLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class InboundTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;
    private const string SECRET = 'test-secret-key';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/inboundlog_test_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $this->app = AppFactory::createSqlite($this->dbFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $headers */
    private function req(string $method, string $path, mixed $body = null, array $headers = []): ResponseInterface
    {
        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $this->app->handle($request);
    }

    /** @param array<string, mixed> $payload */
    private function webhookReq(string $path, array $payload, string $secret = self::SECRET): ResponseInterface
    {
        $rawBody = json_encode($payload);
        assert($rawBody !== false);
        $sig = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri('http://localhost' . $path);
        $stream  = $psr17->createStream($rawBody);
        $request = $psr17->createServerRequest('POST', $uri)
            ->withBody($stream)
            ->withHeader('X-Webhook-Signature', $sig)
            ->withHeader('Content-Type', 'application/json');
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $res->getBody(), true);
    }

    private function createSource(string $name, string $secret = self::SECRET): int
    {
        $res = $this->req('POST', '/sources', ['name' => $name, 'secret' => $secret]);
        return (int) $this->json($res)['id'];
    }

    // =========================================================================

    public function testCreateSourceReturns201(): void
    {
        $res  = $this->req('POST', '/sources', ['name' => 'stripe', 'secret' => 'abc123']);
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('stripe', $data['name']);
        $this->assertArrayNotHasKey('secret', $data); // never expose secret
    }

    public function testCreateSourceRequiresName(): void
    {
        $res = $this->req('POST', '/sources', ['secret' => 'abc']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateSourceRequiresSecret(): void
    {
        $res = $this->req('POST', '/sources', ['name' => 'github']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testReceiveWebhookReturns201(): void
    {
        $srcId = $this->createSource('shopify');
        $res   = $this->webhookReq("/sources/{$srcId}/receive", [
            'event_id' => 'evt-001', 'event_type' => 'order.created', 'data' => ['amount' => 100],
        ]);
        $data  = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('processed', $data['status']);
        $this->assertSame('order.created', $data['event_type']);
    }

    public function testDuplicateEventIdIsIdempotent(): void
    {
        $srcId = $this->createSource('twilio');
        $payload = ['event_id' => 'evt-dup', 'event_type' => 'message.sent'];
        $this->webhookReq("/sources/{$srcId}/receive", $payload);
        $res  = $this->webhookReq("/sources/{$srcId}/receive", $payload);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('already_processed', $data['status']);
    }

    public function testInvalidSignatureReturns401(): void
    {
        $srcId   = $this->createSource('github');
        $rawBody = json_encode(['event_id' => 'x', 'event_type' => 'push']);
        assert($rawBody !== false);
        $badSig  = 'sha256=' . hash_hmac('sha256', $rawBody, 'wrong-secret');

        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri("http://localhost/sources/{$srcId}/receive");
        $stream  = $psr17->createStream($rawBody);
        $request = $psr17->createServerRequest('POST', $uri)
            ->withBody($stream)
            ->withHeader('X-Webhook-Signature', $badSig);
        $res = $this->app->handle($request);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testMissingSignatureReturns401(): void
    {
        $srcId  = $this->createSource('aws');
        $res    = $this->req('POST', "/sources/{$srcId}/receive", ['event_id' => 'x', 'event_type' => 'y']);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testSourceNotFoundReturns404(): void
    {
        $res = $this->webhookReq('/sources/9999/receive', ['event_id' => 'x', 'event_type' => 'y']);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testListEvents(): void
    {
        $srcId = $this->createSource('sendgrid');
        $this->webhookReq("/sources/{$srcId}/receive", ['event_id' => 'e1', 'event_type' => 'delivered']);
        $this->webhookReq("/sources/{$srcId}/receive", ['event_id' => 'e2', 'event_type' => 'bounced']);

        $data = $this->json($this->req('GET', "/sources/{$srcId}/events"));
        $this->assertSame(2, $data['count']);
    }

    public function testGetEvent(): void
    {
        $srcId   = $this->createSource('salesforce');
        $res     = $this->webhookReq("/sources/{$srcId}/receive", ['event_id' => 'ev-10', 'event_type' => 'lead.created']);
        $eventId = (int) $this->json($res)['id'];

        $data = $this->json($this->req('GET', "/events/{$eventId}"));
        $this->assertSame(200, $this->req('GET', "/events/{$eventId}")->getStatusCode());
        $this->assertSame('lead.created', $data['event_type']);
    }

    public function testGetNonexistentEventReturns404(): void
    {
        $this->assertSame(404, $this->req('GET', '/events/9999')->getStatusCode());
    }

    public function testPayloadMissingEventIdReturns422(): void
    {
        $srcId   = $this->createSource('test-src');
        $rawBody = json_encode(['event_type' => 'x']);
        assert($rawBody !== false);
        $sig     = 'sha256=' . hash_hmac('sha256', $rawBody, self::SECRET);

        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri("http://localhost/sources/{$srcId}/receive");
        $stream  = $psr17->createStream($rawBody);
        $request = $psr17->createServerRequest('POST', $uri)
            ->withBody($stream)
            ->withHeader('X-Webhook-Signature', $sig);
        $this->assertSame(422, $this->app->handle($request)->getStatusCode());
    }
}
