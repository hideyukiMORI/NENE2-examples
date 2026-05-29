<?php

declare(strict_types=1);

namespace UnicodeLog\Tests\Profile;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use UnicodeLog\AppFactory;

class UnicodeTest extends TestCase
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

    /** @param array<string, mixed> $body */
    private function req(string $method, string $path, mixed $body = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
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
    private function create(array $body): ResponseInterface
    {
        return $this->req('POST', '/profiles', $body);
    }

    // ── multi-script acceptance ────────────────────────────────────────────────

    public function testJapanese(): void
    {
        $res = $this->create(['name' => '田中太郎', 'bio' => 'プログラマーです', 'tags' => ['エンジニア', 'PHP']]);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('田中太郎', $data['name']);
        $this->assertSame(['エンジニア', 'PHP'], $data['tags']);
    }

    public function testEmoji(): void
    {
        $res = $this->create(['name' => '🎉 Yuki 🎊', 'bio' => 'I love emojis! 🚀✨', 'tags' => ['🎨', '🎵']]);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('🎉 Yuki 🎊', $this->json($res)['name']);
    }

    public function testArabicAndMixed(): void
    {
        $this->assertSame(201, $this->create(['name' => 'محمد علي', 'bio' => 'مبرمج ويب', 'tags' => ['مطور']])->getStatusCode());
        $this->assertSame(201, $this->create(['name' => 'André García 鈴木', 'bio' => 'Café résumé naïve', 'tags' => ['日本語', 'español']])->getStatusCode());
    }

    // ── V-02 / V-08: mb_strlen counts codepoints, not bytes ──────────────────────

    public function testFiftyJapaneseCharsPass(): void
    {
        // 50 × "あ" = 150 bytes but 50 codepoints → must PASS (strlen would wrongly reject)
        $res = $this->create(['name' => str_repeat('あ', 50)]);
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testFiftyOneJapaneseCharsRejected(): void
    {
        $this->assertSame(422, $this->create(['name' => str_repeat('あ', 51)])->getStatusCode());
    }

    public function testFiftyEmojiPass(): void
    {
        // 50 × 🎉 = 200 bytes, 50 codepoints → PASS
        $this->assertSame(201, $this->create(['name' => str_repeat('🎉', 50)])->getStatusCode());
    }

    public function testZwjSequenceStoredVerbatim(): void // V-08
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"; // 5 codepoints
        $res = $this->create(['name' => $family]);
        $this->assertSame(201, $res->getStatusCode());
        // returned verbatim, not normalized
        $this->assertSame($family, $this->json($res)['name']);
    }

    // ── V-01: null byte rejection ────────────────────────────────────────────────

    public function testNullByteInNameRejected(): void
    {
        $this->assertSame(422, $this->create(['name' => "Alice\x00Bob"])->getStatusCode());
    }

    public function testNullByteInBioRejected(): void
    {
        $this->assertSame(422, $this->create(['name' => 'Valid', 'bio' => "bio with \x00 null"])->getStatusCode());
    }

    public function testNullByteInTagRejected(): void
    {
        $this->assertSame(422, $this->create(['name' => 'Valid', 'tags' => ["tag\x00bad"]])->getStatusCode());
    }

    // ── V-03 / V-06: tag validation ──────────────────────────────────────────────

    public function testTooManyTagsRejected(): void
    {
        $tags = array_fill(0, 11, 'x');
        $this->assertSame(422, $this->create(['name' => 'Valid', 'tags' => $tags])->getStatusCode());
    }

    public function testNonStringTagRejected(): void
    {
        $this->assertSame(422, $this->create(['name' => 'Valid', 'tags' => [42]])->getStatusCode());
    }

    public function testTagTooLongRejected(): void
    {
        $this->assertSame(422, $this->create(['name' => 'Valid', 'tags' => [str_repeat('あ', 31)]])->getStatusCode());
    }

    public function testThirtyCharTagPasses(): void
    {
        $this->assertSame(201, $this->create(['name' => 'Valid', 'tags' => [str_repeat('あ', 30)]])->getStatusCode());
    }

    // ── empty name ─────────────────────────────────────────────────────────────────

    public function testEmptyNameRejected(): void
    {
        $this->assertSame(422, $this->create(['name' => ''])->getStatusCode());
    }

    // ── V-04: SQL injection via Unicode payload is inert ─────────────────────────────

    public function testSqlInjectionPayloadStoredVerbatim(): void
    {
        $payload = "'; DROP TABLE profiles; --";
        $res = $this->create(['name' => $payload]);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame($payload, $this->json($res)['name']);
        // table still works
        $this->assertSame(201, $this->create(['name' => 'still here'])->getStatusCode());
    }

    // ── V-05: homograph names coexist (documented EXPOSED limitation) ─────────────────

    public function testHomographNamesCoexist(): void
    {
        // 'admin' (Latin) vs 'аdmin' (Cyrillic а). No normalization → both stored.
        $this->assertSame(201, $this->create(['name' => 'admin'])->getStatusCode());
        $this->assertSame(201, $this->create(['name' => "\u{0430}dmin"])->getStatusCode());
        // This is the known V-05 limitation: verbatim storage allows visual look-alikes.
        $this->assertSame(2, $this->json($this->req('GET', '/profiles'))['total']);
    }

    // ── round-trip / update / delete ─────────────────────────────────────────────────

    public function testUpdateAndDelete(): void
    {
        $id = (int) $this->json($this->create(['name' => 'Original']))['id'];
        $res = $this->req('PATCH', '/profiles/' . $id, ['name' => '更新済み', 'tags' => ['新']]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('更新済み', $this->json($res)['name']);
        $this->assertSame(204, $this->req('DELETE', '/profiles/' . $id)->getStatusCode());
        $this->assertSame(404, $this->req('GET', '/profiles/' . $id)->getStatusCode());
    }

    public function testTagsReturnedAsArrayNotString(): void
    {
        $res = $this->create(['name' => 'x', 'tags' => ['a', 'b']]);
        $this->assertIsArray($this->json($res)['tags']);
    }
}
