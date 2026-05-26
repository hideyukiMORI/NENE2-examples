<?php

declare(strict_types=1);

namespace Throttle\Tests\Notes;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throttle\Notes\NoteRepository;
use Throttle\Notes\RouteRegistrar;

final class ThrottleTest extends TestCase
{
    private string $dbFile = '';

    /** Build a fresh app with the given throttle middleware. */
    private function buildApp(ThrottleMiddleware $throttle): RequestHandlerInterface
    {
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        unset($pdo);

        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $this->dbFile,
            user: '',
            password: '',
            charset: '',
        );

        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar(new NoteRepository($executor), $json, $problems);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            throttleMiddleware: $throttle,
            routeRegistrars: [static fn(Router $r) => $registrar->register($r)],
        ))->create();
    }

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/throttlelog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    // --- helpers ---

    private function get(RequestHandlerInterface $app, string $path, string $ip = '127.0.0.1'): ResponseInterface
    {
        $request = new ServerRequest('GET', $path, [], null, '1.1', ['REMOTE_ADDR' => $ip]);

        return $app->handle($request);
    }

    /** @param array<string, mixed> $body */
    private function post(RequestHandlerInterface $app, string $path, array $body, string $ip = '127.0.0.1'): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body, JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('POST', $path, ['Content-Type' => 'application/json'], $stream, '1.1', ['REMOTE_ADDR' => $ip]));

        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($data);

        return $data;
    }

    // --- tests ---

    public function testResponseIncludesRateLimitHeaders(): void
    {
        $storage  = new InMemoryRateLimitStorage();
        $psr17    = new Psr17Factory();
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $throttle = new ThrottleMiddleware($problems, $storage, limit: 10, windowSeconds: 60);
        $app      = $this->buildApp($throttle);

        $res = $this->get($app, '/notes');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('10', $res->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('9', $res->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertNotEmpty($res->getHeaderLine('X-RateLimit-Reset'));
    }

    public function testRemainingDecrementsPerRequest(): void
    {
        $storage  = new InMemoryRateLimitStorage();
        $psr17    = new Psr17Factory();
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $throttle = new ThrottleMiddleware($problems, $storage, limit: 5, windowSeconds: 60);
        $app      = $this->buildApp($throttle);

        for ($i = 5; $i >= 1; $i--) {
            $res = $this->get($app, '/notes');
            $this->assertSame(200, $res->getStatusCode());
            $this->assertSame((string) ($i - 1), $res->getHeaderLine('X-RateLimit-Remaining'));
        }
    }

    public function testReturns429WhenLimitExceeded(): void
    {
        $storage  = new InMemoryRateLimitStorage();
        $psr17    = new Psr17Factory();
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $throttle = new ThrottleMiddleware($problems, $storage, limit: 3, windowSeconds: 60);
        $app      = $this->buildApp($throttle);

        // Exhaust the limit
        for ($i = 0; $i < 3; $i++) {
            $this->get($app, '/notes');
        }

        $res = $this->get($app, '/notes');
        $this->assertSame(429, $res->getStatusCode());
        $this->assertNotEmpty($res->getHeaderLine('Retry-After'));
        $this->assertSame('0', $res->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function test429ResponseIsValidProblemDetails(): void
    {
        $storage  = new InMemoryRateLimitStorage();
        $psr17    = new Psr17Factory();
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $throttle = new ThrottleMiddleware($problems, $storage, limit: 1, windowSeconds: 60);
        $app      = $this->buildApp($throttle);

        $this->get($app, '/notes');
        $res = $this->get($app, '/notes');

        $this->assertSame(429, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('too-many-requests', (string) $data['type']);
        $this->assertSame(429, $data['status']);
    }

    public function testDifferentIpsHaveSeparateBuckets(): void
    {
        $storage  = new InMemoryRateLimitStorage();
        $psr17    = new Psr17Factory();
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $throttle = new ThrottleMiddleware($problems, $storage, limit: 2, windowSeconds: 60);
        $app      = $this->buildApp($throttle);

        // Exhaust limit for IP A
        $this->get($app, '/notes', '10.0.0.1');
        $this->get($app, '/notes', '10.0.0.1');
        $blockedRes = $this->get($app, '/notes', '10.0.0.1');
        $this->assertSame(429, $blockedRes->getStatusCode());

        // IP B is unaffected
        $okRes = $this->get($app, '/notes', '10.0.0.2');
        $this->assertSame(200, $okRes->getStatusCode());
        $this->assertSame('1', $okRes->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function testCustomKeyExtractorByApiKey(): void
    {
        $psr17    = new Psr17Factory();
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $storage  = new InMemoryRateLimitStorage();
        $throttle = new ThrottleMiddleware(
            $problems,
            $storage,
            limit: 2,
            windowSeconds: 60,
            keyExtractor: static fn($r): string => 'apikey:' . ($r->getHeaderLine('X-Api-Key') ?: 'anonymous'),
        );
        $app = $this->buildApp($throttle);

        // Key "alice" exhausts its own bucket
        $makeRequest = static function (string $apiKey) use ($app): ResponseInterface {
            $request = new ServerRequest('GET', '/notes', ['X-Api-Key' => $apiKey], null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']);

            return $app->handle($request);
        };

        $makeRequest('alice');
        $makeRequest('alice');
        $this->assertSame(429, $makeRequest('alice')->getStatusCode());

        // "bob" is in a separate bucket — unaffected
        $this->assertSame(200, $makeRequest('bob')->getStatusCode());
    }

    public function testRateLimitAppliesToAllHttpMethods(): void
    {
        $storage  = new InMemoryRateLimitStorage();
        $psr17    = new Psr17Factory();
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $throttle = new ThrottleMiddleware($problems, $storage, limit: 2, windowSeconds: 60);
        $app      = $this->buildApp($throttle);

        // First request: GET
        $this->get($app, '/notes');
        // Second request: POST
        $this->post($app, '/notes', ['content' => 'hello']);
        // Third request: GET — over limit
        $res = $this->get($app, '/notes');
        $this->assertSame(429, $res->getStatusCode());
    }

    public function testXRateLimitResetIsUnixTimestamp(): void
    {
        $storage  = new InMemoryRateLimitStorage();
        $psr17    = new Psr17Factory();
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $throttle = new ThrottleMiddleware($problems, $storage, limit: 10, windowSeconds: 60);
        $app      = $this->buildApp($throttle);

        $before = time();
        $res    = $this->get($app, '/notes');
        $after  = time();

        $reset = (int) $res->getHeaderLine('X-RateLimit-Reset');
        $this->assertGreaterThanOrEqual($before + 60, $reset);
        $this->assertLessThanOrEqual($after + 60, $reset);
    }

    public function testNormalRequestSucceedsAfterThrottle(): void
    {
        // Verifies that the API itself still works under the rate limit
        $storage  = new InMemoryRateLimitStorage();
        $psr17    = new Psr17Factory();
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $throttle = new ThrottleMiddleware($problems, $storage, limit: 10, windowSeconds: 60);
        $app      = $this->buildApp($throttle);

        $createRes = $this->post($app, '/notes', ['content' => 'test note']);
        $this->assertSame(201, $createRes->getStatusCode());

        $listRes = $this->get($app, '/notes');
        $this->assertSame(200, $listRes->getStatusCode());
    }
}
