<?php

declare(strict_types=1);

namespace FeedLog\Tests\Feed;

use FeedLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 脆弱性診断テスト — FT153 feedlog
 *
 * VULN-A: 未認証でのフィード取得
 * VULN-B: 未認証でのアクティビティ投稿
 * VULN-C: 他ユーザーのプライベートアクティビティ盗み見
 * VULN-D: 他ユーザー名義でのアクティビティ投稿
 * VULN-E: SQL インジェクション（ユーザー ID パラメーター）
 * VULN-F: SQL インジェクション（サマリーフィールド）
 * VULN-G: 不正な type 値でのアクティビティ投稿
 * VULN-H: 自己フォロー
 * VULN-I: ユーザー ID をリクエストボディから注入（X-User-Id ヘッダーを使うべき）
 * VULN-J: 存在しないユーザーのアクティビティ取得（情報漏洩なし）
 * VULN-K: 超大量 summary（DoS 防止）
 * VULN-L: フォロー関係のないユーザーのプライベートアクティビティがフィードに露出しない
 */
final class VulnTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/feedlog-vuln-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $pdo->exec("INSERT INTO users (name, created_at) VALUES ('Alice', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO users (name, created_at) VALUES ('Bob', '2026-01-01 00:00:00')");
        unset($pdo);

        $this->app = AppFactory::createSqlite($this->dbFile);
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $headers */
    private function req(string $method, string $uri, mixed $body = null, array $headers = []): ResponseInterface
    {
        $req = new ServerRequest($method, $uri);
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')
                       ->withBody((new Psr17Factory())->createStream(
                           empty($body) ? '{}' : (json_encode($body) ?: '{}')
                       ));
        }
        return $this->app->handle($req);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        return (array) json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** VULN-A: 未認証でのフィード取得 → 401 */
    public function testVulnAUnauthenticatedFeedAccess(): void
    {
        $res = $this->req('GET', '/feed');
        $this->assertSame(401, $res->getStatusCode(), 'VULN-A: must require auth');
    }

    /** VULN-B: 未認証でのアクティビティ投稿 → 401 */
    public function testVulnBUnauthenticatedPostActivity(): void
    {
        $res = $this->req('POST', '/users/1/activities', ['type' => 'post', 'summary' => 'Hi']);
        $this->assertSame(401, $res->getStatusCode(), 'VULN-B: must require auth for post');
    }

    /** VULN-C: 他ユーザーのプライベートアクティビティ盗み見 → 公開のみ表示 */
    public function testVulnCPrivateActivityNotVisibleToOthers(): void
    {
        // Alice posts a private activity
        $this->req('POST', '/users/1/activities', [
            'type' => 'post', 'summary' => 'Top secret', 'is_public' => false,
        ], ['X-User-Id' => '1', 'Content-Type' => 'application/json']);

        // Bob should not see it
        $res = $this->req('GET', '/users/1/activities', null, ['X-User-Id' => '2']);
        $body = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([], $body['items'], 'VULN-C: private activity must not leak to other users');
    }

    /** VULN-D: 他ユーザー名義でのアクティビティ投稿 → 403 */
    public function testVulnDPostActivityAsAnotherUser(): void
    {
        // Alice (ID=1) tries to post as Bob (ID=2)
        $res = $this->req('POST', '/users/2/activities', [
            'type' => 'post', 'summary' => 'Impersonation',
        ], ['X-User-Id' => '1']);
        $this->assertSame(403, $res->getStatusCode(), 'VULN-D: impersonation must be blocked');
    }

    /** VULN-E: SQL インジェクション（ユーザー ID パラメーター）→ 整数キャストで無効化・500 なし */
    public function testVulnESqlInjectionInUserId(): void
    {
        // Path param is cast to int — "1 OR 1=1" becomes 1, "0 UNION..." becomes 0
        // Parameterized queries prevent any SQL injection from the path
        $res = $this->req('GET', '/users/0/activities', null, ['X-User-Id' => '1']);
        // ID 0 doesn't exist → 404, not 500 (no SQL error) and not 200 with unexpected data
        $this->assertSame(404, $res->getStatusCode(), 'VULN-E: invalid user ID must return 404, not SQL error');
        $this->assertNotSame(500, $res->getStatusCode(), 'VULN-E: parameterized query must not crash on unusual IDs');
    }

    /** VULN-F: SQL インジェクション（summary フィールド）→ 正常保存（インジェクション無効） */
    public function testVulnFSqlInjectionInSummary(): void
    {
        $maliciousSummary = "'); DROP TABLE activities; --";
        $res = $this->req('POST', '/users/1/activities', [
            'type' => 'post', 'summary' => $maliciousSummary,
        ], ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(201, $res->getStatusCode(), 'VULN-F: malicious summary must not cause error');
        $this->assertSame($maliciousSummary, $body['summary'], 'VULN-F: stored as-is via parameterized query');

        // Verify activities table still exists and has one row
        $activitiesRes = $this->req('GET', '/users/1/activities', null, ['X-User-Id' => '1']);
        $this->assertSame(200, $activitiesRes->getStatusCode(), 'VULN-F: table must survive injection attempt');
    }

    /** VULN-G: 不正な type 値でのアクティビティ投稿 → 422 */
    public function testVulnGInvalidActivityType(): void
    {
        $res = $this->req('POST', '/users/1/activities', [
            'type' => 'delete_all', 'summary' => 'Hack attempt',
        ], ['X-User-Id' => '1']);
        $this->assertSame(422, $res->getStatusCode(), 'VULN-G: invalid type must be rejected');
    }

    /** VULN-H: 自己フォロー → 422 */
    public function testVulnHSelfFollow(): void
    {
        $res = $this->req('POST', '/users/1/follow', null, ['X-User-Id' => '1']);
        $this->assertSame(422, $res->getStatusCode(), 'VULN-H: self-follow must be rejected');
    }

    /** VULN-I: user_id をリクエストボディから注入 → X-User-Id ヘッダーが使われる */
    public function testVulnIUserIdBodyInjection(): void
    {
        // Alice (ID=1) tries to inject user_id=2 in body to post as Bob
        $res = $this->req('POST', '/users/1/activities', [
            'type' => 'post', 'summary' => 'Injected', 'actor_id' => 2,
        ], ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(1, $body['actor_id'], 'VULN-I: actor_id must come from X-User-Id, not body');
    }

    /** VULN-J: 存在しないユーザーのアクティビティ取得 → 404（情報漏洩なし） */
    public function testVulnJNonExistentUserActivities(): void
    {
        $res = $this->req('GET', '/users/99999/activities', null, ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode(), 'VULN-J: non-existent user must return 404');
    }

    /** VULN-K: 超大量 summary（大量データ投入防止） */
    public function testVulnKLargeSummaryRejectedOrHandled(): void
    {
        $hugeSummary = str_repeat('A', 100_000);
        $res = $this->req('POST', '/users/1/activities', [
            'type' => 'post', 'summary' => $hugeSummary,
        ], ['X-User-Id' => '1']);
        // Either reject (4xx) or accept without crashing — must not 500
        $this->assertNotSame(500, $res->getStatusCode(), 'VULN-K: must not crash on large input');
    }

    /** VULN-L: フォロー関係のないユーザーのプライベートアクティビティがフィードに露出しない */
    public function testVulnLPrivateActivityNotInFeedOfNonFollower(): void
    {
        // Bob posts private activity
        $this->req('POST', '/users/2/activities', [
            'type' => 'post', 'summary' => 'Bob secret', 'is_public' => false,
        ], ['X-User-Id' => '2']);

        // Alice (not following Bob) checks her feed
        $res = $this->req('GET', '/feed', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame([], $body['items'], 'VULN-L: private activity of non-followed user must not appear in feed');
    }
}
