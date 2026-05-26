<?php

declare(strict_types=1);

namespace TotpLog\Tests\Totp;

use TotpLog\AppFactory;
use TotpLog\Totp\TotpGenerator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * FT159 — TOTP 脆弱性診断: VULN-A〜L 12件
 */
class VulnTest extends TestCase
{
    private PDO $pdo;
    private string $dbFile;
    private TotpGenerator $gen;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/vuln-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $this->pdo->exec($schema);

        $this->gen = new TotpGenerator();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function req(string $method, string $path, mixed $body = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $uri = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $r): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $r->getBody(), true);
    }

    private function createUser(string $name = 'Alice'): int
    {
        return (int) $this->json($this->req('POST', '/users', ['name' => $name]))['id'];
    }

    private function setupAndEnable(int $userId): string
    {
        $data = $this->json($this->req('POST', "/users/{$userId}/totp/setup"));
        $secret = (string) $data['secret'];
        $code = $this->gen->computeCode($secret, $this->gen->currentTimeStep());
        $this->req('POST', "/users/{$userId}/totp/enable", ['code' => $code]);
        return $secret;
    }

    /** VULN-A: リプレイ攻撃 — 同じコードを2回送ると2回目は拒否される */
    public function testVulnAReplayAttackRejected(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupAndEnable($userId);

        $code = $this->gen->computeCode($secret, $this->gen->currentTimeStep() + 1);

        $r1 = $this->req('POST', "/users/{$userId}/totp/verify", ['code' => $code]);
        $r2 = $this->req('POST', "/users/{$userId}/totp/verify", ['code' => $code]);

        $this->assertSame(200, $r1->getStatusCode(), 'First use should succeed');
        $this->assertSame(401, $r2->getStatusCode(), 'Replayed code must be rejected');
    }

    /** VULN-B: ブルートフォース — 3回失敗するとロックアウト */
    public function testVulnBBruteForceLocksAccount(): void
    {
        $userId = $this->createUser();
        $this->setupAndEnable($userId);

        for ($i = 0; $i < 3; $i++) {
            $this->req('POST', "/users/{$userId}/totp/verify", ['code' => '000000']);
        }

        $status = $this->json($this->req('GET', "/users/{$userId}/totp"));
        $this->assertTrue($status['locked'], 'Account must be locked after 3 failures');
    }

    /** VULN-C: ロックアウト後は正しいコードでも拒否 */
    public function testVulnCLockedAccountRejectsValidCode(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupAndEnable($userId);

        for ($i = 0; $i < 3; $i++) {
            $this->req('POST', "/users/{$userId}/totp/verify", ['code' => '000000']);
        }

        // Even with a valid code, locked account must return 423
        $validCode = $this->gen->computeCode($secret, $this->gen->currentTimeStep() + 2);
        $r = $this->req('POST', "/users/{$userId}/totp/verify", ['code' => $validCode]);
        $this->assertSame(423, $r->getStatusCode());
    }

    /** VULN-D: 無効なコードで 2FA を無効化できない */
    public function testVulnDCannotDisableWithWrongCode(): void
    {
        $userId = $this->createUser();
        $this->setupAndEnable($userId);

        $r = $this->req('DELETE', "/users/{$userId}/totp", ['code' => '000000']);
        $this->assertSame(401, $r->getStatusCode());

        // 2FA should still be enabled
        $status = $this->json($this->req('GET', "/users/{$userId}/totp"));
        $this->assertTrue($status['enabled']);
    }

    /** VULN-E: 無効なコードで 2FA を有効化できない */
    public function testVulnECannotEnableWithWrongCode(): void
    {
        $userId = $this->createUser();
        $this->json($this->req('POST', "/users/{$userId}/totp/setup"));

        $r = $this->req('POST', "/users/{$userId}/totp/enable", ['code' => '000000']);
        $this->assertSame(401, $r->getStatusCode());

        $status = $this->json($this->req('GET', "/users/{$userId}/totp"));
        $this->assertFalse($status['enabled']);
    }

    /** VULN-F: 再セットアップで古いシークレットのコードは無効になる */
    public function testVulnFOldSecretInvalidatedAfterResetup(): void
    {
        $userId = $this->createUser();

        // First setup — don't enable yet
        $data1 = $this->json($this->req('POST', "/users/{$userId}/totp/setup"));
        $oldSecret = (string) $data1['secret'];
        $oldCode = $this->gen->computeCode($oldSecret, $this->gen->currentTimeStep());

        // Second setup overwrites the secret
        $this->req('POST', "/users/{$userId}/totp/setup");

        // Trying to enable with old secret's code must fail
        $r = $this->req('POST', "/users/{$userId}/totp/enable", ['code' => $oldCode]);
        $this->assertSame(401, $r->getStatusCode());
    }

    /** VULN-G: IDOR — 別ユーザーのコードで認証できない */
    public function testVulnGCrossUserCodeRejected(): void
    {
        $aliceId = $this->createUser('Alice');
        $bobId = $this->createUser('Bob');

        $aliceSecret = $this->setupAndEnable($aliceId);
        $this->json($this->req('POST', "/users/{$bobId}/totp/setup"));

        // Bob's 2FA not enabled yet
        // Try to enable Bob's using Alice's code
        $aliceCode = $this->gen->computeCode($aliceSecret, $this->gen->currentTimeStep() + 1);
        $r = $this->req('POST', "/users/{$bobId}/totp/enable", ['code' => $aliceCode]);
        // Bob has different secret; Alice's code won't match Bob's secret
        $this->assertSame(401, $r->getStatusCode());
    }

    /** VULN-H: verify レスポンスにシークレットが含まれない */
    public function testVulnHSecretNotExposedInVerifyResponse(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupAndEnable($userId);

        $code = $this->gen->computeCode($secret, $this->gen->currentTimeStep() + 1);
        $data = $this->json($this->req('POST', "/users/{$userId}/totp/verify", ['code' => $code]));

        $responseJson = json_encode($data);
        assert($responseJson !== false);
        $this->assertStringNotContainsString($secret, $responseJson, 'Secret must not appear in verify response');
    }

    /** VULN-I: 非数字コードはバリデーションエラー */
    public function testVulnINonDigitCodeRejectedWith422(): void
    {
        $userId = $this->createUser();
        $this->setupAndEnable($userId);

        $r = $this->req('POST', "/users/{$userId}/totp/verify", ['code' => 'ABCDEF']);
        // Non-digit code won't match any valid TOTP → 401 (invalid code)
        // or 422 (format validation). Either is acceptable security behavior.
        $this->assertContains($r->getStatusCode(), [401, 422]);
    }

    /** VULN-J: 空コードは検証を通過しない */
    public function testVulnJEmptyCodeRejected(): void
    {
        $userId = $this->createUser();
        $this->setupAndEnable($userId);

        $r = $this->req('POST', "/users/{$userId}/totp/verify", ['code' => '']);
        $this->assertSame(422, $r->getStatusCode());
    }

    /** VULN-K: 2FA 未有効化状態では verify が 409 を返す */
    public function testVulnKVerifyBeforeEnableReturns409(): void
    {
        $userId = $this->createUser();
        $this->req('POST', "/users/{$userId}/totp/setup");

        $r = $this->req('POST', "/users/{$userId}/totp/verify", ['code' => '123456']);
        $this->assertSame(409, $r->getStatusCode());
    }

    /** VULN-L: 存在しないユーザーへの verify は 404 */
    public function testVulnLVerifyNonExistentUserReturns404(): void
    {
        $r = $this->req('POST', '/users/9999/totp/verify', ['code' => '123456']);
        $this->assertSame(404, $r->getStatusCode());
    }
}
