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

/**
 * Cracker-mindset attack test suite (FT120 — 4th-cycle adversarial test).
 *
 * Inferred attack surface:
 * - POST /webhooks accepts a URL → SSRF, header injection, scheme bypass
 * - secret is passed in request → does it leak in responses or logs?
 * - Signature uses HMAC → can we forge, replay, or strip it?
 * - Delivery retry logic → can we inflate attempt_count?
 * - Event dispatch fan-out → can we target deactivated or wrong-type endpoints?
 */
final class AttackTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/webhooklog-attack-' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $signer       = new WebhookSigner();
        $urlValidator = new UrlValidator();
        $repo         = new WebhookRepository($executor, $signer);

        $registrar = new RouteRegistrar($repo, $signer, $urlValidator, $json, $problems);

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

    // === ATTACK CATEGORY 1: SSRF via URL manipulation ===

    /** Attack: redirect chain via 127.0.0.1 (numeric loopback) */
    public function testAttackSsrfLoopbackIpv4Blocked(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url' => 'https://127.0.0.1/admin/delete-all', 'event_type' => 'x', 'secret' => 's',
        ]);
        self::assertSame(422, $res->getStatusCode(), 'ATTACK: 127.0.0.1 SSRF must be blocked');
    }

    /** Attack: 0.0.0.0 resolves to loopback on many systems */
    public function testAttackSsrfZeroIpBlocked(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url' => 'https://0.0.0.0/secret', 'event_type' => 'x', 'secret' => 's',
        ]);
        self::assertSame(422, $res->getStatusCode(), 'ATTACK: 0.0.0.0 SSRF must be blocked');
    }

    /** Attack: 10.x private range */
    public function testAttackSsrfPrivateRange10Blocked(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url' => 'https://10.0.0.1/internal', 'event_type' => 'x', 'secret' => 's',
        ]);
        self::assertSame(422, $res->getStatusCode(), 'ATTACK: 10.x private SSRF must be blocked');
    }

    /** Attack: 172.16.x private range */
    public function testAttackSsrfPrivateRange172Blocked(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url' => 'https://172.16.0.1/admin', 'event_type' => 'x', 'secret' => 's',
        ]);
        self::assertSame(422, $res->getStatusCode(), 'ATTACK: 172.16.x private SSRF must be blocked');
    }

    /** Attack: http:// scheme downgrade */
    public function testAttackHttpSchemeDowngrade(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url' => 'http://evil.example.com/capture', 'event_type' => 'x', 'secret' => 's',
        ]);
        self::assertSame(422, $res->getStatusCode(), 'ATTACK: http:// scheme must be blocked');
    }

    /** Attack: file:// scheme for local file read */
    public function testAttackFileSchemeBlocked(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url' => 'file:///etc/passwd', 'event_type' => 'x', 'secret' => 's',
        ]);
        self::assertSame(422, $res->getStatusCode(), 'ATTACK: file:// scheme must be blocked');
    }

    // === ATTACK CATEGORY 2: Header injection in URL field ===

    /** Attack: CRLF injection in URL to inject HTTP headers */
    public function testAttackCrlfInjectionInUrl(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url'        => "https://example.com/hook\r\nX-Injected: evil",
            'event_type' => 'x',
            'secret'     => 's',
        ]);
        self::assertSame(422, $res->getStatusCode(), 'ATTACK: CRLF injection in URL must be blocked');
    }

    /** Attack: null byte injection */
    public function testAttackNullByteInUrl(): void
    {
        $res = $this->req('POST', '/webhooks', [
            'url'        => "https://example.com/hook\0/etc/passwd",
            'event_type' => 'x',
            'secret'     => 's',
        ]);
        self::assertSame(422, $res->getStatusCode(), 'ATTACK: null byte in URL must be blocked');
    }

    // === ATTACK CATEGORY 3: Secret leakage ===

    /** Attack: register endpoint and check if secret leaks in GET response */
    public function testAttackSecretDoesNotLeakInGetEndpoint(): void
    {
        $this->req('POST', '/webhooks', [
            'url' => 'https://example.com/h', 'event_type' => 'x', 'secret' => 'super-secret-value',
        ]);

        $res  = $this->req('GET', '/webhooks/1');
        $body = $this->decode($res);

        self::assertArrayNotHasKey('secret', $body, 'ATTACK: raw secret must not appear in response');
        self::assertArrayNotHasKey('secret_hash', $body, 'ATTACK: secret_hash must not appear in response');

        $json = json_encode($body, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('super-secret-value', $json, 'ATTACK: secret value must not appear in any field');
    }

    /** Attack: event dispatch response should not expose raw secret */
    public function testAttackSecretDoesNotLeakInDispatchResponse(): void
    {
        $this->req('POST', '/webhooks', [
            'url' => 'https://example.com/h', 'event_type' => 'pay.done', 'secret' => 'hidden-secret',
        ]);

        $res  = $this->req('POST', '/events', [
            'event_type' => 'pay.done', 'payload' => [], 'secret' => 'hidden-secret',
        ]);
        $body = $this->decode($res);

        $json = json_encode($body, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('hidden-secret', $json, 'ATTACK: secret must not appear in dispatch response');
    }

    // === ATTACK CATEGORY 4: Signature replay / bypass ===

    /** Attack: different timestamps produce different signatures (replay protection) */
    public function testAttackReplayAttackSignatureBindsTimestamp(): void
    {
        $signer = new WebhookSigner();
        $body   = '{"id":1}';

        $sig1 = $signer->sign('secret', $body, '1000000000');
        $sig2 = $signer->sign('secret', $body, '1000000001');

        self::assertNotSame($sig1, $sig2, 'ATTACK: changing timestamp must invalidate signature');
    }

    /** Attack: wrong secret produces different signature (signature cannot be forged) */
    public function testAttackForgedSignatureWithWrongSecret(): void
    {
        $signer    = new WebhookSigner();
        $body      = '{"id":1}';
        $timestamp = '1000000000';

        $legitSig  = $signer->sign('real-secret', $body, $timestamp);
        $forgedSig = $signer->sign('attacker-secret', $body, $timestamp);

        self::assertNotSame($legitSig, $forgedSig, 'ATTACK: forged signature with wrong secret must differ');
    }

    // === ATTACK CATEGORY 5: Endpoint isolation ===

    /** Attack: dispatch to deactivated endpoint should not create deliveries */
    public function testAttackDeactivatedEndpointGetsNoDeliveries(): void
    {
        $this->req('POST', '/webhooks', ['url' => 'https://a.example.com/h', 'event_type' => 'x', 'secret' => 's']);
        $this->req('DELETE', '/webhooks/1');

        $res  = $this->req('POST', '/events', ['event_type' => 'x', 'payload' => [], 'secret' => 's']);
        $body = $this->decode($res);

        self::assertSame(0, $body['dispatched'], 'ATTACK: deactivated endpoint must not receive deliveries');
    }

    /** Attack: wrong event type should not cross-contaminate endpoints */
    public function testAttackEventTypeDoesNotCrossContaminate(): void
    {
        $this->req('POST', '/webhooks', ['url' => 'https://a.example.com/h', 'event_type' => 'order.created', 'secret' => 's']);
        $this->req('POST', '/webhooks', ['url' => 'https://b.example.com/h', 'event_type' => 'order.refunded', 'secret' => 's']);

        // Dispatch only order.refunded
        $res  = $this->req('POST', '/events', ['event_type' => 'order.refunded', 'payload' => [], 'secret' => 's']);
        $body = $this->decode($res);

        self::assertSame(1, $body['dispatched'], 'ATTACK: event dispatch must be isolated by type');
        self::assertSame(2, $body['deliveries'][0]['endpoint_id'], 'ATTACK: must target correct endpoint only');
    }
}
