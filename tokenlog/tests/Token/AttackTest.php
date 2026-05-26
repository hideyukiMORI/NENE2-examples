<?php

declare(strict_types=1);

namespace Token\Tests\Token;

use Token\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * FT136 Cracker Attack Test
 *
 * Adversarial inputs — cracker mindset. Each test sends a crafted
 * request that a real attacker might attempt.
 */
final class AttackTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/tokenlog-attack-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $this->app = AppFactory::createSqliteApp($this->dbFile);
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function request(string $method, string $path, mixed $body = null, string $actorId = ''): ResponseInterface
    {
        $req = new ServerRequest($method, $path);

        if ($body !== null) {
            $req = $req
                ->withBody(Stream::create((string) json_encode($body)))
                ->withHeader('Content-Type', 'application/json');
        }

        if ($actorId !== '') {
            $req = $req->withHeader('X-User-Id', $actorId);
        }

        return $this->app->handle($req);
    }

    private function json(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function createUser(string $name): int
    {
        $req = new ServerRequest('POST', '/users');
        $req = $req->withBody(Stream::create((string) json_encode(['name' => $name])))
                   ->withHeader('Content-Type', 'application/json');

        return (int) $this->json($this->app->handle($req))['id'];
    }

    private function issueToken(int $userId, string $scope = 'read'): string
    {
        $res = $this->request('POST', "/users/{$userId}/tokens", ['scope' => $scope], actorId: (string) $userId);

        return (string) $this->json($res)['token'];
    }

    // ATK-01: IDOR — issue token on behalf of another user
    public function testAtk01_IdorIssueToken(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $res = $this->request('POST', "/users/{$alice}/tokens", ['scope' => 'admin'], actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode(), 'ATK-01: must not issue token for another user');
    }

    // ATK-02: IDOR — list another user's tokens
    public function testAtk02_IdorListTokens(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $this->issueToken($alice, 'admin');

        $res = $this->request('GET', "/users/{$alice}/tokens", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode(), 'ATK-02: must not list another user\'s tokens');
    }

    // ATK-03: IDOR — revoke another user's token (cross-user revoke)
    public function testAtk03_IdorRevokeToken(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $this->issueToken($alice);
        $tokens  = $this->json($this->request('GET', "/users/{$alice}/tokens", actorId: (string) $alice));
        $tokenId = $tokens['items'][0]['id'];

        // Bob tries to revoke Alice's token via Alice's user path
        $res = $this->request('DELETE', "/users/{$alice}/tokens/{$tokenId}", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode(), 'ATK-03: cross-user token revoke must be rejected');
    }

    // ATK-04: IDOR — Bob tries to revoke Alice's token via Bob's own path
    public function testAtk04_IdorRevokeTokenViaSelfPath(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $this->issueToken($alice);
        $tokens  = $this->json($this->request('GET', "/users/{$alice}/tokens", actorId: (string) $alice));
        $tokenId = $tokens['items'][0]['id'];

        // Bob tries to revoke Alice's token by using his own userId in the path
        $res = $this->request('DELETE', "/users/{$bob}/tokens/{$tokenId}", actorId: (string) $bob);
        // The token doesn't belong to Bob → 403 (ownership check)
        $this->assertSame(403, $res->getStatusCode(), 'ATK-04: token owned by another user must be rejected even via own path');
    }

    // ATK-05: Scope escalation — use non-existent scope
    public function testAtk05_ScopeEscalation(): void
    {
        $alice = $this->createUser('Alice');

        $res = $this->request('POST', "/users/{$alice}/tokens", ['scope' => 'superuser'], actorId: (string) $alice);
        $this->assertSame(422, $res->getStatusCode(), 'ATK-05: invalid scope must be rejected');
    }

    // ATK-06: Use a revoked token for verify
    public function testAtk06_RevokedTokenVerifyReturnsFalse(): void
    {
        $alice   = $this->createUser('Alice');
        $token   = $this->issueToken($alice, 'admin');
        $tokens  = $this->json($this->request('GET', "/users/{$alice}/tokens", actorId: (string) $alice));
        $tokenId = $tokens['items'][0]['id'];

        $this->request('DELETE', "/users/{$alice}/tokens/{$tokenId}", actorId: (string) $alice);

        $res  = $this->request('POST', '/tokens/verify', ['token' => $token]);
        $body = $this->json($res);

        $this->assertFalse($body['valid'], 'ATK-06: revoked token must not verify as valid');
    }

    // ATK-07: Brute-force guess — random token of correct length
    public function testAtk07_RandomTokenNotValid(): void
    {
        $alice = $this->createUser('Alice');
        $this->issueToken($alice);

        // Random 64-char hex string — not a real token
        $guessed = bin2hex(random_bytes(32));
        $res     = $this->request('POST', '/tokens/verify', ['token' => $guessed]);
        $body    = $this->json($res);

        $this->assertFalse($body['valid'], 'ATK-07: randomly guessed token must not verify');
    }

    // ATK-08: SQL injection in token verify body
    public function testAtk08_SqlInjectionInVerify(): void
    {
        $alice = $this->createUser('Alice');
        $this->issueToken($alice);

        $payload = "' OR '1'='1";
        $res     = $this->request('POST', '/tokens/verify', ['token' => $payload]);
        $body    = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($body['valid'], 'ATK-08: SQL injection in token verify must not return valid');
    }

    // ATK-09: Non-numeric X-User-Id header
    public function testAtk09_NonNumericActorHeader(): void
    {
        $alice = $this->createUser('Alice');

        $res = $this->request('POST', "/users/{$alice}/tokens", ['scope' => 'read'], actorId: 'admin');
        $this->assertNotSame(201, $res->getStatusCode(), 'ATK-09: non-numeric actor header must be rejected');
    }

    // ATK-10: Negative user ID
    public function testAtk10_NegativeUserId(): void
    {
        $res = $this->request('POST', '/users/-1/tokens', ['scope' => 'read'], actorId: '-1');
        $this->assertSame(404, $res->getStatusCode(), 'ATK-10: negative user ID must return 404');
    }

    // ATK-11: Very long scope string
    public function testAtk11_LongScopeString(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', "/users/{$alice}/tokens", [
            'scope' => str_repeat('x', 10_000),
        ], actorId: (string) $alice);

        $this->assertSame(422, $res->getStatusCode(), 'ATK-11: excessively long scope must be rejected');
    }

    // ATK-12: Verify empty string token
    public function testAtk12_EmptyTokenVerify(): void
    {
        $res = $this->request('POST', '/tokens/verify', ['token' => '   ']);
        $this->assertSame(422, $res->getStatusCode(), 'ATK-12: empty/whitespace-only token must be rejected');
    }
}
