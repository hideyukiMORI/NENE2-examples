<?php

declare(strict_types=1);

namespace FileLog\Tests\File;

use FileLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * 脆弱性診断テスト — FT156
 *
 * VULN-A: IDOR — 他ユーザーのプライベートファイルへの直接アクセス
 * VULN-B: IDOR — 他ユーザーのファイルを削除
 * VULN-C: IDOR — 他ユーザーのファイルを更新
 * VULN-D: 権限昇格 — 閲覧共有から編集へのアップグレード試行
 * VULN-E: 所有権なりすまし — body の user_id でオーナー書き換え試行
 * VULN-F: 共有相手になりすまし — 自分を共有から勝手に削除
 * VULN-G: SQL インジェクション — ファイル名にインジェクション文字列
 * VULN-H: 大量データ — 極端に長い name でのサービス妨害
 * VULN-I: 型混乱攻撃 — size に浮動小数点
 * VULN-J: 公開設定エスカレーション — 編集共有者が visibility を変更
 * VULN-K: 存在推測 — 共有なしのプライベートファイルに対して 403 ではなく 404
 * VULN-L: 認証バイパス — X-User-Id: 0 / 負値
 */
class VulnTest extends TestCase
{
    private PDO $pdo;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/vuln-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $this->pdo->exec($schema);
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Alice', '2026-01-01T00:00:00Z')");
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Bob', '2026-01-01T00:00:00Z')");
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

    private function createAliceFile(string $visibility = 'private'): int
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '1'], [
            'name' => 'alice-secret.txt',
            'size' => 512,
            'mime_type' => 'text/plain',
            'visibility' => $visibility,
        ]);
        $data = json_decode((string) $res->getBody(), true);
        assert(is_array($data));
        return (int) $data['id'];
    }

    /** VULN-A: IDOR — 他ユーザーのプライベートファイルへの直接アクセスは 404 */
    public function testVulnAIdorGetPrivateFile(): void
    {
        $fileId = $this->createAliceFile('private');
        $res = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '2']);
        $this->assertSame(404, $res->getStatusCode(), 'VULN-A: private file must not be accessible by other users');
    }

    /** VULN-B: IDOR — 他ユーザーのファイルを削除できない */
    public function testVulnBIdorDeleteFile(): void
    {
        $fileId = $this->createAliceFile();
        $res = $this->req('DELETE', "/files/{$fileId}", ['X-User-Id' => '2']);
        $this->assertSame(404, $res->getStatusCode(), 'VULN-B: cannot delete other user file');

        // Confirm file still exists for owner
        $check = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '1']);
        $this->assertSame(200, $check->getStatusCode(), 'VULN-B: file must still exist after failed delete');
    }

    /** VULN-C: IDOR — 他ユーザーのファイルを更新できない */
    public function testVulnCIdorUpdateFile(): void
    {
        $fileId = $this->createAliceFile();
        $res = $this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '2'], [
            'name' => 'hacked.txt',
            'size' => 1,
            'mime_type' => 'text/plain',
        ]);
        $this->assertSame(404, $res->getStatusCode(), 'VULN-C: cannot update other user file');

        $check = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '1']);
        $data = json_decode((string) $check->getBody(), true);
        assert(is_array($data));
        $this->assertSame('alice-secret.txt', $data['name'], 'VULN-C: file name must not have changed');
    }

    /** VULN-D: 権限昇格 — view 共有者が edit で更新不可 */
    public function testVulnDViewShareCannotEdit(): void
    {
        $fileId = $this->createAliceFile();
        $this->req('POST', "/files/{$fileId}/shares", ['X-User-Id' => '1'], ['user_id' => 2, 'can_edit' => false]);

        $res = $this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '2'], [
            'name' => 'escalated.txt',
            'size' => 1,
            'mime_type' => 'text/plain',
        ]);
        $this->assertSame(403, $res->getStatusCode(), 'VULN-D: view-share must not be able to edit');
    }

    /** VULN-E: 所有権なりすまし — body の user_id を無視 */
    public function testVulnEOwnershipInjection(): void
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '1'], [
            'name' => 'test.txt',
            'size' => 1,
            'mime_type' => 'text/plain',
            'user_id' => 2, // Should be ignored
        ]);
        $data = json_decode((string) $res->getBody(), true);
        assert(is_array($data));
        $this->assertSame(1, $data['user_id'], 'VULN-E: user_id in body must be ignored, owner must be X-User-Id');
    }

    /** VULN-F: 共有削除なりすまし — 共有相手自身が共有を削除できない */
    public function testVulnFShareRemovalByNonOwner(): void
    {
        $fileId = $this->createAliceFile();
        $this->req('POST', "/files/{$fileId}/shares", ['X-User-Id' => '1'], ['user_id' => 2]);

        // Bob tries to remove himself from the share (only Alice should be able to do this)
        $res = $this->req('DELETE', "/files/{$fileId}/shares/2", ['X-User-Id' => '2']);
        $this->assertSame(404, $res->getStatusCode(), 'VULN-F: only owner can remove shares');
    }

    /** VULN-G: SQL インジェクション — ファイル名にインジェクション文字列 */
    public function testVulnGSqlInjectionInName(): void
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '1'], [
            'name' => "test'; DROP TABLE files; --",
            'size' => 1,
            'mime_type' => 'text/plain',
        ]);
        // Must not crash
        $this->assertNotSame(500, $res->getStatusCode(), 'VULN-G: SQL injection in name must not cause 500');

        // Files table must still exist
        $listRes = $this->req('GET', '/files', ['X-User-Id' => '1']);
        $this->assertSame(200, $listRes->getStatusCode(), 'VULN-G: files table must still exist after injection attempt');
    }

    /** VULN-H: 長すぎる name — 422 を返す（500 にならない） */
    public function testVulnHOversizedName(): void
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '1'], [
            'name' => str_repeat('a', 300),
            'size' => 1,
            'mime_type' => 'text/plain',
        ]);
        $this->assertSame(422, $res->getStatusCode(), 'VULN-H: oversized name must return 422, not 500');
    }

    /** VULN-I: 型混乱 — size にフロート値 */
    public function testVulnITypeMixupFloatSize(): void
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '1'], [
            'name' => 'test.txt',
            'size' => 1.5,
            'mime_type' => 'text/plain',
        ]);
        $this->assertSame(422, $res->getStatusCode(), 'VULN-I: float size must return 422');
    }

    /** VULN-J: 編集共有者が visibility を変更できない */
    public function testVulnJEditShareCannotChangeVisibility(): void
    {
        $fileId = $this->createAliceFile('private');
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
        $this->assertSame('private', $data['visibility'], 'VULN-J: edit-share must not escalate visibility');
    }

    /** VULN-K: 存在推測防止 — 403 ではなく 404 を返す */
    public function testVulnKExistenceNonDisclosure(): void
    {
        $fileId = $this->createAliceFile('private');
        $res = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '2']);
        $this->assertSame(404, $res->getStatusCode(), 'VULN-K: private file existence must not be disclosed (404 not 403)');
    }

    /** VULN-L: 認証バイパス — X-User-Id: 0 や負値 */
    public function testVulnLInvalidUserIdHeader(): void
    {
        $res0 = $this->req('GET', '/files', ['X-User-Id' => '0']);
        $this->assertSame(401, $res0->getStatusCode(), 'VULN-L: X-User-Id: 0 must be rejected');

        $resNeg = $this->req('GET', '/files', ['X-User-Id' => '-1']);
        $this->assertSame(401, $resNeg->getStatusCode(), 'VULN-L: negative X-User-Id must be rejected');
    }
}
