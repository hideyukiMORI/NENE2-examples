<?php

declare(strict_types=1);

namespace TagFilterLog\Tests\Post;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TagFilterLog\AppFactory;

class TagFilterTest extends TestCase
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
        if ($query !== []) {
            $request = $request->withQueryParams($query);
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

    /** @param list<string> $tags */
    private function create(string $title, array $tags): int
    {
        $res = $this->req('POST', '/posts', [], ['title' => $title, 'tags' => $tags]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    /**
     * @param array<string, mixed> $query
     * @return list<string> titles in result order
     */
    private function listTitles(array $query): array
    {
        $data = $this->json($this->req('GET', '/posts', [], null, $query));
        return array_values(array_map(static fn (array $p): string => $p['title'], $data['posts']));
    }

    // ── create ──────────────────────────────────────────────────────────────

    public function testCreateDedupesAndSortsTags(): void
    {
        $id = $this->create('A', ['php', 'api', 'php', 'api']);
        $tags = $this->json($this->req('GET', '/posts/' . $id))['tags'];
        $this->assertSame(['api', 'php'], $tags); // unique + sorted
    }

    public function testTitleRequired(): void
    {
        $this->assertSame(422, $this->req('POST', '/posts', [], ['tags' => ['x']])->getStatusCode());
    }

    public function testNonStringTagsDropped(): void
    {
        $id = $this->create('A', ['php', '', '  ']);
        $this->assertSame(['php'], $this->json($this->req('GET', '/posts/' . $id))['tags']);
    }

    // ── AND filter ──────────────────────────────────────────────────────────────

    public function testAndFilterRequiresAllTags(): void
    {
        $this->create('both', ['php', 'api']);
        $this->create('php-only', ['php']);
        $this->create('js-only', ['js']);
        // mode defaults to 'all' (AND) — only the post with BOTH php and api
        $this->assertSame(['both'], $this->listTitles(['tags' => 'php,api']));
    }

    public function testAndDefaultsWhenModeUnknown(): void
    {
        $this->create('both', ['php', 'api']);
        $this->create('php-only', ['php']);
        // unknown mode → AND
        $this->assertSame(['both'], $this->listTitles(['tags' => 'php,api', 'mode' => 'wat']));
    }

    // ── OR filter ──────────────────────────────────────────────────────────────────

    public function testOrFilterMatchesAnyTag(): void
    {
        $this->create('both', ['php', 'api']);
        $this->create('php-only', ['php']);
        $this->create('js-only', ['js']);
        $titles = $this->listTitles(['tags' => 'php,api', 'mode' => 'any']);
        sort($titles);
        $this->assertSame(['both', 'php-only'], $titles);
    }

    public function testOrNoDuplicateRows(): void
    {
        // a post matching multiple IN tags must appear once (SELECT DISTINCT)
        $this->create('multi', ['php', 'api', 'sql']);
        $this->assertCount(1, $this->json($this->req('GET', '/posts', [], null, ['tags' => 'php,api,sql', 'mode' => 'any']))['posts']);
    }

    // ── dual query format ────────────────────────────────────────────────────────────

    public function testPhpArrayQueryFormat(): void
    {
        $this->create('both', ['php', 'api']);
        $this->create('php-only', ['php']);
        // ?tags[]=php&tags[]=api  (PSR-7 parses to array)
        $this->assertSame(['both'], $this->listTitles(['tags' => ['php', 'api']]));
    }

    public function testCommaSeparatedAndArrayEquivalent(): void
    {
        $this->create('both', ['php', 'api']);
        $csv = $this->listTitles(['tags' => 'php,api']);
        $arr = $this->listTitles(['tags' => ['php', 'api']]);
        $this->assertSame($csv, $arr);
    }

    // ── no tags → all ────────────────────────────────────────────────────────────────

    public function testNoTagsReturnsAll(): void
    {
        $this->create('a', ['php']);
        $this->create('b', ['js']);
        $this->assertCount(2, $this->json($this->req('GET', '/posts'))['posts']);
        // empty tags param also returns all
        $this->assertCount(2, $this->json($this->req('GET', '/posts', [], null, ['tags' => '']))['posts']);
    }

    public function testShowUnknownIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/posts/999')->getStatusCode());
    }
}
