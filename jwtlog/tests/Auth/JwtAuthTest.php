<?php

declare(strict_types=1);

namespace Jwt\Tests\Auth;

use Jwt\Auth\RouteRegistrar;
use Jwt\Auth\UserRepository;
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
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

final class JwtAuthTest extends TestCase
{
    private RequestHandlerInterface $app;
    private LocalBearerTokenVerifier $verifier;
    private string $dbFile = '';
    private const string SECRET = 'test-secret-key-for-jwt-field-trial';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/jwtlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
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

        $factory          = new PdoConnectionFactory($dbConfig);
        $executor         = new PdoDatabaseQueryExecutor($factory);
        $psr17            = new Psr17Factory();
        $json             = new JsonResponseFactory($psr17, $psr17);
        $problems         = new ProblemDetailsResponseFactory($psr17, $psr17);
        $this->verifier   = new LocalBearerTokenVerifier(self::SECRET);
        $repo             = new UserRepository($executor);
        $registrar        = new RouteRegistrar($repo, $this->verifier, $json, $problems);

        // BearerTokenMiddleware protects all paths except /auth/login.
        $authMiddleware = new BearerTokenMiddleware(
            problemDetails: $problems,
            verifier: $this->verifier,
            excludedPaths: ['/auth/login'],
        );

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            authMiddleware: $authMiddleware,
            routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
        ))->create();

        // Pre-register a test user
        $hash = password_hash('correct-horse', PASSWORD_ARGON2ID);
        $repo->create('alice@example.com', $hash);
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    // --- helpers ---

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, string $token = ''): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body, JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('POST', $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $this->app->handle($request);
    }

    private function get(string $path, string $token = ''): ResponseInterface
    {
        $request = new ServerRequest('GET', $path);

        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($data);

        return $data;
    }

    private function loginAndGetToken(): string
    {
        $res  = $this->post('/auth/login', ['email' => 'alice@example.com', 'password' => 'correct-horse']);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertIsString($data['token']);

        return (string) $data['token'];
    }

    // --- login tests ---

    public function testLoginWithCorrectCredentialsReturns200(): void
    {
        $res  = $this->post('/auth/login', ['email' => 'alice@example.com', 'password' => 'correct-horse']);
        $data = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('expires_at', $data);
        $this->assertSame('Bearer', $data['token_type']);
    }

    public function testLoginTokenIsValidJwt(): void
    {
        $token  = $this->loginAndGetToken();
        $claims = $this->verifier->verify($token);

        $this->assertSame('alice@example.com', $claims['email']);
        $this->assertIsInt($claims['sub']);
        $this->assertIsInt($claims['iat']);
        $this->assertIsInt($claims['exp']);
        $this->assertGreaterThan(time(), $claims['exp']);
    }

    public function testLoginTokenExpiresInOneHour(): void
    {
        $before = time();
        $token  = $this->loginAndGetToken();
        $claims = $this->verifier->verify($token);

        $this->assertGreaterThanOrEqual($before + 3600, $claims['exp']);
        $this->assertLessThanOrEqual(time() + 3600, $claims['exp']);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $res = $this->post('/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong-password']);

        $this->assertSame(401, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('invalid-credentials', (string) $data['type']);
    }

    public function testLoginWithUnknownEmailReturns401(): void
    {
        $res = $this->post('/auth/login', ['email' => 'nobody@example.com', 'password' => 'any-password']);

        // Must return 401 (not 404) — do not reveal whether the email exists
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testLoginMissingFieldReturns400(): void
    {
        $res = $this->post('/auth/login', ['email' => 'alice@example.com']);
        $this->assertSame(400, $res->getStatusCode());
    }

    // --- protected route tests ---

    public function testMeWithValidTokenReturns200(): void
    {
        $token = $this->loginAndGetToken();
        $res   = $this->get('/auth/me', $token);
        $data  = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('alice@example.com', $data['email']);
        $this->assertArrayHasKey('id', $data);
    }

    public function testMeWithoutTokenReturns401(): void
    {
        $res = $this->get('/auth/me');

        $this->assertSame(401, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('unauthorized', (string) $data['type']);
        // WWW-Authenticate header must be present (RFC 6750)
        $this->assertStringContainsString('Bearer', $res->getHeaderLine('WWW-Authenticate'));
    }

    public function testMeWithInvalidSignatureReturns401(): void
    {
        $token  = $this->loginAndGetToken();
        $parts  = explode('.', $token);
        // Tamper the signature
        $parts[2] = 'invalidsignatureXXXXXXXXXXXXXXXXXXXXXXXXXXX';
        $tampered = implode('.', $parts);

        $res = $this->get('/auth/me', $tampered);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testMeWithExpiredTokenReturns401(): void
    {
        // Issue a token that is already expired (exp = 1 second ago)
        $expiredToken = $this->verifier->issue([
            'sub'   => 1,
            'email' => 'alice@example.com',
            'iat'   => time() - 7200,
            'exp'   => time() - 1,
        ]);

        $res = $this->get('/auth/me', $expiredToken);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testMeWithWrongBearerSchemeReturns401(): void
    {
        $token   = $this->loginAndGetToken();
        $request = (new ServerRequest('GET', '/auth/me'))
            ->withHeader('Authorization', 'Basic ' . $token);

        $res = $this->app->handle($request);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testMeResponseDoesNotContainPasswordHash(): void
    {
        $token = $this->loginAndGetToken();
        $data  = $this->json($this->get('/auth/me', $token));

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('password_hash', $data);
    }

    public function testLoginRouteIsPublic(): void
    {
        // /auth/login must be reachable without a token (it's the token-issuance endpoint)
        $res = $this->post('/auth/login', ['email' => 'alice@example.com', 'password' => 'correct-horse']);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testAlgNoneTokenIsRejected(): void
    {
        // Build a manually crafted token with alg: none — the "none" algorithm attack
        $header  = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'none'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode(['sub' => 1, 'email' => 'alice@example.com', 'exp' => time() + 3600], JSON_THROW_ON_ERROR));
        $algNoneToken = rtrim(strtr($header, '+/', '-_'), '=') . '.' . rtrim(strtr($payload, '+/', '-_'), '=') . '.';

        $res = $this->get('/auth/me', $algNoneToken);
        $this->assertSame(401, $res->getStatusCode());
    }
}
