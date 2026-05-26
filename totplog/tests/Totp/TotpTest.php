<?php

declare(strict_types=1);

namespace TotpLog\Tests\Totp;

use TotpLog\AppFactory;
use TotpLog\Totp\TotpGenerator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class TotpTest extends TestCase
{
    private PDO $pdo;
    private string $dbFile;
    private TotpGenerator $gen;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
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

    /** Returns [userId, secret] */
    private function setupTotp(int $userId): string
    {
        $data = $this->json($this->req('POST', "/users/{$userId}/totp/setup"));
        return (string) $data['secret'];
    }

    private function currentCode(string $secret): string
    {
        return $this->gen->computeCode($secret, $this->gen->currentTimeStep());
    }

    // ── User creation ────────────────────────────────────────────────────────

    public function testCreateUserReturns201(): void
    {
        $r = $this->req('POST', '/users', ['name' => 'Alice']);
        $this->assertSame(201, $r->getStatusCode());
        $data = $this->json($r);
        $this->assertSame('Alice', $data['name']);
        $this->assertArrayHasKey('id', $data);
    }

    // ── Setup ────────────────────────────────────────────────────────────────

    public function testSetupReturnsSecretAndUri(): void
    {
        $userId = $this->createUser();
        $r = $this->req('POST', "/users/{$userId}/totp/setup");
        $this->assertSame(201, $r->getStatusCode());
        $data = $this->json($r);
        $this->assertArrayHasKey('secret', $data);
        $this->assertArrayHasKey('otpauth_uri', $data);
        $this->assertStringStartsWith('otpauth://totp/', $data['otpauth_uri']);
    }

    public function testSetupSecretIsBase32(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupTotp($userId);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testSetupForUnknownUserReturns404(): void
    {
        $r = $this->req('POST', '/users/9999/totp/setup');
        $this->assertSame(404, $r->getStatusCode());
    }

    public function testSetupTwiceGeneratesNewSecret(): void
    {
        $userId = $this->createUser();
        $secret1 = $this->setupTotp($userId);
        $secret2 = $this->setupTotp($userId);
        $this->assertNotSame($secret1, $secret2);
    }

    // ── Enable ───────────────────────────────────────────────────────────────

    public function testEnableWithValidCodeSucceeds(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupTotp($userId);

        $r = $this->req('POST', "/users/{$userId}/totp/enable", ['code' => $this->currentCode($secret)]);
        $this->assertSame(200, $r->getStatusCode());
        $data = $this->json($r);
        $this->assertTrue($data['enabled']);
    }

    public function testEnableWithInvalidCodeReturns401(): void
    {
        $userId = $this->createUser();
        $this->setupTotp($userId);

        $r = $this->req('POST', "/users/{$userId}/totp/enable", ['code' => '000000']);
        $this->assertSame(401, $r->getStatusCode());
    }

    public function testEnableWhenAlreadyEnabledReturns409(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupTotp($userId);
        $this->req('POST', "/users/{$userId}/totp/enable", ['code' => $this->currentCode($secret)]);

        // Generate a code for the next time step to avoid replay
        $r = $this->req('POST', "/users/{$userId}/totp/enable", [
            'code' => $this->gen->computeCode($secret, $this->gen->currentTimeStep() + 1),
        ]);
        $this->assertSame(409, $r->getStatusCode());
    }

    public function testEnableWithoutSetupReturns409(): void
    {
        $userId = $this->createUser();
        $r = $this->req('POST', "/users/{$userId}/totp/enable", ['code' => '123456']);
        $this->assertSame(409, $r->getStatusCode());
    }

    // ── Verify ───────────────────────────────────────────────────────────────

    public function testVerifyValidCodeAfterEnableSucceeds(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupTotp($userId);
        $enableCode = $this->currentCode($secret);
        $this->req('POST', "/users/{$userId}/totp/enable", ['code' => $enableCode]);

        // Use next time step to avoid replay
        $verifyCode = $this->gen->computeCode($secret, $this->gen->currentTimeStep() + 1);
        $r = $this->req('POST', "/users/{$userId}/totp/verify", ['code' => $verifyCode]);
        $this->assertSame(200, $r->getStatusCode());
        $data = $this->json($r);
        $this->assertTrue($data['verified']);
    }

    public function testVerifyInvalidCodeReturns401WithAttempts(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupTotp($userId);
        $this->req('POST', "/users/{$userId}/totp/enable", ['code' => $this->currentCode($secret)]);

        $r = $this->req('POST', "/users/{$userId}/totp/verify", ['code' => '000000']);
        $this->assertSame(401, $r->getStatusCode());
        $data = $this->json($r);
        $this->assertArrayHasKey('failed_attempts', $data);
        $this->assertGreaterThan(0, $data['failed_attempts']);
    }

    public function testVerifyLocksAfter3Failures(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupTotp($userId);
        $this->req('POST', "/users/{$userId}/totp/enable", ['code' => $this->currentCode($secret)]);

        for ($i = 0; $i < 3; $i++) {
            $this->req('POST', "/users/{$userId}/totp/verify", ['code' => '000000']);
        }

        $r = $this->req('POST', "/users/{$userId}/totp/verify", ['code' => '000000']);
        $this->assertSame(423, $r->getStatusCode());
    }

    public function testVerifyWithoutEnableReturns409(): void
    {
        $userId = $this->createUser();
        $r = $this->req('POST', "/users/{$userId}/totp/verify", ['code' => '123456']);
        $this->assertSame(409, $r->getStatusCode());
    }

    public function testVerifyUnknownUserReturns404(): void
    {
        $r = $this->req('POST', '/users/9999/totp/verify', ['code' => '123456']);
        $this->assertSame(404, $r->getStatusCode());
    }

    // ── Disable ──────────────────────────────────────────────────────────────

    public function testDisableWithValidCodeSucceeds(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupTotp($userId);
        $this->req('POST', "/users/{$userId}/totp/enable", ['code' => $this->currentCode($secret)]);

        $disableCode = $this->gen->computeCode($secret, $this->gen->currentTimeStep() + 1);
        $r = $this->req('DELETE', "/users/{$userId}/totp", ['code' => $disableCode]);
        $this->assertSame(204, $r->getStatusCode());
    }

    public function testDisableWithInvalidCodeReturns401(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupTotp($userId);
        $this->req('POST', "/users/{$userId}/totp/enable", ['code' => $this->currentCode($secret)]);

        $r = $this->req('DELETE', "/users/{$userId}/totp", ['code' => '000000']);
        $this->assertSame(401, $r->getStatusCode());
    }

    public function testDisableWhenNotEnabledReturns409(): void
    {
        $userId = $this->createUser();
        $r = $this->req('DELETE', "/users/{$userId}/totp", ['code' => '123456']);
        $this->assertSame(409, $r->getStatusCode());
    }

    // ── Status ───────────────────────────────────────────────────────────────

    public function testGetStatusBeforeSetup(): void
    {
        $userId = $this->createUser();
        $r = $this->req('GET', "/users/{$userId}/totp");
        $this->assertSame(200, $r->getStatusCode());
        $data = $this->json($r);
        $this->assertFalse($data['enabled']);
        $this->assertFalse($data['setup']);
    }

    public function testGetStatusAfterSetupBeforeEnable(): void
    {
        $userId = $this->createUser();
        $this->setupTotp($userId);
        $data = $this->json($this->req('GET', "/users/{$userId}/totp"));
        $this->assertFalse($data['enabled']);
        $this->assertTrue($data['setup']);
    }

    public function testGetStatusAfterEnable(): void
    {
        $userId = $this->createUser();
        $secret = $this->setupTotp($userId);
        $this->req('POST', "/users/{$userId}/totp/enable", ['code' => $this->currentCode($secret)]);
        $data = $this->json($this->req('GET', "/users/{$userId}/totp"));
        $this->assertTrue($data['enabled']);
    }
}
