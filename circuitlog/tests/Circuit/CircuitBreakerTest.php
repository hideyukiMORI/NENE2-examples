<?php

declare(strict_types=1);

namespace Circuit\Tests\Circuit;

use Circuit\Circuit\CircuitBreakerRepository;
use Circuit\Circuit\CircuitState;
use Circuit\Circuit\RouteRegistrar;
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

final class CircuitBreakerTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;
    private CircuitBreakerRepository $repo;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/circuitlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $factory    = new PdoConnectionFactory($dbConfig);
        $executor   = new PdoDatabaseQueryExecutor($factory);
        $psr17      = new Psr17Factory();
        $json       = new JsonResponseFactory($psr17, $psr17);
        $problems   = new ProblemDetailsResponseFactory($psr17, $psr17);
        $this->repo = new CircuitBreakerRepository($executor);

        $registrar = new RouteRegistrar($this->repo, $json, $problems);

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

    // --- Initial state ---

    public function testNewCircuitStartsClosed(): void
    {
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $circuit = $this->repo->findOrCreate('payment-svc', 3, $now);

        self::assertSame(CircuitState::Closed, $circuit->state);
        self::assertSame(0, $circuit->failureCount);
    }

    // --- Closed → Open transition ---

    public function testCircuitOpensAfterThresholdFailures(): void
    {
        // threshold=3, so 3 failures trip the circuit
        $res  = $this->req('POST', '/circuits/payment-svc/call', ['success' => false, 'threshold' => 3]);
        $res  = $this->req('POST', '/circuits/payment-svc/call', ['success' => false, 'threshold' => 3]);
        $res  = $this->req('POST', '/circuits/payment-svc/call', ['success' => false, 'threshold' => 3]);
        $body = $this->decode($res);

        self::assertSame('failure', $body['result']);
        self::assertSame('open', $body['circuit']['state']);
    }

    public function testOpenCircuitReturns503(): void
    {
        // Open the circuit
        $this->req('POST', '/circuits/email-svc/call', ['success' => false, 'threshold' => 1]);
        $this->req('POST', '/circuits/email-svc/call', ['success' => false, 'threshold' => 1]);

        // Next call should be blocked
        $res = $this->req('POST', '/circuits/email-svc/call', ['success' => true, 'threshold' => 1]);
        self::assertSame(503, $res->getStatusCode());
    }

    public function testFailuresBelowThresholdKeepCircuitClosed(): void
    {
        $this->req('POST', '/circuits/db-svc/call', ['success' => false, 'threshold' => 5]);
        $this->req('POST', '/circuits/db-svc/call', ['success' => false, 'threshold' => 5]);

        $body = $this->decode($this->req('GET', '/circuits/db-svc'));
        self::assertSame('closed', $body['state']);
        self::assertSame(2, $body['failure_count']);
    }

    // --- Success resets failure count ---

    public function testSuccessResetsFailureCount(): void
    {
        $this->req('POST', '/circuits/cache-svc/call', ['success' => false, 'threshold' => 5]);
        $this->req('POST', '/circuits/cache-svc/call', ['success' => false, 'threshold' => 5]);
        $this->req('POST', '/circuits/cache-svc/call', ['success' => true,  'threshold' => 5]);

        $body = $this->decode($this->req('GET', '/circuits/cache-svc'));
        self::assertSame('closed', $body['state']);
        self::assertSame(0, $body['failure_count']);
    }

    // --- Open → Half-Open transition ---

    public function testCircuitTransitionsToHalfOpenAfterTimeout(): void
    {
        $now      = '2026-01-01 00:00:00';
        $circuit  = $this->repo->findOrCreate('svc-a', 1, $now);
        $this->repo->recordFailure('svc-a', $now, 10);
        $this->repo->recordFailure('svc-a', $now, 10);

        // Fast-forward 11 seconds past open_until
        $future = '2026-01-01 00:00:11';
        $result = $this->repo->maybeTransitionToHalfOpen('svc-a', $future);

        self::assertSame(CircuitState::HalfOpen, $result->state);
    }

    public function testOpenCircuitDoesNotTransitionBeforeTimeout(): void
    {
        $now = '2026-01-01 00:00:00';
        $this->repo->findOrCreate('svc-b', 1, $now);
        $this->repo->recordFailure('svc-b', $now, 30);
        $this->repo->recordFailure('svc-b', $now, 30);

        // Only 5 seconds later — should still be Open
        $soon   = '2026-01-01 00:00:05';
        $result = $this->repo->maybeTransitionToHalfOpen('svc-b', $soon);

        self::assertSame(CircuitState::Open, $result->state);
    }

    // --- Half-Open → Closed on success ---

    public function testHalfOpenSuccessClosesCircuit(): void
    {
        $now = '2026-01-01 00:00:00';
        $this->repo->findOrCreate('svc-c', 1, $now);
        $this->repo->recordFailure('svc-c', $now, 10);
        $this->repo->recordFailure('svc-c', $now, 10);

        // Transition to half-open
        $future = '2026-01-01 00:00:11';
        $this->repo->maybeTransitionToHalfOpen('svc-c', $future);

        // Success in half-open → should close
        $result = $this->repo->recordSuccess('svc-c', $future);

        self::assertSame(CircuitState::Closed, $result->state);
        self::assertSame(0, $result->failureCount);
    }

    // --- Half-Open → Open on failure ---

    public function testHalfOpenFailureReopensCircuit(): void
    {
        $now = '2026-01-01 00:00:00';
        $this->repo->findOrCreate('svc-d', 1, $now);
        $this->repo->recordFailure('svc-d', $now, 10);
        $this->repo->recordFailure('svc-d', $now, 10);

        // Transition to half-open
        $future = '2026-01-01 00:00:11';
        $this->repo->maybeTransitionToHalfOpen('svc-d', $future);

        // Failure in half-open → should re-open
        $result = $this->repo->recordFailure('svc-d', $future, 10);

        self::assertSame(CircuitState::Open, $result->state);
        self::assertNotNull($result->openUntil);
    }

    // --- GET /circuits/{name} ---

    public function testGetCircuitReturns404ForUnknown(): void
    {
        $res = $this->req('GET', '/circuits/unknown-svc');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testGetCircuitShowsState(): void
    {
        $this->req('POST', '/circuits/status-svc/call', ['success' => false, 'threshold' => 3]);
        $res  = $this->req('GET', '/circuits/status-svc');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('closed', $body['state']);
        self::assertSame(1, $body['failure_count']);
        self::assertSame(3, $body['failure_threshold']);
    }

    // --- POST /circuits/{name}/reset ---

    public function testResetClosesOpenCircuit(): void
    {
        // Open it
        $this->req('POST', '/circuits/reset-svc/call', ['success' => false, 'threshold' => 1]);
        $this->req('POST', '/circuits/reset-svc/call', ['success' => false, 'threshold' => 1]);

        // Reset
        $res  = $this->req('POST', '/circuits/reset-svc/reset', (object) []);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('closed', $body['state']);
        self::assertSame(0, $body['failure_count']);
    }

    // --- Open circuit includes open_until in response ---

    public function testOpenCircuitResponseIncludesOpenUntil(): void
    {
        $this->req('POST', '/circuits/time-svc/call', ['success' => false, 'threshold' => 1]);
        $res  = $this->req('POST', '/circuits/time-svc/call', ['success' => false, 'threshold' => 1]);
        $body = $this->decode($res);

        self::assertSame('open', $body['circuit']['state']);
        self::assertNotNull($body['circuit']['open_until']);
    }

    public function testLastFailureAtIsRecordedOnFailure(): void
    {
        $res  = $this->req('POST', '/circuits/fail-svc/call', ['success' => false, 'threshold' => 5]);
        $body = $this->decode($res);

        self::assertNotNull($body['circuit']['last_failure_at']);
    }

    // --- Multiple independent circuits ---

    public function testCircuitsAreIsolatedByName(): void
    {
        // Trip svc-x but not svc-y
        $this->req('POST', '/circuits/svc-x/call', ['success' => false, 'threshold' => 1]);
        $this->req('POST', '/circuits/svc-x/call', ['success' => false, 'threshold' => 1]);
        $this->req('POST', '/circuits/svc-y/call', ['success' => true,  'threshold' => 1]);

        $x = $this->decode($this->req('GET', '/circuits/svc-x'));
        $y = $this->decode($this->req('GET', '/circuits/svc-y'));

        self::assertSame('open', $x['state']);
        self::assertSame('closed', $y['state']);
    }
}
