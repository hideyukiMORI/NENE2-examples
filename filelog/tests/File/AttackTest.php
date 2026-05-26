<?php

declare(strict_types=1);

namespace FileLog\Tests\File;

use FileLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * クラッカー攻撃試験 — FT156
 *
 * 実際の攻撃者がどんな入力を試みるかをシミュレートする。
 * すべてのテストは「攻撃が無効化されていること」を証明する。
 *
 * ATK-01: なりすまし — 他ユーザー ID でのファイル取得
 * ATK-02: なりすまし — 他ユーザー ID でのファイル削除
 * ATK-03: 共有昇格 — view-only 共有で更新試行
 * ATK-04: 所有権インジェクション — body に user_id を注入
 * ATK-05: パス操作 — /files/../ 形式の URL
 * ATK-06: 非標準 ID — 文字列 ID でファイル取得
 * ATK-07: ヘッダー操作 — 空の X-User-Id
 * ATK-08: SQL インジェクション — MIME type にインジェクション
 * ATK-09: 大量フィールド — 巨大 description でのサービス妨害
 * ATK-10: 可視性エスカレーション — 編集共有者による公開設定変更
 * ATK-11: 共有削除権限昇格 — 共有相手が自分の共有を削除
 * ATK-12: 存在確認 — 403 vs 404 によるファイル存在推測
 */
