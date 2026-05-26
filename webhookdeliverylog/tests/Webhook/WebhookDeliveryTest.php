<?php

declare(strict_types=1);

namespace Webhook\Tests\Webhook;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Webhook\Webhook\RouteRegistrar;
use Webhook\Webhook\UrlValidator;
use Webhook\Webhook\WebhookRepository;
use Webhook\Webhook\WebhookSigner;

final class WebhookDeliveryTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;
    private WebhookSigner $signer;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/webhooklog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $factory      = new PdoConnectionFactory($dbConfig);
        $executor     = new PdoDatabaseQueryExecutor($factory);
        $psr17        = new Psr17Factory();
        $json         = new JsonResponseFactory($psr17, $psr17);
        $problems     = new ProblemDetailsResponseFactory($psr17, $psr17);
        $this->signer = new WebhookSigner();
        $urlValidator = new UrlValidator();
        $repo         = new WebhookRepository($executor, $this->signer);

        $registrar = new RouteRegistrar($repo, $this->signer, $urlValidator, $json, $problems);

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

    private function req(string $method, string $uri, mixed $body = null): ResponseInterface
    {
        $req = new ServerRequest($method, $uri);
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')
                       ->withBody((new Psr17Factory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($req);
    }

    private function decode(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- POST /webhooks ---

    public function testCreateEndpointReturns201(): void
    {
        $res  = $this->req('POST', '/webhooks', [
            'url'        => 'https://example.com/hook',
            'event_type' => 'order.created',
            'secret'     => 'my-secret',
        ]);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('https://example.com/hook', $body['url']);
        self::assertSame('order.created', $body['event_type']);
        self::assertArrayNotHasKey('secret_hash', $body);
        self::assertArrayNotHasKey('secret', $body);
    }

    public function testCreateEndpointMissingUrlReturns422(): void
    {
        $res = $this->req('POST', '/webhooks', ['event_type' => 'x', 'secret' => 's']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testCreateEndpointMissingSecretReturns422(): void
    {
        $res = $this->req('POST', '/webhooks', ['url' => 'https://example.com', 'event_type' => 'x']);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- URL validation ---

    public function testHttpUrlRejected(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url'        => 'http://example.com/hook',
            'event_type' => 'x',
            'secret'     => 's',
        ]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testLocalhostUrlRejected(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url'        => 'https://localhost/hook',
            'event_type' => 'x',
            'secret'     => 's',
        ]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testPrivateIpUrlRejected(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url'        => 'https://192.168.1.1/hook',
            'event_type' => 'x',
            'secret'     => 's',
        ]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testInternalDomainRejected(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url'        => 'https://myapp.internal/hook',
            'event_type' => 'x',
            'secret'     => 's',
        ]);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- GET /webhooks/{id} ---

    public function testGetEndpointReturns404ForUnknown(): void
    {
        $res = $this->req('GET', '/webhooks/999');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- DELETE /webhooks/{id} ---

    public function testDeactivateEndpoint(): void
    {
        $this->req('POST', '/webhooks', ['url' => 'https://a.example.com/h', 'event_type' => 'x', 'secret' => 's']);
        $res = $this->req('DELETE', '/webhooks/1');
        self::assertSame(204, $res->getStatusCode());
    }

    // --- POST /events ---

    public function testDispatchEventCreatesDeliveries(): void
    {
        $this->req('POST', '/webhooks', ['url' => 'https://a.example.com/h', 'event_type' => 'order.created', 'secret' => 'abc']);
        $this->req('POST', '/webhooks', ['url' => 'https://b.example.com/h', 'event_type' => 'order.created', 'secret' => 'abc']);

        $res  = $this->req('POST', '/events', ['event_type' => 'order.created', 'payload' => ['id' => 1], 'secret' => 'abc']);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(2, $body['dispatched']);
        self::assertCount(2, $body['deliveries']);
    }

    public function testDispatchEventIncludesSignature(): void
    {
        $this->req('POST', '/webhooks', ['url' => 'https://a.example.com/h', 'event_type' => 'order.paid', 'secret' => 'abc']);

        $res  = $this->req('POST', '/events', ['event_type' => 'order.paid', 'payload' => ['amount' => 100], 'secret' => 'abc']);
        $body = $this->decode($res);

        self::assertStringStartsWith('sha256=', $body['deliveries'][0]['signature']);
    }

    public function testDispatchEventOnlyTargetsMatchingEventType(): void
    {
        $this->req('POST', '/webhooks', ['url' => 'https://a.example.com/h', 'event_type' => 'order.created', 'secret' => 's']);
        $this->req('POST', '/webhooks', ['url' => 'https://b.example.com/h', 'event_type' => 'order.refunded', 'secret' => 's']);

        $res  = $this->req('POST', '/events', ['event_type' => 'order.created', 'payload' => [], 'secret' => 's']);
        $body = $this->decode($res);

        self::assertSame(1, $body['dispatched']);
    }

    // --- Delivery outcomes ---

    public function testMarkDelivered(): void
    {
        $this->req('POST', '/webhooks', ['url' => 'https://a.example.com/h', 'event_type' => 'x', 'secret' => 's']);
        $this->req('POST', '/events', ['event_type' => 'x', 'payload' => [], 'secret' => 's']);

        $res  = $this->req('POST', '/deliveries/1/delivered', ['http_status' => 200]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('delivered', $body['status']);
        self::assertSame(1, $body['attempt_count']);
        self::assertNotNull($body['delivered_at']);
    }

    public function testMarkFailedWithRetriesRemainingStaysPending(): void
    {
        $this->req('POST', '/webhooks', ['url' => 'https://a.example.com/h', 'event_type' => 'x', 'secret' => 's']);
        $this->req('POST', '/events', ['event_type' => 'x', 'payload' => [], 'secret' => 's']);

        $res  = $this->req('POST', '/deliveries/1/failed', ['error' => 'Connection timeout', 'max_retries' => 3]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('pending', $body['status']);
        self::assertSame(1, $body['attempt_count']);
        self::assertSame('Connection timeout', $body['last_error']);
    }

    public function testMarkFailedExhaustingRetriesBecomesFailedStatus(): void
    {
        $this->req('POST', '/webhooks', ['url' => 'https://a.example.com/h', 'event_type' => 'x', 'secret' => 's']);
        $this->req('POST', '/events', ['event_type' => 'x', 'payload' => [], 'secret' => 's']);

        // 3 failures with max_retries=3 → final should be 'failed'
        $this->req('POST', '/deliveries/1/failed', ['error' => 'err1', 'max_retries' => 3]);
        $this->req('POST', '/deliveries/1/failed', ['error' => 'err2', 'max_retries' => 3]);
        $res  = $this->req('POST', '/deliveries/1/failed', ['error' => 'err3', 'max_retries' => 3]);
        $body = $this->decode($res);

        self::assertSame('failed', $body['status']);
        self::assertSame(3, $body['attempt_count']);
    }

    // --- Signature verification ---

    public function testSignatureIncludesTimestamp(): void
    {
        $signer    = new WebhookSigner();
        $body      = '{"id":1}';
        $timestamp = '1716000000';
        $sig1      = $signer->sign('secret', $body, $timestamp);
        $sig2      = $signer->sign('secret', $body, '9999999999');

        // Same body + secret, different timestamp → different signature
        self::assertNotSame($sig1, $sig2);
    }

    public function testDeactivatedEndpointGetsNoDeliveries(): void
    {
        $this->req('POST', '/webhooks', ['url' => 'https://a.example.com/h', 'event_type' => 'x', 'secret' => 's']);
        $this->req('DELETE', '/webhooks/1');

        $res  = $this->req('POST', '/events', ['event_type' => 'x', 'payload' => [], 'secret' => 's']);
        $body = $this->decode($res);

        self::assertSame(0, $body['dispatched']);
    }
}
