<?php

declare(strict_types=1);

namespace Token\Tests\Token;

use Token\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TokenTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/tokenlog-' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $res = $this->request('POST', '/users', ['name' => $name]);
        $this->assertSame(201, $res->getStatusCode());

        return (int) $this->json($res)['id'];
    }

    private function issueToken(int $userId, string $scope = 'read', string $label = ''): string
    {
        $res = $this->request('POST', "/users/{$userId}/tokens", [
            'scope' => $scope,
            'label' => $label,
        ], actorId: (string) $userId);
        $this->assertSame(201, $res->getStatusCode());

        return (string) $this->json($res)['token'];
    }

    // --- User creation ---

    public function testCreateUser(): void
    {
        $res  = $this->request('POST', '/users', ['name' => 'Alice']);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Alice', $body['name']);
    }

    // --- Issue token ---

    public function testIssueToken(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', "/users/{$alice}/tokens", [
            'scope' => 'read',
            'label' => 'My API key',
        ], actorId: (string) $alice);
        $body  = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('read', $body['scope']);
        $this->assertSame('My API key', $body['label']);
        $this->assertIsString($body['token']);
        $this->assertSame(64, strlen($body['token'])); // 32 bytes hex-encoded
    }

    public function testIssueTokenWithWriteScope(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', "/users/{$alice}/tokens", ['scope' => 'write'], actorId: (string) $alice);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('write', $this->json($res)['scope']);
    }

    public function testIssueTokenInvalidScopeReturns422(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', "/users/{$alice}/tokens", ['scope' => 'superuser'], actorId: (string) $alice);

        $this->assertSame(422, $res->getStatusCode());
    }

    public function testIssueTokenOtherUserReturns403(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $res = $this->request('POST', "/users/{$alice}/tokens", ['scope' => 'read'], actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testIssueTokenUnknownUserReturns404(): void
    {
        $res = $this->request('POST', '/users/9999/tokens', ['scope' => 'read'], actorId: '9999');
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- List tokens ---

    public function testListTokens(): void
    {
        $alice = $this->createUser('Alice');
        $this->issueToken($alice, 'read', 'key1');
        $this->issueToken($alice, 'write', 'key2');

        $res  = $this->request('GET', "/users/{$alice}/tokens", actorId: (string) $alice);
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(2, $body['items']);
        $this->assertSame(2, $body['count']);
        // Tokens do NOT include the raw token value
        $this->assertArrayNotHasKey('token', $body['items'][0]);
    }

    public function testListTokensOtherUserReturns403(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $res = $this->request('GET', "/users/{$alice}/tokens", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    // --- Revoke token ---

    public function testRevokeToken(): void
    {
        $alice   = $this->createUser('Alice');
        $this->issueToken($alice);

        $tokens  = $this->json($this->request('GET', "/users/{$alice}/tokens", actorId: (string) $alice));
        $tokenId = $tokens['items'][0]['id'];

        $res = $this->request('DELETE', "/users/{$alice}/tokens/{$tokenId}", actorId: (string) $alice);
        $this->assertSame(204, $res->getStatusCode());
    }

    public function testRevokeTokenAppearsAsRevokedInList(): void
    {
        $alice   = $this->createUser('Alice');
        $this->issueToken($alice, 'read', 'test key');

        $tokens  = $this->json($this->request('GET', "/users/{$alice}/tokens", actorId: (string) $alice));
        $tokenId = $tokens['items'][0]['id'];

        $this->request('DELETE', "/users/{$alice}/tokens/{$tokenId}", actorId: (string) $alice);

        $updated = $this->json($this->request('GET', "/users/{$alice}/tokens", actorId: (string) $alice));
        $this->assertTrue($updated['items'][0]['revoked']);
    }

    public function testRevokeAlreadyRevokedReturns409(): void
    {
        $alice   = $this->createUser('Alice');
        $this->issueToken($alice);

        $tokens  = $this->json($this->request('GET', "/users/{$alice}/tokens", actorId: (string) $alice));
        $tokenId = $tokens['items'][0]['id'];

        $this->request('DELETE', "/users/{$alice}/tokens/{$tokenId}", actorId: (string) $alice);
        $res = $this->request('DELETE', "/users/{$alice}/tokens/{$tokenId}", actorId: (string) $alice);

        $this->assertSame(409, $res->getStatusCode());
    }

    public function testRevokeOtherUserTokenReturns403(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $this->issueToken($alice);

        $tokens  = $this->json($this->request('GET', "/users/{$alice}/tokens", actorId: (string) $alice));
        $tokenId = $tokens['items'][0]['id'];

        // Bob tries to revoke Alice's token
        $res = $this->request('DELETE', "/users/{$alice}/tokens/{$tokenId}", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    // --- Verify token ---

    public function testVerifyValidToken(): void
    {
        $alice = $this->createUser('Alice');
        $token = $this->issueToken($alice, 'write');

        $res  = $this->request('POST', '/tokens/verify', ['token' => $token]);
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($body['valid']);
        $this->assertSame($alice, $body['user_id']);
        $this->assertSame('write', $body['scope']);
    }

    public function testVerifyInvalidTokenReturnsFalse(): void
    {
        $res  = $this->request('POST', '/tokens/verify', ['token' => str_repeat('a', 64)]);
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($body['valid']);
    }

    public function testVerifyRevokedTokenReturnsFalse(): void
    {
        $alice   = $this->createUser('Alice');
        $token   = $this->issueToken($alice);
        $tokens  = $this->json($this->request('GET', "/users/{$alice}/tokens", actorId: (string) $alice));
        $tokenId = $tokens['items'][0]['id'];

        $this->request('DELETE', "/users/{$alice}/tokens/{$tokenId}", actorId: (string) $alice);

        $res  = $this->request('POST', '/tokens/verify', ['token' => $token]);
        $body = $this->json($res);

        $this->assertFalse($body['valid']);
    }

    public function testVerifyEmptyTokenReturns422(): void
    {
        $res = $this->request('POST', '/tokens/verify', ['token' => '']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // --- Multiple tokens per user ---

    public function testMultipleTokensPerUser(): void
    {
        $alice = $this->createUser('Alice');
        $this->issueToken($alice, 'read', 'CI token');
        $this->issueToken($alice, 'write', 'Deploy token');
        $this->issueToken($alice, 'admin', 'Admin token');

        $tokens = $this->json($this->request('GET', "/users/{$alice}/tokens", actorId: (string) $alice));
        $this->assertCount(3, $tokens['items']);
    }
}
