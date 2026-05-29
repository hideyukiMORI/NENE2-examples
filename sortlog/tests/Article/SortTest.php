<?php

declare(strict_types=1);

namespace SortLog\Tests\Article;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SortLog\AppFactory;

class SortTest extends TestCase
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

    private function seed(): void
    {
        foreach ([['Banana', 'published'], ['Apple', 'draft'], ['Cherry', 'archived']] as [$t, $s]) {
            $res = $this->req('POST', '/articles', ['title' => $t, 'status' => $s]);
            assert($res->getStatusCode() === 201);
        }
    }

    // ── legitimate sorting works ────────────────────────────────────────────

    public function testDefaultSort(): void
    {
        $this->seed();
        $res = $this->req('GET', '/articles');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('created_at', $this->json($res)['sort']);
        $this->assertSame('asc', $this->json($res)['order']);
    }

    public function testSortByTitleAsc(): void
    {
        $this->seed();
        $titles = array_map(static fn (array $a): string => $a['title'], $this->json($this->req('GET', '/articles', null, ['sort' => 'title', 'order' => 'asc']))['items']);
        $this->assertSame(['Apple', 'Banana', 'Cherry'], $titles);
    }

    public function testSortByTitleDesc(): void
    {
        $this->seed();
        $titles = array_map(static fn (array $a): string => $a['title'], $this->json($this->req('GET', '/articles', null, ['sort' => 'title', 'order' => 'DESC']))['items']);
        $this->assertSame(['Cherry', 'Banana', 'Apple'], $titles);
    }

    public function testStatusFilter(): void
    {
        $this->seed();
        $data = $this->json($this->req('GET', '/articles', null, ['status' => 'published']));
        $this->assertSame(1, $data['count']);
        $this->assertSame('Banana', $data['items'][0]['title']);
    }

    // ── VULN/ATK: ORDER BY injection blocked ─────────────────────────────────

    public function testSqlInjectionInSortRejected(): void
    {
        $this->seed();
        foreach ([
            "'; DROP TABLE articles--",
            '1; SELECT password',
            '(SELECT name FROM sqlite_master)',
            'SLEEP(5)',
            'nonexistent_column',
            'created_at--',
            '1 AND 1=1',
            '1',                         // column-index injection
            'created_at;',
        ] as $payload) {
            $res = $this->req('GET', '/articles', null, ['sort' => $payload]);
            $this->assertSame(422, $res->getStatusCode(), "sort '{$payload}' must be 422");
        }
        // table still exists / intact
        $this->assertSame(200, $this->req('GET', '/articles')->getStatusCode());
    }

    public function testInvalidDirectionRejected(): void
    {
        $this->seed();
        foreach (['INVALID', 'asc; UNION SELECT 1,2--', 'desc--'] as $payload) {
            $this->assertSame(422, $this->req('GET', '/articles', null, ['order' => $payload])->getStatusCode());
        }
    }

    public function testNullByteInSortRejected(): void
    {
        $this->seed();
        $this->assertSame(422, $this->req('GET', '/articles', null, ['sort' => "created_at\0"])->getStatusCode());
    }

    public function testArrayInjectionInSortRejected(): void
    {
        $this->seed();
        $this->assertSame(422, $this->req('GET', '/articles', null, ['sort' => ['created_at']])->getStatusCode());
    }

    public function testStatusFilterInjectionRejected(): void
    {
        $this->seed();
        $this->assertSame(422, $this->req('GET', '/articles', null, ['status' => "' OR '1'='1"])->getStatusCode());
        $this->assertSame(422, $this->req('GET', '/articles', null, ['status' => ['draft']])->getStatusCode());
    }

    public function testLimitGuards(): void
    {
        $this->seed();
        $this->assertSame(422, $this->req('GET', '/articles', null, ['limit' => '999999'])->getStatusCode());
        $this->assertSame(422, $this->req('GET', '/articles', null, ['limit' => '10.5'])->getStatusCode());
    }

    public function testDoubleEncodingBypassRejected(): void
    {
        $this->seed();
        // PSR-7 decodes once: 'cr%2565ated_at' → 'cr%65ated_at' here; allow-list rejects it.
        $this->assertSame(422, $this->req('GET', '/articles', null, ['sort' => 'cr%65ated_at'])->getStatusCode());
    }
}
