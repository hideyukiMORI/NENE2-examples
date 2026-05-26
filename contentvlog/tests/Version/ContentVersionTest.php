<?php

declare(strict_types=1);

namespace ContentVLog\Tests\Version;

use ContentVLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ContentVersionTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/contentvlog_test_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $this->app = AppFactory::createSqlite($this->dbFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function req(string $method, string $path, mixed $body = null): ResponseInterface
    {
        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $res->getBody(), true);
    }

    private function createArticle(string $title = 'Hello', string $body = 'World'): int
    {
        $res = $this->req('POST', '/articles', ['title' => $title, 'body' => $body]);
        return (int) $this->json($res)['id'];
    }

    // =========================================================================
    // Create

    public function testCreateArticleReturnsV1(): void
    {
        $res  = $this->req('POST', '/articles', ['title' => 'My Post', 'body' => 'Content here']);
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('My Post', $data['title']);
        $this->assertSame(1, $data['current_version']);
    }

    public function testCreateRequiresTitle(): void
    {
        $res = $this->req('POST', '/articles', ['body' => 'no title']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateRequiresBody(): void
    {
        $res = $this->req('POST', '/articles', ['title' => 'no body']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // =========================================================================
    // Get latest

    public function testGetArticleReturnsLatest(): void
    {
        $id = $this->createArticle('Original', 'First draft');
        $this->req('PUT', "/articles/{$id}", ['title' => 'Revised', 'body' => 'Updated content']);

        $data = $this->json($this->req('GET', "/articles/{$id}"));
        $this->assertSame('Revised', $data['title']);
        $this->assertSame(2, $data['current_version']);
    }

    public function testGetNonexistentArticleReturns404(): void
    {
        $res = $this->req('GET', '/articles/9999');
        $this->assertSame(404, $res->getStatusCode());
    }

    // =========================================================================
    // Update (creates new version)

    public function testUpdateIncrementsVersion(): void
    {
        $id = $this->createArticle();

        $this->req('PUT', "/articles/{$id}", ['title' => 'V2 title', 'body' => 'V2 body']);
        $data = $this->json($this->req('GET', "/articles/{$id}"));
        $this->assertSame(2, $data['current_version']);

        $this->req('PUT', "/articles/{$id}", ['title' => 'V3 title', 'body' => 'V3 body']);
        $data = $this->json($this->req('GET', "/articles/{$id}"));
        $this->assertSame(3, $data['current_version']);
    }

    public function testUpdateNonexistentReturns404(): void
    {
        $res = $this->req('PUT', '/articles/9999', ['title' => 'X', 'body' => 'Y']);
        $this->assertSame(404, $res->getStatusCode());
    }

    // =========================================================================
    // List versions

    public function testListVersionsAfterThreeEdits(): void
    {
        $id = $this->createArticle('V1', 'draft one');
        $this->req('PUT', "/articles/{$id}", ['title' => 'V2', 'body' => 'draft two']);
        $this->req('PUT', "/articles/{$id}", ['title' => 'V3', 'body' => 'draft three']);

        $data = $this->json($this->req('GET', "/articles/{$id}/versions"));
        $this->assertSame(3, $data['count']);
        $this->assertSame(1, $data['versions'][0]['version']);
        $this->assertSame(3, $data['versions'][2]['version']);
    }

    public function testListVersionsForNonexistentArticleReturns404(): void
    {
        $res = $this->req('GET', '/articles/9999/versions');
        $this->assertSame(404, $res->getStatusCode());
    }

    // =========================================================================
    // Get specific version

    public function testGetSpecificVersionReturnsThatContent(): void
    {
        $id = $this->createArticle('Initial', 'First body');
        $this->req('PUT', "/articles/{$id}", ['title' => 'Updated', 'body' => 'Second body']);

        $v1 = $this->json($this->req('GET', "/articles/{$id}/versions/1"));
        $this->assertSame('Initial', $v1['title']);
        $this->assertSame('First body', $v1['body']);

        $v2 = $this->json($this->req('GET', "/articles/{$id}/versions/2"));
        $this->assertSame('Updated', $v2['title']);
    }

    public function testGetNonexistentVersionReturns404(): void
    {
        $id  = $this->createArticle();
        $res = $this->req('GET', "/articles/{$id}/versions/99");
        $this->assertSame(404, $res->getStatusCode());
    }

    // =========================================================================
    // Rollback

    public function testRollbackRestoresOlderVersion(): void
    {
        $id = $this->createArticle('Original title', 'Original body');
        $this->req('PUT', "/articles/{$id}", ['title' => 'Modified', 'body' => 'Changed body']);

        // Rollback to v1
        $res  = $this->req('POST', "/articles/{$id}/rollback", ['version' => 1]);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Original title', $data['title']);
        $this->assertSame(3, $data['current_version']); // rollback creates new version
        $this->assertSame(1, $data['rolled_back_from']);
    }

    public function testRollbackCreatesNewVersionEntry(): void
    {
        $id = $this->createArticle();
        $this->req('PUT', "/articles/{$id}", ['title' => 'V2', 'body' => 'V2 body']);
        $this->req('POST', "/articles/{$id}/rollback", ['version' => 1]);

        $data = $this->json($this->req('GET', "/articles/{$id}/versions"));
        $this->assertSame(3, $data['count']); // v1, v2, v3(rollback)
    }

    public function testRollbackToNonexistentVersionReturns404(): void
    {
        $id  = $this->createArticle();
        $res = $this->req('POST', "/articles/{$id}/rollback", ['version' => 99]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testRollbackWithoutVersionReturns422(): void
    {
        $id  = $this->createArticle();
        $res = $this->req('POST', "/articles/{$id}/rollback", []);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testRollbackNonexistentArticleReturns404(): void
    {
        $res = $this->req('POST', '/articles/9999/rollback', ['version' => 1]);
        $this->assertSame(404, $res->getStatusCode());
    }

    // =========================================================================
    // Version history integrity

    public function testOldVersionsPreservedAfterUpdate(): void
    {
        $id = $this->createArticle('Alpha', 'Alpha body');
        $this->req('PUT', "/articles/{$id}", ['title' => 'Beta', 'body' => 'Beta body']);
        $this->req('PUT', "/articles/{$id}", ['title' => 'Gamma', 'body' => 'Gamma body']);

        $v1 = $this->json($this->req('GET', "/articles/{$id}/versions/1"));
        $v2 = $this->json($this->req('GET', "/articles/{$id}/versions/2"));
        $v3 = $this->json($this->req('GET', "/articles/{$id}/versions/3"));

        $this->assertSame('Alpha', $v1['title']);
        $this->assertSame('Beta', $v2['title']);
        $this->assertSame('Gamma', $v3['title']);
    }

    public function testVersionsAreArticleScoped(): void
    {
        $id1 = $this->createArticle('Article 1', 'Body 1');
        $id2 = $this->createArticle('Article 2', 'Body 2');
        $this->req('PUT', "/articles/{$id1}", ['title' => 'A1 v2', 'body' => 'Updated 1']);

        $versions1 = $this->json($this->req('GET', "/articles/{$id1}/versions"));
        $versions2 = $this->json($this->req('GET', "/articles/{$id2}/versions"));

        $this->assertSame(2, $versions1['count']);
        $this->assertSame(1, $versions2['count']); // article 2 has only v1
    }
}
