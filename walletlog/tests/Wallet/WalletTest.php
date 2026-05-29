<?php

declare(strict_types=1);

namespace WalletLog\Tests\Wallet;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use WalletLog\AppFactory;

class WalletTest extends TestCase
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

    /** @param array<string, string> $headers */
    private function req(string $method, string $path, array $headers = [], mixed $body = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $uri = $psr17->createUri('http://localhost' . $path);
        /** @var \Psr\Http\Message\ServerRequestInterface $request */
        $request = $psr17->createServerRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
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

    private function deposit(int $user, string $currency, int $amount): void
    {
        $res = $this->req('POST', '/wallet/deposit', ['X-User-Id' => (string) $user], ['currency' => $currency, 'amount' => $amount]);
        assert($res->getStatusCode() === 200);
    }

    // ─── auth / validation ───────────────────────────────────────────────────

    public function testRequiresUserId(): void
    {
        $this->assertSame(401, $this->req('GET', '/wallet')->getStatusCode());
    }

    public function testNegativeAndZeroUserIdRejected(): void
    {
        $this->assertSame(401, $this->req('GET', '/wallet', ['X-User-Id' => '-1'])->getStatusCode());
        $this->assertSame(401, $this->req('GET', '/wallet', ['X-User-Id' => '0'])->getStatusCode());
    }

    public function testInvalidCurrencyRejected(): void
    {
        $res = $this->req('POST', '/wallet/deposit', ['X-User-Id' => '1'], ['currency' => 'XYZ', 'amount' => 100]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testNonPositiveAmountRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/wallet/deposit', ['X-User-Id' => '1'], ['currency' => 'USD', 'amount' => 0])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/wallet/deposit', ['X-User-Id' => '1'], ['currency' => 'USD', 'amount' => -5])->getStatusCode());
    }

    // ─── deposit / withdraw ──────────────────────────────────────────────────

    public function testDepositIncreasesBalance(): void
    {
        $res = $this->req('POST', '/wallet/deposit', ['X-User-Id' => '1'], ['currency' => 'USD', 'amount' => 1500]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1500, $this->json($res)['balance']);
    }

    public function testWithdrawDecreasesBalance(): void
    {
        $this->deposit(1, 'USD', 1000);
        $res = $this->req('POST', '/wallet/withdraw', ['X-User-Id' => '1'], ['currency' => 'USD', 'amount' => 400]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(600, $this->json($res)['balance']);
    }

    public function testWithdrawInsufficientFunds(): void
    {
        $this->deposit(1, 'USD', 100);
        $res = $this->req('POST', '/wallet/withdraw', ['X-User-Id' => '1'], ['currency' => 'USD', 'amount' => 500]);
        $this->assertSame(409, $res->getStatusCode());
        // balance unchanged
        $this->assertSame(100, $this->json($this->req('GET', '/wallet', ['X-User-Id' => '1']))['balances'][0]['balance']);
    }

    // ─── transfer ────────────────────────────────────────────────────────────

    public function testTransferMovesFunds(): void
    {
        $this->deposit(1, 'USD', 1000);
        $res = $this->req('POST', '/wallet/transfer', ['X-User-Id' => '1'], ['to_user_id' => 2, 'currency' => 'USD', 'amount' => 300]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(700, $this->json($res)['balance']);
        // recipient received it
        $this->assertSame(300, $this->json($this->req('GET', '/wallet', ['X-User-Id' => '2']))['balances'][0]['balance']);
    }

    public function testSelfTransferRejected(): void
    {
        $this->deposit(1, 'USD', 1000);
        $res = $this->req('POST', '/wallet/transfer', ['X-User-Id' => '1'], ['to_user_id' => 1, 'currency' => 'USD', 'amount' => 100]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testTransferInsufficientRollsBack(): void
    {
        $this->deposit(1, 'USD', 100);
        $res = $this->req('POST', '/wallet/transfer', ['X-User-Id' => '1'], ['to_user_id' => 2, 'currency' => 'USD', 'amount' => 500]);
        $this->assertSame(409, $res->getStatusCode());
        // sender unchanged, recipient got nothing (rolled back)
        $this->assertSame(100, $this->json($this->req('GET', '/wallet', ['X-User-Id' => '1']))['balances'][0]['balance']);
        $this->assertSame([], $this->json($this->req('GET', '/wallet', ['X-User-Id' => '2']))['balances']);
    }

    // ─── IDOR: ledger is per-user ────────────────────────────────────────────

    public function testTransactionsAreIsolatedPerUser(): void
    {
        $this->deposit(1, 'USD', 1000);
        $this->req('POST', '/wallet/transfer', ['X-User-Id' => '1'], ['to_user_id' => 2, 'currency' => 'USD', 'amount' => 200]);

        // user 1 sees: deposit + transfer_out
        $t1 = $this->json($this->req('GET', '/wallet/transactions', ['X-User-Id' => '1']));
        $this->assertSame(['deposit', 'transfer_out'], array_map(static fn (array $t): string => $t['type'], $t1['transactions']));

        // user 2 sees only their transfer_in — never user 1's rows
        $t2 = $this->json($this->req('GET', '/wallet/transactions', ['X-User-Id' => '2']));
        $this->assertSame(['transfer_in'], array_map(static fn (array $t): string => $t['type'], $t2['transactions']));
        $this->assertSame(200, $t2['transactions'][0]['amount']);
    }

    public function testCurrenciesAreSeparate(): void
    {
        $this->deposit(1, 'USD', 1000);
        $this->deposit(1, 'JPY', 500);
        $balances = $this->json($this->req('GET', '/wallet', ['X-User-Id' => '1']))['balances'];
        $this->assertSame(['JPY' => 500, 'USD' => 1000], [
            $balances[0]['currency'] => $balances[0]['balance'],
            $balances[1]['currency'] => $balances[1]['balance'],
        ]);
    }
}
