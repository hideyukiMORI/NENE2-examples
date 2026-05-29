<?php

declare(strict_types=1);

namespace FtsLog\Tests\Post;

use FtsLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class FtsTest extends TestCase
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
    private function req(string $method, string $path, array $headers = [], mixed $body = null, ?string $query = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        if ($query !== null) {
            parse_str($query, $q);
            $request = $request->withQueryParams($q);
        }
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

    /** @param array<string, mixed> $body */
    private function create(array $body): int
    {
        $res = $this->req('POST', '/posts', [], $body);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    private function search(string $q): ResponseInterface
    {
        return $this->req('GET', '/posts/search', [], null, 'q=' . rawurlencode($q));
    }

    // ── indexing via triggers ──────────────────────────────────────────────────

    public function testCreateIsIndexedAndFound(): void
    {
        $this->create(['title' => 'PHP Framework', 'body' => 'Building APIs with PHP', 'tags' => 'php backend']);
        $data = $this->json($this->search('php'));
        $this->assertSame(1, $data['total']);
        $this->assertSame('PHP Framework', $data['items'][0]['title']);
    }

    public function testSearchMatchesBody(): void
    {
        $this->create(['title' => 'Intro', 'body' => 'a guide to kubernetes orchestration']);
        $this->assertSame(1, $this->json($this->search('kubernetes'))['total']);
    }

    public function testSearchMatchesTags(): void
    {
        $this->create(['title' => 'Notes', 'body' => 'misc', 'tags' => 'docker kubernetes devops']);
        // a bare-term search across columns finds the tag
        $this->assertSame(1, $this->json($this->search('devops'))['total']);
    }

    public function testCaseInsensitive(): void
    {
        $this->create(['title' => 'PHP', 'body' => 'x']);
        $this->assertSame(1, $this->json($this->search('php'))['total']);
        $this->assertSame(1, $this->json($this->search('PHP'))['total']);
    }

    // ── FTS5 query syntax ───────────────────────────────────────────────────────

    public function testPrefixSearch(): void
    {
        $this->create(['title' => 'PHP Programming', 'body' => 'x']);
        $this->assertSame(1, $this->json($this->search('progr*'))['total']);
    }

    public function testPhraseSearch(): void
    {
        $this->create(['title' => 'The quick brown fox', 'body' => 'x']);
        $this->create(['title' => 'brown and quick', 'body' => 'x']);
        // exact phrase only matches the first
        $this->assertSame(1, $this->json($this->search('"quick brown"'))['total']);
    }

    public function testAndOrOperators(): void
    {
        $this->create(['title' => 'php api', 'body' => 'x']);
        $this->create(['title' => 'php only', 'body' => 'x']);
        // FTS5's implicit operator is AND (not OR) — bare "php api" requires both.
        $this->assertSame(1, $this->json($this->search('php api'))['total']);
        $this->assertSame(1, $this->json($this->search('php AND api'))['total']);
        // explicit OR matches both
        $this->assertSame(2, $this->json($this->search('php OR api'))['total']);
    }

    public function testColumnScopedSearch(): void
    {
        $this->create(['title' => 'python', 'body' => 'about php']);
        $this->create(['title' => 'php', 'body' => 'about python']);
        // title:php only matches the second
        $data = $this->json($this->search('title:php'));
        $this->assertSame(1, $data['total']);
        $this->assertSame('php', $data['items'][0]['title']);
    }

    public function testNotOperator(): void
    {
        $this->create(['title' => 'php and python', 'body' => 'x']);
        $this->create(['title' => 'php only', 'body' => 'x']);
        $this->assertSame(1, $this->json($this->search('php NOT python'))['total']);
    }

    // ── relevance ranking ───────────────────────────────────────────────────────

    public function testResultsAreRankOrdered(): void
    {
        $this->create(['title' => 'php', 'body' => 'one mention']);
        $this->create(['title' => 'php php php', 'body' => 'php php php many mentions of php']);
        $items = $this->json($this->search('php'))['items'];
        $this->assertCount(2, $items);
        // more relevant (lower rank) first
        $this->assertLessThanOrEqual($items[1]['rank'], $items[0]['rank']);
        $this->assertStringContainsString('many mentions', $items[0]['body']);
    }

    // ── trigger sync on delete is not exposed; verify update path indirectly ───────

    public function testNoMatchReturnsEmpty(): void
    {
        $this->create(['title' => 'php', 'body' => 'x']);
        $this->assertSame(0, $this->json($this->search('rustlang'))['total']);
    }

    // ── invalid query → 400 ──────────────────────────────────────────────────────

    public function testMalformedQueryIs400(): void
    {
        $this->create(['title' => 'php', 'body' => 'x']);
        // unclosed quote is invalid FTS5 syntax → 400 (not 500)
        $res = $this->search('"unclosed');
        $this->assertSame(400, $res->getStatusCode());
    }

    public function testEmptyQueryIs422(): void
    {
        $this->assertSame(422, $this->req('GET', '/posts/search', [], null, 'q=')->getStatusCode());
        $this->assertSame(422, $this->req('GET', '/posts/search')->getStatusCode());
    }

    // ── routing ───────────────────────────────────────────────────────────────────

    public function testSearchRouteNotCapturedById(): void
    {
        // '/posts/search' must hit search (422 for missing q), not show({id}=search)
        $this->assertSame(422, $this->req('GET', '/posts/search')->getStatusCode());
    }

    public function testGetById(): void
    {
        $id = $this->create(['title' => 'x', 'body' => 'y']);
        $this->assertSame(200, $this->req('GET', '/posts/' . $id)->getStatusCode());
        $this->assertSame(404, $this->req('GET', '/posts/999')->getStatusCode());
    }
}
