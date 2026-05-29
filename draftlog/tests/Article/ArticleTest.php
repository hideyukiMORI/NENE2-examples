<?php

declare(strict_types=1);

namespace DraftLog\Tests\Article;

use DraftLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class ArticleTest extends TestCase
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

    private function createDraft(string $author = '1', string $title = 'Hello'): int
    {
        $res = $this->req('POST', '/articles', ['X-User-Id' => $author], ['title' => $title, 'body' => 'content']);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    private function publish(int $id, string $author = '1'): ResponseInterface
    {
        return $this->req('POST', '/articles/' . $id . '/publish', ['X-User-Id' => $author]);
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function testCreateRequiresUser(): void
    {
        $this->assertSame(401, $this->req('POST', '/articles', [], ['title' => 'x'])->getStatusCode());
    }

    public function testCreateStartsAsDraft(): void
    {
        $res = $this->req('POST', '/articles', ['X-User-Id' => '1'], ['title' => 'New', 'status' => 'published']);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('draft', $this->json($res)['status']); // status in body is ignored (mass-assignment)
    }

    public function testCreateRequiresTitle(): void
    {
        $this->assertSame(422, $this->req('POST', '/articles', ['X-User-Id' => '1'], ['body' => 'x'])->getStatusCode());
    }

    // ── state machine ───────────────────────────────────────────────────────────

    public function testPublishTransition(): void
    {
        $id = $this->createDraft();
        $res = $this->publish($id);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('published', $data['status']);
        $this->assertNotNull($data['published_at']);
    }

    public function testArchiveTransition(): void
    {
        $id = $this->createDraft();
        $this->publish($id);
        $res = $this->req('POST', '/articles/' . $id . '/archive', ['X-User-Id' => '1']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('archived', $this->json($res)['status']);
    }

    public function testCannotPublishTwice(): void
    {
        $id = $this->createDraft();
        $this->publish($id);
        $this->assertSame(422, $this->publish($id)->getStatusCode());
    }

    public function testCannotArchiveDraft(): void
    {
        $id = $this->createDraft();
        $this->assertSame(422, $this->req('POST', '/articles/' . $id . '/archive', ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testCannotEditPublished(): void
    {
        $id = $this->createDraft();
        $this->publish($id);
        $res = $this->req('PUT', '/articles/' . $id, ['X-User-Id' => '1'], ['title' => 'edited']);
        $this->assertSame(422, $res->getStatusCode()); // no re-open: published is immutable here
    }

    public function testCanEditDraft(): void
    {
        $id = $this->createDraft();
        $res = $this->req('PUT', '/articles/' . $id, ['X-User-Id' => '1'], ['title' => 'edited']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('edited', $this->json($res)['title']);
    }

    // ── author ownership / visibility ────────────────────────────────────────────

    public function testNonAuthorCannotSeeDraft(): void
    {
        $id = $this->createDraft('1');
        // other user → 404 (not 403), no existence leak
        $this->assertSame(404, $this->req('GET', '/articles/' . $id, ['X-User-Id' => '2'])->getStatusCode());
        // anonymous → 404 too
        $this->assertSame(404, $this->req('GET', '/articles/' . $id)->getStatusCode());
        // author → 200
        $this->assertSame(200, $this->req('GET', '/articles/' . $id, ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testPublishedVisibleToEveryone(): void
    {
        $id = $this->createDraft('1');
        $this->publish($id);
        $this->assertSame(200, $this->req('GET', '/articles/' . $id, ['X-User-Id' => '2'])->getStatusCode());
        $this->assertSame(200, $this->req('GET', '/articles/' . $id)->getStatusCode());
    }

    public function testNonAuthorCannotPublish(): void
    {
        $id = $this->createDraft('1');
        $this->assertSame(404, $this->publish($id, '2')->getStatusCode()); // 404, not 403
        // still a draft
        $this->assertSame('draft', $this->json($this->req('GET', '/articles/' . $id, ['X-User-Id' => '1']))['status']);
    }

    // ── public list ──────────────────────────────────────────────────────────────

    public function testListShowsOnlyPublished(): void
    {
        $this->createDraft('1', 'unpublished');
        $pub = $this->createDraft('1', 'live');
        $this->publish($pub);
        $data = $this->json($this->req('GET', '/articles'));
        $this->assertSame(1, $data['count']);
        $this->assertSame('live', $data['articles'][0]['title']);
    }

    public function testListSameSecondTiebreakByIdDesc(): void
    {
        // Three articles published "at the same second" — order must be id DESC.
        $ids = [$this->createDraft('1', 'a'), $this->createDraft('1', 'b'), $this->createDraft('1', 'c')];
        foreach ($ids as $id) {
            $this->publish($id);
        }
        $titles = array_map(static fn (array $a): string => $a['title'], $this->json($this->req('GET', '/articles'))['articles']);
        $this->assertSame(['c', 'b', 'a'], $titles); // highest id first
    }

    public function testListLimitGuard(): void
    {
        $this->assertSame(422, $this->req('GET', '/articles', [], null, 'limit=0')->getStatusCode());
    }
}
