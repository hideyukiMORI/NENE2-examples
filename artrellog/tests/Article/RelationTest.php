<?php

declare(strict_types=1);

namespace Relations\Tests\Article;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Relations\AppFactory;

class RelationTest extends TestCase
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

    private function article(string $title): int
    {
        $res = $this->req('POST', '/articles', ['title' => $title, 'body' => 'content']);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    // ── article ────────────────────────────────────────────────────────────

    public function testCreateRequiresTitleAndBody(): void
    {
        $this->assertSame(422, $this->req('POST', '/articles', ['body' => 'x'])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/articles', ['title' => 'x'])->getStatusCode());
    }

    // ── symmetric relation ────────────────────────────────────────────────────

    public function testSymmetricRelatedCreatesBothDirections(): void
    {
        $a = $this->article('A');
        $b = $this->article('B');
        $this->assertSame(201, $this->req('POST', "/articles/{$a}/relations", ['related_id' => $b, 'relation_type' => 'related'])->getStatusCode());

        // A → B related
        $aRel = $this->json($this->req('GET', "/articles/{$a}/relations"));
        $this->assertSame('related', $aRel['relations'][0]['relation']['relation_type']);
        $this->assertSame($b, $aRel['relations'][0]['related']['id']);
        // B → A related (inverse, symmetric)
        $bRel = $this->json($this->req('GET', "/articles/{$b}/relations"));
        $this->assertSame('related', $bRel['relations'][0]['relation']['relation_type']);
        $this->assertSame($a, $bRel['relations'][0]['related']['id']);
    }

    // ── asymmetric relation: sequel ↔ prequel ──────────────────────────────────

    public function testSequelCreatesPrequelInverse(): void
    {
        $a = $this->article('Part 1');
        $b = $this->article('Part 2');
        // A is sequel of B  →  B is prequel of A
        $this->req('POST', "/articles/{$a}/relations", ['related_id' => $b, 'relation_type' => 'sequel']);

        $this->assertSame('sequel', $this->json($this->req('GET', "/articles/{$a}/relations"))['relations'][0]['relation']['relation_type']);
        $this->assertSame('prequel', $this->json($this->req('GET', "/articles/{$b}/relations"))['relations'][0]['relation']['relation_type']);
    }

    // ── embedded relations in GET ───────────────────────────────────────────────

    public function testGetEmbedsRelations(): void
    {
        $a = $this->article('A');
        $b = $this->article('B');
        $this->req('POST', "/articles/{$a}/relations", ['related_id' => $b, 'relation_type' => 'reference']);
        $data = $this->json($this->req('GET', "/articles/{$a}"));
        $this->assertSame('A', $data['data']['title']);
        $this->assertCount(1, $data['relations']);
        $this->assertSame('B', $data['relations'][0]['related']['title']);
    }

    // ── filter by type ──────────────────────────────────────────────────────────

    public function testFilterByType(): void
    {
        $a = $this->article('A');
        $b = $this->article('B');
        $c = $this->article('C');
        $this->req('POST', "/articles/{$a}/relations", ['related_id' => $b, 'relation_type' => 'related']);
        $this->req('POST', "/articles/{$a}/relations", ['related_id' => $c, 'relation_type' => 'sequel']);

        $sequels = $this->json($this->req('GET', "/articles/{$a}/relations", null, ['type' => 'sequel']));
        $this->assertCount(1, $sequels['relations']);
        $this->assertSame($c, $sequels['relations'][0]['related']['id']);
    }

    // ── delete removes both directions ───────────────────────────────────────────

    public function testDeleteRemovesInverse(): void
    {
        $a = $this->article('A');
        $b = $this->article('B');
        $this->req('POST', "/articles/{$a}/relations", ['related_id' => $b, 'relation_type' => 'sequel']);

        $this->assertSame(204, $this->req('DELETE', "/articles/{$a}/relations/{$b}", null, ['type' => 'sequel'])->getStatusCode());
        $this->assertCount(0, $this->json($this->req('GET', "/articles/{$a}/relations"))['relations']);
        // inverse (B prequel A) is gone too
        $this->assertCount(0, $this->json($this->req('GET', "/articles/{$b}/relations"))['relations']);
    }

    // ── validation / edge cases ───────────────────────────────────────────────────

    public function testUnknownRelationTypeRejected(): void
    {
        $a = $this->article('A');
        $b = $this->article('B');
        $this->assertSame(422, $this->req('POST', "/articles/{$a}/relations", ['related_id' => $b, 'relation_type' => 'cousin'])->getStatusCode());
    }

    public function testSelfRelationRejected(): void
    {
        $a = $this->article('A');
        $this->assertSame(422, $this->req('POST', "/articles/{$a}/relations", ['related_id' => $a, 'relation_type' => 'related'])->getStatusCode());
    }

    public function testRelatedArticleMustExist(): void
    {
        $a = $this->article('A');
        $this->assertSame(404, $this->req('POST', "/articles/{$a}/relations", ['related_id' => 999, 'relation_type' => 'related'])->getStatusCode());
    }

    public function testDuplicateRelationConflicts(): void
    {
        $a = $this->article('A');
        $b = $this->article('B');
        $this->req('POST', "/articles/{$a}/relations", ['related_id' => $b, 'relation_type' => 'related']);
        $this->assertSame(409, $this->req('POST', "/articles/{$a}/relations", ['related_id' => $b, 'relation_type' => 'related'])->getStatusCode());
    }
}
