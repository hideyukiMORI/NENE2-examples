<?php

declare(strict_types=1);

namespace BudgetLog\Tests\Budget;

use BudgetLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class BudgetTest extends TestCase
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

    private function account(string $user, string $name = 'Main', int $initial = 0): int
    {
        $res = $this->req('POST', '/accounts', ['X-User-Id' => $user], ['name' => $name, 'initial_balance' => $initial]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    private function balance(string $user, int $id): int
    {
        return (int) $this->json($this->req('GET', '/accounts/' . $id, ['X-User-Id' => $user]))['balance'];
    }

    // ── auth / ownership (ATK-01 / ATK-10 fixed) ───────────────────────────

    public function testAllEndpointsRequireUser(): void
    {
        $this->assertSame(401, $this->req('GET', '/accounts')->getStatusCode());
        $this->assertSame(401, $this->req('POST', '/accounts', [], ['name' => 'x'])->getStatusCode());
    }

    public function testAccountsAreOwnerScoped(): void
    {
        $a = $this->account('100', 'Alice');
        // user 200 cannot read user 100's account
        $this->assertSame(404, $this->req('GET', '/accounts/' . $a, ['X-User-Id' => '200'])->getStatusCode());
        $this->assertSame(404, $this->req('GET', "/accounts/{$a}/transactions", ['X-User-Id' => '200'])->getStatusCode());
        $this->assertSame([], $this->json($this->req('GET', '/accounts', ['X-User-Id' => '200']))['accounts']);
    }

    // ── create account validation (ATK-02) ──────────────────────────────────

    public function testNegativeInitialBalanceRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/accounts', ['X-User-Id' => '1'], ['name' => 'x', 'initial_balance' => -50])->getStatusCode());
    }

    // ── transactions ────────────────────────────────────────────────────────

    public function testIncomeAndExpenseUpdateBalance(): void
    {
        $a = $this->account('1', 'Main', 0);
        $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 1000, 'type' => 'income', 'category' => 'salary', 'recurring' => true]);
        $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 300, 'type' => 'expense', 'category' => 'food', 'recurring' => false]);
        $this->assertSame(700, $this->balance('1', $a));
    }

    /** ATK-03 / ATK-09 fixed: an expense cannot drive the balance negative. */
    public function testExpenseCannotExceedBalance(): void
    {
        $a = $this->account('1', 'Main', 100);
        $res = $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 99999, 'type' => 'expense', 'category' => 'food', 'recurring' => false]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(100, $this->balance('1', $a)); // unchanged
    }

    /** ATK / type allowlist: 'transfer' cannot be injected via the transaction endpoint. */
    public function testTransferTypeRejectedOnTransactionEndpoint(): void
    {
        $a = $this->account('1', 'Main', 100);
        $this->assertSame(422, $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 10, 'type' => 'transfer', 'category' => 'x', 'recurring' => false])->getStatusCode());
    }

    /** ATK-05 fixed: float amount rejected (no silent (int) truncation). */
    public function testFloatAmountRejected(): void
    {
        $a = $this->account('1', 'Main', 100);
        $this->assertSame(422, $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 1.9, 'type' => 'income', 'category' => 'x', 'recurring' => false])->getStatusCode());
    }

    /** ATK-06: zero / negative amount rejected. */
    public function testZeroAmountRejected(): void
    {
        $a = $this->account('1', 'Main', 100);
        $this->assertSame(422, $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 0, 'type' => 'income', 'category' => 'x', 'recurring' => false])->getStatusCode());
    }

    /** ATK-11 fixed: non-boolean recurring rejected (no truthy coercion). */
    public function testNonBooleanRecurringRejected(): void
    {
        $a = $this->account('1', 'Main', 100);
        $this->assertSame(422, $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 10, 'type' => 'income', 'category' => 'x', 'recurring' => 'yes'])->getStatusCode());
    }

    /** ATK-12 fixed: non-numeric path id → 404 (ctype_digit). */
    public function testNonNumericAccountIdIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/accounts/abc', ['X-User-Id' => '1'])->getStatusCode());
    }

    // ── transfer (ATK-07 / ATK-08) ───────────────────────────────────────────

    public function testTransferMovesFundsAtomically(): void
    {
        $from = $this->account('1', 'From', 1000);
        $to = $this->account('1', 'To', 0);
        $res = $this->req('POST', '/transfers', ['X-User-Id' => '1'], ['from_account_id' => $from, 'to_account_id' => $to, 'amount' => 400]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(600, $this->balance('1', $from));
        $this->assertSame(400, $this->balance('1', $to));
    }

    public function testTransferSameAccountRejected(): void
    {
        $a = $this->account('1', 'Main', 1000);
        $this->assertSame(422, $this->req('POST', '/transfers', ['X-User-Id' => '1'], ['from_account_id' => $a, 'to_account_id' => $a, 'amount' => 100])->getStatusCode());
    }

    public function testTransferInsufficientRollsBack(): void
    {
        $from = $this->account('1', 'From', 100);
        $to = $this->account('1', 'To', 0);
        $res = $this->req('POST', '/transfers', ['X-User-Id' => '1'], ['from_account_id' => $from, 'to_account_id' => $to, 'amount' => 99999]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(100, $this->balance('1', $from));
        $this->assertSame(0, $this->balance('1', $to));
    }

    public function testCannotTransferFromAnotherUsersAccount(): void
    {
        $alice = $this->account('100', 'Alice', 1000);
        $bob = $this->account('200', 'Bob', 0);
        // user 200 tries to pull from Alice's account
        $res = $this->req('POST', '/transfers', ['X-User-Id' => '200'], ['from_account_id' => $alice, 'to_account_id' => $bob, 'amount' => 500]);
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame(1000, $this->balance('100', $alice));
    }

    // ── filters / summary ─────────────────────────────────────────────────────

    public function testTransactionFilters(): void
    {
        $a = $this->account('1', 'Main', 0);
        $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 100, 'type' => 'income', 'category' => 'salary', 'recurring' => true]);
        $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 5000, 'type' => 'income', 'category' => 'bonus', 'recurring' => false]);

        $data = $this->json($this->req('GET', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], null, ['recurring' => 'true']));
        $this->assertSame(1, $data['total']);
        $this->assertSame('salary', $data['items'][0]['category']);

        $byMin = $this->json($this->req('GET', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], null, ['min_amount' => '1000']));
        $this->assertSame(1, $byMin['total']);
        $this->assertSame('bonus', $byMin['items'][0]['category']);
    }

    public function testSummaryByCategory(): void
    {
        $a = $this->account('1', 'Main', 0);
        $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 1000, 'type' => 'income', 'category' => 'salary', 'recurring' => false]);
        $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 300, 'type' => 'expense', 'category' => 'food', 'recurring' => false]);
        $this->req('POST', "/accounts/{$a}/transactions", ['X-User-Id' => '1'], ['amount' => 200, 'type' => 'expense', 'category' => 'food', 'recurring' => false]);

        $data = $this->json($this->req('GET', "/accounts/{$a}/summary", ['X-User-Id' => '1']));
        $this->assertSame(500, $data['balance']);
        $this->assertSame('food', $data['expense_by_category'][0]['category']);
        $this->assertSame(500, $data['expense_by_category'][0]['total']);
    }
}
