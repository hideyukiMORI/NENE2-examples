<?php

declare(strict_types=1);

namespace ExpenseLog\Tests\Expense;

use ExpenseLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class ExpenseTest extends TestCase
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

    /** @param array<string, mixed> $query */
    private function req(string $method, string $path, mixed $body = null, array $query = []): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
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

    private function add(string $date, int $amount, string $category, string $note = ''): int
    {
        $res = $this->req('POST', '/expenses', ['date' => $date, 'amount' => $amount, 'category' => $category, 'note' => $note]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    // ── create / validation ──────────────────────────────────────────────────

    public function testCreateAndGet(): void
    {
        $id = $this->add('2026-05-01', 1200, 'food', 'lunch');
        $data = $this->json($this->req('GET', '/expenses/' . $id));
        $this->assertSame(1200, $data['amount']);
        $this->assertSame('food', $data['category']);
    }

    public function testRejectsFloatAmount(): void
    {
        $this->assertSame(422, $this->req('POST', '/expenses', ['date' => '2026-05-01', 'amount' => 12.5, 'category' => 'food'])->getStatusCode());
    }

    public function testRejectsNonPositiveAmount(): void
    {
        $this->assertSame(422, $this->req('POST', '/expenses', ['date' => '2026-05-01', 'amount' => 0, 'category' => 'food'])->getStatusCode());
    }

    public function testRejectsInvalidCategory(): void
    {
        $this->assertSame(422, $this->req('POST', '/expenses', ['date' => '2026-05-01', 'amount' => 100, 'category' => 'bogus'])->getStatusCode());
    }

    public function testRejectsNonCanonicalDate(): void
    {
        $this->assertSame(422, $this->req('POST', '/expenses', ['date' => '2026-5-1', 'amount' => 100, 'category' => 'food'])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/expenses', ['date' => '2026-13-40', 'amount' => 100, 'category' => 'food'])->getStatusCode());
    }

    // ── filters ────────────────────────────────────────────────────────────────

    public function testDateRangeFilter(): void
    {
        $this->add('2026-04-15', 100, 'food');
        $this->add('2026-05-10', 200, 'food');
        $this->add('2026-06-01', 300, 'food');

        $data = $this->json($this->req('GET', '/expenses', null, ['from' => '2026-05-01', 'to' => '2026-05-31']));
        $this->assertSame(1, $data['total']);
        $this->assertSame(200, $data['items'][0]['amount']);
    }

    public function testCategoryFilter(): void
    {
        $this->add('2026-05-01', 100, 'food');
        $this->add('2026-05-02', 200, 'transport');
        $data = $this->json($this->req('GET', '/expenses', null, ['category' => 'transport']));
        $this->assertSame(1, $data['total']);
        $this->assertSame('transport', $data['items'][0]['category']);
    }

    public function testInvalidDateFilterRejected(): void
    {
        $this->assertSame(422, $this->req('GET', '/expenses', null, ['from' => 'not-a-date'])->getStatusCode());
    }

    // ── summary ────────────────────────────────────────────────────────────────

    public function testMonthlySummaryByCategory(): void
    {
        $this->add('2026-05-01', 1000, 'food');
        $this->add('2026-05-15', 2000, 'food');
        $this->add('2026-05-20', 500, 'transport');
        $this->add('2026-04-10', 9000, 'food');

        $summary = $this->json($this->req('GET', '/expenses/summary'))['summary'];
        // most recent month first; within month, category asc
        $may = array_values(array_filter($summary, static fn (array $r): bool => $r['month'] === '2026-05'));
        $foodMay = array_values(array_filter($may, static fn (array $r): bool => $r['category'] === 'food'))[0];
        $this->assertSame(3000, $foodMay['total']);
        $this->assertSame(2, $foodMay['count']);
    }

    // ── PATCH ────────────────────────────────────────────────────────────────────

    public function testPatchUpdatesOnlyProvided(): void
    {
        $id = $this->add('2026-05-01', 1000, 'food', 'orig');
        $res = $this->req('PATCH', '/expenses/' . $id, ['amount' => 1500]);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame(1500, $data['amount']);
        $this->assertSame('food', $data['category']);   // preserved
        $this->assertSame('orig', $data['note']);        // preserved
    }

    public function testPatchInvalidCategory(): void
    {
        $id = $this->add('2026-05-01', 1000, 'food');
        $this->assertSame(422, $this->req('PATCH', '/expenses/' . $id, ['category' => 'nope'])->getStatusCode());
    }

    // ── delete / pagination ──────────────────────────────────────────────────────

    public function testDelete(): void
    {
        $id = $this->add('2026-05-01', 1000, 'food');
        $this->assertSame(204, $this->req('DELETE', '/expenses/' . $id)->getStatusCode());
        $this->assertSame(404, $this->req('GET', '/expenses/' . $id)->getStatusCode());
    }

    public function testPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->add(sprintf('2026-05-%02d', $i), 100 * $i, 'food');
        }
        $data = $this->json($this->req('GET', '/expenses', null, ['limit' => '2', 'offset' => '0']));
        $this->assertSame(5, $data['total']);
        $this->assertCount(2, $data['items']);
    }
}
