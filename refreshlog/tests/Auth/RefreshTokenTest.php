<?php

declare(strict_types=1);

namespace Refresh\Tests\Auth;

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
use Refresh\Auth\RefreshTokenRepository;
use Refresh\Auth\RouteRegistrar;
use Refresh\Auth\UserRepository;

final class RefreshTokenTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile = '';
    private const string SECRET = 'test-secret-for-refresh-token-ft113';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/refreshlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $factory       = new PdoConnectionFactory($dbConfig);
        $executor      = new PdoDatabaseQueryExecutor($factory);
        $psr17         = new Psr17Factory();
        $json          = new JsonResponseFactory($psr17, $psr17);
        $problems      = new ProblemDetailsResponseFactory($psr17, $psr17);
        $verifier      = new LocalBearerTokenVerifier(self::SECRET);
        $users         = new UserRepository($executor);
        $refreshTokens = new RefreshTokenRepository($executor);
        $registrar     = new RouteRegistrar($users, $refreshTokens, $verifier, $json, $problems);

        $authMiddleware = new BearerTokenMiddleware(
            problemDetails: $problems,
            verifier: $verifier,
            excludedPaths: ['/auth/login', '/auth/refresh', '/auth/logout'],
        );

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            authMiddleware: $authMiddleware,
            routeRegistrars: [static fn(Router $r) => $registrar->register($r)],
        ))->create();

        // Seed one user
        $users->create('alice@example.com', password_hash('password', PASSWORD_ARGON2ID));
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

    /** @return array{access_token: string, refresh_token: string} */
    private function login(): array
    {
        $res  = $this->post('/auth/login', ['email' => 'alice@example.com', 'password' => 'password']);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertIsString($data['access_token']);
        $this->assertIsString($data['refresh_token']);

        return ['access_token' => (string) $data['access_token'], 'refresh_token' => (string) $data['refresh_token']];
    }

    // --- login ---

    public function testLoginReturnsAccessAndRefreshTokens(): void
    {
        $res  = $this->post('/auth/login', ['email' => 'alice@example.com', 'password' => 'password']);
        $data = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertArrayHasKey('expires_in', $data);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame(300, $data['expires_in']); // 5 minutes
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $res = $this->post('/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testLoginWithUnknownEmailReturns401(): void
    {
        $res = $this->post('/auth/login', ['email' => 'unknown@example.com', 'password' => 'password']);
        $this->assertSame(401, $res->getStatusCode());
    }

    // --- access token usage ---

    public function testAccessTokenGrantsAccessToProtectedEndpoint(): void
    {
        $tokens = $this->login();

        $res = $this->get('/auth/me', $tokens['access_token']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('alice@example.com', $this->json($res)['email']);
    }

    public function testMeRequiresAuth(): void
    {
        $res = $this->get('/auth/me');
        $this->assertSame(401, $res->getStatusCode());
    }

    // --- refresh ---

    public function testRefreshReturnsNewTokenPair(): void
    {
        $tokens = $this->login();

        $res  = $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        $data = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        // New tokens must differ from the old ones
        $this->assertNotSame($tokens['access_token'], $data['access_token']);
        $this->assertNotSame($tokens['refresh_token'], $data['refresh_token']);
    }

    public function testNewAccessTokenFromRefreshGrantsAccess(): void
    {
        $tokens    = $this->login();
        $refreshed = $this->json($this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]));

        $res = $this->get('/auth/me', (string) $refreshed['access_token']);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testRefreshTokenRotation_OldTokenIsInvalidAfterRefresh(): void
    {
        $tokens = $this->login();

        // Use the refresh token once
        $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

        // Attempt to reuse the old refresh token — must fail
        $res = $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testRefreshTokenReuseRevokesAllUserTokens(): void
    {
        $tokens = $this->login();

        // Rotate once — old token is revoked
        $newTokens = $this->json($this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]));

        // Simulate replay: attacker reuses the old (revoked) refresh token
        // This should also revoke the newly issued refresh token (reuse detection)
        $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

        // New refresh token should now also be invalid
        $res = $this->post('/auth/refresh', ['refresh_token' => (string) $newTokens['refresh_token']]);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testInvalidRefreshTokenReturns401(): void
    {
        $res = $this->post('/auth/refresh', ['refresh_token' => 'completely-invalid-token']);
        $this->assertSame(401, $res->getStatusCode());
    }

    // --- logout ---

    public function testLogoutRevokesRefreshToken(): void
    {
        $tokens = $this->login();

        $res = $this->post('/auth/logout', ['refresh_token' => $tokens['refresh_token']]);
        $this->assertSame(204, $res->getStatusCode());

        // Refresh token must no longer work
        $refreshRes = $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        $this->assertSame(401, $refreshRes->getStatusCode());
    }

    public function testLogoutWithInvalidTokenStillReturns204(): void
    {
        // Logout must never leak whether the token was valid
        $res = $this->post('/auth/logout', ['refresh_token' => 'invalid-token']);
        $this->assertSame(204, $res->getStatusCode());
    }

    public function testLogoutTwiceWithSameTokenStillReturns204(): void
    {
        $tokens = $this->login();

        $this->post('/auth/logout', ['refresh_token' => $tokens['refresh_token']]);
        $res = $this->post('/auth/logout', ['refresh_token' => $tokens['refresh_token']]);

        $this->assertSame(204, $res->getStatusCode());
    }

    // --- refresh token is raw value, not stored value ---

    public function testRefreshTokenInResponseIsRawNotHash(): void
    {
        $tokens = $this->login();

        // The raw refresh token must be 64 hex characters (32 random bytes → bin2hex)
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tokens['refresh_token']);
    }

    // --- unauthenticated requests ---

    public function testMissingBodyOnRefreshReturns400(): void
    {
        $stream  = Stream::create('');
        $request = (new ServerRequest('POST', '/auth/refresh'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        $res = $this->app->handle($request);
        $this->assertSame(400, $res->getStatusCode());
    }
}
