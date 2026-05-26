<?php

declare(strict_types=1);

namespace Hmac\Tests\Webhook;

use Hmac\Webhook\RouteRegistrar;
use Hmac\Webhook\WebhookEventRepository;
use Hmac\Webhook\WebhookVerifier;
use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class WebhookSignatureTest extends TestCase
{
    private RequestHandlerInterface $app;
    private WebhookEventRepository $repo;
    private WebhookVerifier $verifier;
    private string $dbFile = '';
    private const string SECRET = 'test-secret-key-do-not-use-in-production';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/hmaclog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $dbConfig = new DatabaseConfig(
            url:         null,
            environment: 'test',
            adapter:     'sqlite',
            host:        '',
            port:        1,
            name:        $this->dbFile,
            user:        '',
            password:    '',
            charset:     '',
        );

        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);

        $this->verifier = new WebhookVerifier(self::SECRET);
        $this->repo     = new WebhookEventRepository($executor);
        $registrar      = new RouteRegistrar($this->verifier, $this->repo, $json, $problems);

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
        ))->create();
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    // --- helpers ---

    /** @param array<string, mixed> $payload */
    private function signedPost(array $payload, int $timestamp, string $secret = self::SECRET): ResponseInterface
    {
        $rawBody   = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $verifier  = new WebhookVerifier($secret);
        $sigHeader = $verifier->sign($rawBody, $timestamp);

        $stream  = Stream::create($rawBody);
        $request = (new ServerRequest('POST', '/webhook'))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Webhook-Signature', $sigHeader)
            ->withBody($stream);

        return $this->app->handle($request);
    }

    private function postWithHeader(string $rawBody, string $sigHeader): ResponseInterface
    {
        $stream  = Stream::create($rawBody);
        $request = (new ServerRequest('POST', '/webhook'))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Webhook-Signature', $sigHeader)
            ->withBody($stream);

        return $this->app->handle($request);
    }

    private function postNoSignature(string $rawBody): ResponseInterface
    {
        $stream  = Stream::create($rawBody);
        $request = (new ServerRequest('POST', '/webhook'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function now(): int
    {
        return time();
    }

    // --- happy path ---

    public function testValidSignatureReturns202(): void
    {
        $res = $this->signedPost(['event_type' => 'order.created', 'order_id' => 42], $this->now());

        $this->assertSame(202, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('accepted', $data['status']);
        $this->assertSame(1, $this->repo->count());
    }

    public function testEventIsStoredAfterValidSignature(): void
    {
        $this->signedPost(['event_type' => 'payment.succeeded', 'amount' => 1000], $this->now());

        $events = $this->repo->findAll();
        $this->assertCount(1, $events);
        $this->assertSame('payment.succeeded', $events[0]->eventType);
    }

    public function testMultipleValidWebhooksAccumulate(): void
    {
        $this->signedPost(['event_type' => 'order.created'], $this->now());
        $this->signedPost(['event_type' => 'order.shipped'], $this->now());

        $this->assertSame(2, $this->repo->count());
    }

    // --- signature rejection ---

    /**
     * Missing X-Webhook-Signature header must be rejected with 401.
     */
    public function testMissingSignatureHeaderReturns401(): void
    {
        $res = $this->postNoSignature('{"event_type":"order.created"}');
        $this->assertSame(401, $res->getStatusCode());
        $this->assertStringContainsString('invalid-signature', (string) ($this->json($res)['type'] ?? ''));
    }

    /**
     * Signature computed with a different secret must be rejected.
     * An attacker who doesn't know the secret cannot forge a valid signature.
     */
    public function testWrongSecretReturns401(): void
    {
        $res = $this->signedPost(
            ['event_type' => 'order.created'],
            $this->now(),
            'wrong-secret',  // ← attacker uses wrong secret
        );

        $this->assertSame(401, $res->getStatusCode());
        // No event stored
        $this->assertSame(0, $this->repo->count());
    }

    /**
     * A tampered payload (body modified after signing) must be rejected.
     * The signature is computed over the original body; any change invalidates it.
     */
    public function testTamperedPayloadReturns401(): void
    {
        $originalBody = '{"event_type":"order.created","amount":100}';
        $timestamp    = $this->now();
        $verifier     = new WebhookVerifier(self::SECRET);
        $sigHeader    = $verifier->sign($originalBody, $timestamp);

        // Attacker modifies the body after computing the signature
        $tamperedBody = '{"event_type":"order.created","amount":9999}';
        $res          = $this->postWithHeader($tamperedBody, $sigHeader);

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(0, $this->repo->count());
    }

    /**
     * A replayed webhook with an old timestamp must be rejected.
     * The 5-minute tolerance window prevents old webhooks from being re-delivered.
     */
    public function testExpiredTimestampReturns401(): void
    {
        $oldTimestamp = $this->now() - 301; // 5 minutes and 1 second ago
        $res = $this->signedPost(['event_type' => 'order.created'], $oldTimestamp);

        $this->assertSame(401, $res->getStatusCode());
        $this->assertStringContainsString('seconds old', (string) ($this->json($res)['detail'] ?? ''));
        $this->assertSame(0, $this->repo->count());
    }

    /**
     * A webhook with a future timestamp (clock skew) is rejected if beyond tolerance.
     * This prevents pre-computation of signatures for future use.
     */
    public function testFutureTimestampBeyondToleranceReturns401(): void
    {
        $futureTimestamp = $this->now() + 301; // 5 minutes in the future
        $res = $this->signedPost(['event_type' => 'order.created'], $futureTimestamp);

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(0, $this->repo->count());
    }

    /**
     * A webhook with a slightly future timestamp (within tolerance) is accepted.
     * Real-world clock skew between sender and receiver must be tolerated.
     */
    public function testSlightlyFutureTimestampWithinToleranceIsAccepted(): void
    {
        $slightlyFuture = $this->now() + 30; // 30 seconds ahead — within 5-minute window
        $res = $this->signedPost(['event_type' => 'order.created'], $slightlyFuture);

        $this->assertSame(202, $res->getStatusCode());
    }

    /**
     * Malformed signature header format must be rejected with 401.
     */
    public function testMalformedSignatureHeaderReturns401(): void
    {
        $res = $this->postWithHeader('{"event_type":"order.created"}', 'not-a-valid-header');
        $this->assertSame(401, $res->getStatusCode());
    }

    /**
     * Signature header with non-numeric timestamp must be rejected.
     */
    public function testNonNumericTimestampReturns401(): void
    {
        $res = $this->postWithHeader('{"event_type":"order.created"}', 't=abc,v1=deadbeef');
        $this->assertSame(401, $res->getStatusCode());
    }

    // --- body validation (post-signature) ---

    public function testMissingEventTypeReturns400(): void
    {
        $res = $this->signedPost(['order_id' => 1], $this->now()); // no event_type
        $this->assertSame(400, $res->getStatusCode());
    }

    // --- list endpoint ---

    public function testListEventsReturnsStoredEvents(): void
    {
        $this->signedPost(['event_type' => 'order.created'], $this->now());
        $this->signedPost(['event_type' => 'order.shipped'], $this->now());

        $res  = $this->app->handle(new ServerRequest('GET', '/webhook/events'));
        $this->assertSame(200, $res->getStatusCode());

        $data = (array) json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $data);
    }
}
