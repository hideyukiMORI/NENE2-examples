<?php

declare(strict_types=1);

namespace I18nLog\Tests\Content;

use I18nLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class ContentTest extends TestCase
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

    /** @param array<string, mixed> $body */
    private function article(array $body = ['default_locale' => 'en', 'published' => true]): int
    {
        $res = $this->req('POST', '/articles', $body);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    private function translate(int $id, string $locale, string $title, string $body): ResponseInterface
    {
        return $this->req('PUT', "/articles/{$id}/translations/{$locale}", ['title' => $title, 'body' => $body]);
    }

    // ── article creation ─────────────────────────────────────────────────────

    public function testCreateArticle(): void
    {
        $id = $this->article(['default_locale' => 'en', 'published' => true]);
        $data = $this->json($this->req('GET', '/articles/' . $id));
        $this->assertSame('en', $data['default_locale']);
        $this->assertTrue($data['published']);
    }

    public function testInvalidDefaultLocaleRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/articles', ['default_locale' => 'EN'])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/articles', ['default_locale' => 'en_US'])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/articles', ['default_locale' => '../../etc'])->getStatusCode());
    }

    public function testPublishedRequiresStrictTrue(): void
    {
        // "true" string / 1 are NOT strict true → draft
        $id = $this->article(['default_locale' => 'en', 'published' => 'true']);
        $this->assertFalse($this->json($this->req('GET', '/articles/' . $id))['published']);
        // drafts excluded from list
        $this->assertSame(0, $this->json($this->req('GET', '/articles'))['count']);
    }

    // ── translations / upsert ────────────────────────────────────────────────

    public function testUpsertCreatesThenUpdates(): void
    {
        $id = $this->article();
        $this->assertSame(201, $this->translate($id, 'en', 'Hello', 'World')->getStatusCode());
        $this->assertSame(200, $this->translate($id, 'en', 'Hello v2', 'World v2')->getStatusCode());

        $data = $this->json($this->req('GET', '/articles/' . $id, null, ['locale' => 'en']));
        $this->assertSame('Hello v2', $data['content']['title']);
    }

    public function testInvalidLocaleInPathRejected(): void
    {
        $id = $this->article();
        $this->assertSame(422, $this->translate($id, 'EN', 'x', 'y')->getStatusCode());
        $this->assertSame(422, $this->translate($id, 'english', 'x', 'y')->getStatusCode());
    }

    public function testRegionLocaleAccepted(): void
    {
        $id = $this->article(['default_locale' => 'fr-FR', 'published' => true]);
        $this->assertSame(201, $this->translate($id, 'fr-FR', 'Bonjour', 'Monde')->getStatusCode());
    }

    // ── locale fallback ────────────────────────────────────────────────────────

    public function testLocaleFallbackToDefault(): void
    {
        $id = $this->article(['default_locale' => 'en', 'published' => true]);
        $this->translate($id, 'en', 'Hello', 'World');

        // request ja → no ja translation → falls back to en (default)
        $data = $this->json($this->req('GET', '/articles/' . $id, null, ['locale' => 'ja']));
        $this->assertSame('en', $data['resolved_locale']);
        $this->assertSame('Hello', $data['content']['title']);
    }

    public function testRequestedLocaleWins(): void
    {
        $id = $this->article(['default_locale' => 'en', 'published' => true]);
        $this->translate($id, 'en', 'Hello', 'World');
        $this->translate($id, 'ja', 'こんにちは', '世界');

        $data = $this->json($this->req('GET', '/articles/' . $id, null, ['locale' => 'ja']));
        $this->assertSame('ja', $data['resolved_locale']);
        $this->assertSame('こんにちは', $data['content']['title']);
        $this->assertSame(['en', 'ja'], $data['available_locales']);
    }

    public function testNoTranslationYieldsNullContent(): void
    {
        $id = $this->article();
        $data = $this->json($this->req('GET', '/articles/' . $id));
        $this->assertNull($data['content']);
        $this->assertNull($data['resolved_locale']);
    }

    public function testInvalidLocaleQueryRejected(): void
    {
        $id = $this->article();
        $this->assertSame(422, $this->req('GET', '/articles/' . $id, null, ['locale' => 'BOGUS'])->getStatusCode());
    }

    // ── list shows published only ───────────────────────────────────────────────

    public function testListShowsPublishedOnly(): void
    {
        $this->article(['default_locale' => 'en', 'published' => true]);
        $this->article(['default_locale' => 'en', 'published' => false]);
        $this->assertSame(1, $this->json($this->req('GET', '/articles'))['count']);
    }

    public function testTranslateUnknownArticleIs404(): void
    {
        $this->assertSame(404, $this->translate(999, 'en', 'x', 'y')->getStatusCode());
    }
}