class AttackTest extends TestCase
{
    private PDO $pdo;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/atk-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $this->pdo->exec($schema);
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Victim', '2026-01-01T00:00:00Z')");
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Attacker', '2026-01-01T00:00:00Z')");
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Carol', '2026-01-01T00:00:00Z')");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $headers */
    private function req(string $method, string $path, array $headers = [], mixed $body = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $uri = $psr17->createUri('http://localhost' . $path);
        /** @var \Psr\Http\Message\ServerRequestInterface $request */
        $request = $psr17->createServerRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    private function createVictimFile(): int
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '1'], [
            'name' => 'private-doc.txt',
            'size' => 1024,
            'mime_type' => 'text/plain',
            'visibility' => 'private',
        ]);
        $data = json_decode((string) $res->getBody(), true);
        assert(is_array($data));
        return (int) $data['id'];
    }

    /** ATK-01: なりすまし GET */
    public function testAtk01ImpersonationGet(): void
    {
        $fileId = $this->createVictimFile();
        // Attacker (user 2) tries to access victim's (user 1) private file
        $res = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '2']);
        $this->assertSame(404, $res->getStatusCode(), 'ATK-01: attacker must not access victim private file');
    }

    /** ATK-02: なりすまし DELETE */
    public function testAtk02ImpersonationDelete(): void
    {
        $fileId = $this->createVictimFile();
        $res = $this->req('DELETE', "/files/{$fileId}", ['X-User-Id' => '2']);
        $this->assertSame(404, $res->getStatusCode(), 'ATK-02: attacker must not delete victim file');

        $check = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '1']);
        $this->assertSame(200, $check->getStatusCode(), 'ATK-02: file must survive delete attempt');
    }

    /** ATK-03: view-only 共有で編集試行 */
    public function testAtk03ViewShareEditAttempt(): void
    {
        $fileId = $this->createVictimFile();
        $this->req('POST', "/files/{$fileId}/shares", ['X-User-Id' => '1'], ['user_id' => 2, 'can_edit' => false]);

        $res = $this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '2'], [
            'name' => 'attacked.txt',
            'size' => 1,
            'mime_type' => 'text/plain',
        ]);
        $this->assertSame(403, $res->getStatusCode(), 'ATK-03: view-only share cannot edit');
    }

    /** ATK-04: body の user_id 注入でファイルオーナー書き換え試行 */
    public function testAtk04UserIdInjection(): void
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '2'], [
            'name' => 'attacker-file.txt',
            'size' => 100,
            'mime_type' => 'text/plain',
            'user_id' => 1, // Try to claim ownership as victim
        ]);
        $data = json_decode((string) $res->getBody(), true);
        assert(is_array($data));
        $this->assertSame(2, $data['user_id'], 'ATK-04: user_id injection must be ignored');
    }

    /** ATK-05: パス操作 URL */
    public function testAtk05PathTraversal(): void
    {
        $fileId = $this->createVictimFile();
        // Path traversal attempt — router will handle this as a separate route or 404
        $res = $this->req('GET', '/files/../files', ['X-User-Id' => '2']);
        $this->assertNotSame(500, $res->getStatusCode(), 'ATK-05: path traversal must not cause 500');
    }

    /** ATK-06: 文字列 ID でファイル取得 */
    public function testAtk06StringIdAccess(): void
    {
        $res = $this->req('GET', "/files/abc", ['X-User-Id' => '2']);
        $this->assertNotSame(500, $res->getStatusCode(), 'ATK-06: string ID must not cause 500');
        $this->assertSame(404, $res->getStatusCode(), 'ATK-06: string ID must return 404');
    }

    /** ATK-07: 空の X-User-Id ヘッダー */
    public function testAtk07EmptyUserIdHeader(): void
    {
        $res = $this->req('GET', '/files', ['X-User-Id' => '']);
        $this->assertSame(401, $res->getStatusCode(), 'ATK-07: empty X-User-Id must return 401');
    }

    /** ATK-08: MIME type に SQL インジェクション */
    public function testAtk08SqlInjectionInMimeType(): void
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '2'], [
            'name' => 'malicious.txt',
            'size' => 1,
            'mime_type' => "text/plain'; DROP TABLE files; --",
        ]);
        $this->assertNotSame(500, $res->getStatusCode(), 'ATK-08: SQL injection in mime_type must not cause 500');

        $listRes = $this->req('GET', '/files', ['X-User-Id' => '1']);
        $this->assertSame(200, $listRes->getStatusCode(), 'ATK-08: files table must survive SQL injection');
    }

    /** ATK-09: 巨大 description でサービス妨害試行 */
    public function testAtk09OversizedDescription(): void
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '2'], [
            'name' => 'test.txt',
            'size' => 1,
            'mime_type' => 'text/plain',
            'description' => str_repeat('A', 100_000),
        ]);
        // Should succeed (description has no length limit currently) or fail gracefully
        $this->assertNotSame(500, $res->getStatusCode(), 'ATK-09: large description must not cause 500');
    }

    /** ATK-10: 編集共有者による visibility エスカレーション */
    public function testAtk10VisibilityEscalation(): void
    {
        $fileId = $this->createVictimFile(); // private
        $this->req('POST', "/files/{$fileId}/shares", ['X-User-Id' => '1'], ['user_id' => 2, 'can_edit' => true]);

        $this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '2'], [
            'name' => 'test.txt',
            'size' => 1,
            'mime_type' => 'text/plain',
            'visibility' => 'public',
        ]);

        $check = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '1']);
        $data = json_decode((string) $check->getBody(), true);
        assert(is_array($data));
        $this->assertSame('private', $data['visibility'], 'ATK-10: edit-share must not escalate visibility to public');
    }

    /** ATK-11: 共有相手が自分の共有エントリを削除 */
    public function testAtk11SharedUserDeletesOwnShare(): void
    {
        $fileId = $this->createVictimFile();
        $this->req('POST', "/files/{$fileId}/shares", ['X-User-Id' => '1'], ['user_id' => 2]);

        // Attacker (user 2, shared with) tries to remove their own share entry
        $res = $this->req('DELETE', "/files/{$fileId}/shares/2", ['X-User-Id' => '2']);
        $this->assertSame(404, $res->getStatusCode(), 'ATK-11: shared user must not be able to remove own share');

        // Share must still exist — victim's file must still be accessible
        $check = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '2']);
        $this->assertSame(200, $check->getStatusCode(), 'ATK-11: share must persist after failed removal attempt');
    }

    /** ATK-12: 403 vs 404 でファイル存在確認 */
    public function testAtk12ExistenceProbing(): void
    {
        $fileId = $this->createVictimFile(); // private file owned by victim (user 1)
        $res = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '2']);
        // Must return 404, not 403 — to prevent confirming file existence
        $this->assertSame(404, $res->getStatusCode(), 'ATK-12: must return 404 not 403 for unauthorized private file');
        $this->assertNotSame(403, $res->getStatusCode(), 'ATK-12: 403 would reveal file existence');
    }
}
