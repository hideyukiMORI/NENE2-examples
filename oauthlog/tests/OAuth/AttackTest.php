<?php

declare(strict_types=1);

namespace OAuthLog\Tests\OAuth;

use OAuthLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * クラッカー攻撃試験 ATK-01〜12 (FT160)
 *
 * 攻撃者視点でOAuth2フローの脆弱性を突く12テスト。
 */
final class AttackTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/oauthlog_atk_' . uniqid() . '.sqlite';
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

    private function req(
        string $method,
        string $path,
        mixed $body = null,
        string $bearerToken = '',
    ): ResponseInterface {
        $psr17 = new Psr17Factory();
        $uri = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        if ($bearerToken !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true);
    }

    private function doLogin(string $code): string
    {
        $state = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $data  = $this->json($this->req('POST', '/auth/oauth/callback', [
            'state' => $state,
            'code'  => $code,
        ]));
        return (string) $data['token'];
    }

    // ATK-01: CSRF — callback with no state parameter
    public function testAtk01CsrfMissingState(): void
    {
        $res = $this->req('POST', '/auth/oauth/callback', ['code' => 'code_atk01']);
        $this->assertSame(422, $res->getStatusCode(), 'ATK-01: missing state must be rejected');
    }

    // ATK-02: CSRF — forged/unknown state value
    public function testAtk02CsrfForgedState(): void
    {
        $res = $this->req('POST', '/auth/oauth/callback', [
            'state' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'code'  => 'code_atk02',
        ]);
        $this->assertSame(400, $res->getStatusCode(), 'ATK-02: unknown state must be rejected');
    }

    // ATK-03: Expired state (simulate by consuming and then re-using)
    // We use state reuse test here; expired-state timing is ATK-03 variant
    public function testAtk03ExpiredState(): void
    {
        // Create a state and use it legitimately
        $state = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $this->req('POST', '/auth/oauth/callback', ['state' => $state, 'code' => 'code_atk03a']);

        // Attacker tries to replay the same state with a fresh code
        $res = $this->req('POST', '/auth/oauth/callback', [
            'state' => $state,
            'code'  => 'code_atk03b',
        ]);
        $this->assertSame(400, $res->getStatusCode(), 'ATK-03: used state must be rejected');
    }

    // ATK-04: State reuse — attacker intercepts and reuses a valid state
    public function testAtk04StateReuse(): void
    {
        $state = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];

        // First use succeeds
        $res1 = $this->req('POST', '/auth/oauth/callback', ['state' => $state, 'code' => 'code_atk04a']);
        $this->assertSame(200, $res1->getStatusCode());

        // Second use of same state must fail
        $res2 = $this->req('POST', '/auth/oauth/callback', ['state' => $state, 'code' => 'code_atk04b']);
        $this->assertSame(400, $res2->getStatusCode(), 'ATK-04: state must not be reusable');
    }

    // ATK-05: Code replay — same authorization code used twice
    public function testAtk05CodeReplay(): void
    {
        $state1 = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $res1 = $this->req('POST', '/auth/oauth/callback', ['state' => $state1, 'code' => 'code_atk05']);
        $this->assertSame(200, $res1->getStatusCode());

        // New state, same code
        $state2 = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $res2 = $this->req('POST', '/auth/oauth/callback', ['state' => $state2, 'code' => 'code_atk05']);
        $this->assertSame(400, $res2->getStatusCode(), 'ATK-05: replayed code must be rejected');
    }

    // ATK-06: Invalid code — provider rejects it
    public function testAtk06InvalidCode(): void
    {
        $state = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $res = $this->req('POST', '/auth/oauth/callback', [
            'state' => $state,
            'code'  => 'invalid_code',
        ]);
        $this->assertSame(401, $res->getStatusCode(), 'ATK-06: invalid code must return 401');
    }

    // ATK-07: Open redirect prevention — start does not accept redirect_uri
    // The /auth/oauth/start endpoint ignores any redirect_uri in the body;
    // attacker cannot inject an arbitrary redirect.
    public function testAtk07OpenRedirectPrevention(): void
    {
        $res = $this->req('POST', '/auth/oauth/start', [
            'redirect_uri' => 'https://evil.example.com/steal',
        ]);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $authUrl = (string) $data['auth_url'];
        $this->assertStringNotContainsString('evil.example.com', $authUrl, 'ATK-07: redirect_uri injection must not affect auth_url');
    }

    // ATK-08: Session reuse after logout
    public function testAtk08SessionReuseAfterLogout(): void
    {
        $token = $this->doLogin('code_atk08');

        // Legitimate logout
        $this->req('POST', '/auth/logout', bearerToken: $token);

        // Attacker tries to use the revoked token
        $res = $this->req('GET', '/me', bearerToken: $token);
        $this->assertSame(401, $res->getStatusCode(), 'ATK-08: revoked token must be rejected');
    }

    // ATK-09: Invalid/malformed session token
    public function testAtk09InvalidToken(): void
    {
        $res = $this->req('GET', '/me', bearerToken: 'not-a-valid-token');
        $this->assertSame(401, $res->getStatusCode(), 'ATK-09: invalid token must be rejected');
    }

    // ATK-10: Accessing /me without any auth header
    public function testAtk10NoAuthHeader(): void
    {
        $res = $this->req('GET', '/me');
        $this->assertSame(401, $res->getStatusCode(), 'ATK-10: unauthenticated /me must return 401');
    }

    // ATK-11: SQL injection in state parameter
    public function testAtk11SqlInjectionInState(): void
    {
        $maliciousState = "' OR '1'='1";
        $res = $this->req('POST', '/auth/oauth/callback', [
            'state' => $maliciousState,
            'code'  => 'code_atk11',
        ]);
        // Must not succeed (400 or 422)
        $this->assertContains(
            $res->getStatusCode(),
            [400, 422],
            'ATK-11: SQL injection in state must not succeed',
        );
    }

    // ATK-12: Cross-user session — user A's token cannot access as user B
    public function testAtk12CrossUserSession(): void
    {
        $tokenA = $this->doLogin('code_atk12_alice');
        $tokenB = $this->doLogin('code_atk12_bob');

        $meA = $this->json($this->req('GET', '/me', bearerToken: $tokenA));
        $meB = $this->json($this->req('GET', '/me', bearerToken: $tokenB));

        $this->assertNotSame($meA['id'], $meB['id'], 'ATK-12: different users must have different sessions');
        $this->assertSame('User-code_atk12_alice', $meA['name']);
        $this->assertSame('User-code_atk12_bob', $meB['name']);
    }
}
