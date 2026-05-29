<?php

declare(strict_types=1);

namespace OneTimeLog\Tests\Secret;

use Nyholm\Psr7\Factory\Psr17Factory;
use OneTimeLog\AppFactory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class SecretTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $pdo->exec($schema);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    private function req(string $method, string $path, array $headers = [], mixed $body = null, array $query = []): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($query !== []) {
            $request = $request->withQueryParams($query);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        assert(is_array($decoded));
        return $decoded;
    }

    /** @param array<string, mixed> $body */
    private function makeSecret(array $body, string $user = '1'): string
    {
        $res = $this->req('POST', '/secrets', ['X-User-Id' => $user], $body);
        assert($res->getStatusCode() === 201);
        return (string) $this->json($res)['token'];
    }

    // ── create ────────────────────────────────────────────────────────────

    public function testCreateRequiresUser(): void
    {
        $this->assertSame(401, $this->req('POST', '/secrets', [], ['message' => 'x'])->getStatusCode());
    }

    public function testTokenIs64Hex(): void
    {
        $token = $this->makeSecret(['message' => 'top secret']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testNonStringMessageRejected(): void // ATK-07
    {
        $this->assertSame(422, $this->req('POST', '/secrets', ['X-User-Id' => '1'], ['message' => 12345])->getStatusCode());
    }

    public function testConsumedNotMassAssignable(): void // ATK-03
    {
        $token = $this->makeSecret(['message' => 'hi', 'consumed' => 1]);
        // body consumed=1 ignored → still readable once
        $this->assertSame(200, $this->req('GET', '/secrets/' . $token)->getStatusCode());
    }

    // ── one-time read ───────────────────────────────────────────────────────

    public function testReadOnceThenGone(): void // ATK-11 (single-process)
    {
        $token = $this->makeSecret(['message' => 'visible once']);
        $first = $this->req('GET', '/secrets/' . $token);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('visible once', $this->json($first)['message']);
        // second read → consumed → 404
        $this->assertSame(404, $this->req('GET', '/secrets/' . $token)->getStatusCode());
    }

    public function testMalformedTokenIs404(): void // ATK-05
    {
        foreach (['../../etc', 'ABC', 'short', str_repeat('z', 64)] as $bad) {
            $this->assertSame(404, $this->req('GET', '/secrets/' . $bad)->getStatusCode());
        }
    }

    // ── password ────────────────────────────────────────────────────────────

    public function testPasswordProtected(): void
    {
        $token = $this->makeSecret(['message' => 'guarded', 'password' => 'hunter2']);
        // no password → 404
        $this->assertSame(404, $this->req('GET', '/secrets/' . $token)->getStatusCode());
        // wrong password → 404
        $this->assertSame(404, $this->req('GET', '/secrets/' . $token, [], null, ['password' => 'nope'])->getStatusCode());
        // correct → 200
        $res = $this->req('GET', '/secrets/' . $token, [], null, ['password' => 'hunter2']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('guarded', $this->json($res)['message']);
    }

    public function testWrongPasswordDoesNotConsume(): void
    {
        $token = $this->makeSecret(['message' => 'guarded', 'password' => 'pw']);
        $this->req('GET', '/secrets/' . $token, [], null, ['password' => 'wrong']); // failed attempt
        // still readable with correct password (not consumed by the failed attempt)
        $this->assertSame(200, $this->req('GET', '/secrets/' . $token, [], null, ['password' => 'pw'])->getStatusCode());
    }

    // ── expiry ────────────────────────────────────────────────────────────────

    public function testExpiredSecretIs404(): void
    {
        $past = (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM);
        $token = $this->makeSecret(['message' => 'old', 'expires_at' => $past]);
        $this->assertSame(404, $this->req('GET', '/secrets/' . $token)->getStatusCode());
    }

    public function testInvalidExpiryRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/secrets', ['X-User-Id' => '1'], ['message' => 'x', 'expires_at' => 'not-a-date'])->getStatusCode());
    }

    // ── IDOR cancel ───────────────────────────────────────────────────────────

    public function testCancelOwn(): void
    {
        $token = $this->makeSecret(['message' => 'x'], '1');
        $this->assertSame(204, $this->req('DELETE', '/secrets/' . $token, ['X-User-Id' => '1'])->getStatusCode());
        $this->assertSame(404, $this->req('GET', '/secrets/' . $token)->getStatusCode());
    }

    public function testCrossUserCancelIs404(): void // ATK-02
    {
        $token = $this->makeSecret(['message' => 'x'], '1');
        $this->assertSame(404, $this->req('DELETE', '/secrets/' . $token, ['X-User-Id' => '99'])->getStatusCode());
        // survived → still consumable by anyone with the token
        $this->assertSame(200, $this->req('GET', '/secrets/' . $token)->getStatusCode());
    }

    // ── list metadata only ──────────────────────────────────────────────────────

    public function testListOmitsMessage(): void
    {
        $this->makeSecret(['message' => 'hidden'], '1');
        $data = $this->json($this->req('GET', '/secrets', ['X-User-Id' => '1']));
        $this->assertSame(1, $data['count']);
        $this->assertArrayNotHasKey('message', $data['secrets'][0]);
    }
}
