<?php

declare(strict_types=1);

namespace InboundLog\Tests\Inbound;

use InboundLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * MySQL integration tests for FT167.
 * Skipped automatically when MYSQL_HOST is not set.
 */
final class MysqlInboundTest extends TestCase
{
    private bool $mysqlEnabled = false;
    private ?PDO $pdo = null;
    private ?RequestHandlerInterface $app = null;
    private const string SECRET = 'mysql-test-secret';

    protected function setUp(): void
    {
        $host = (string) (getenv('MYSQL_HOST') ?: '');
        if ($host === '') {
            self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
        }

        $this->mysqlEnabled = true;
        $port     = (int) (getenv('MYSQL_PORT') ?: 3306);
        $database = (string) (getenv('MYSQL_DATABASE') ?: 'ft_test');
        $user     = (string) (getenv('MYSQL_USER') ?: 'ft_user');
        $password = (string) (getenv('MYSQL_PASSWORD') ?: 'ft_pass');

        $dsn      = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS inbound_events');
        $this->pdo->exec('DROP TABLE IF EXISTS webhook_sources');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema_mysql.sql');
        $this->pdo->exec($schema);

        $this->app = AppFactory::createMysql($host, $port, $database, $user, $password);
    }

    protected function tearDown(): void
    {
        if ($this->mysqlEnabled && $this->pdo !== null) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->pdo->exec('DROP TABLE IF EXISTS inbound_events');
            $this->pdo->exec('DROP TABLE IF EXISTS webhook_sources');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** @param array<string, mixed> $payload */
    private function webhookReq(string $path, array $payload, string $secret = self::SECRET): ResponseInterface
    {
        assert($this->app !== null);
        $rawBody = json_encode($payload);
        assert($rawBody !== false);
        $sig     = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri('http://localhost' . $path);
        $stream  = $psr17->createStream($rawBody);
        $request = $psr17->createServerRequest('POST', $uri)
            ->withBody($stream)
            ->withHeader('X-Webhook-Signature', $sig)
            ->withHeader('Content-Type', 'application/json');
        return $this->app->handle($request);
    }

    private function req(string $method, string $path, mixed $body = null): ResponseInterface
    {
        assert($this->app !== null);
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

    private function createSource(string $name, string $secret = self::SECRET): int
    {
        $res = $this->req('POST', '/sources', ['name' => $name, 'secret' => $secret]);
        return (int) $this->json($res)['id'];
    }

    // MySQL-01: 基本的な受信とDB永続化
    public function testMysqlReceiveAndPersist(): void
    {
        $srcId = $this->createSource('mysql-src-1');
        $res   = $this->webhookReq("/sources/{$srcId}/receive", [
            'event_id' => 'mysql-evt-001', 'event_type' => 'order.created',
        ]);
        $this->assertSame(201, $res->getStatusCode());

        // Verify in DB
        assert($this->pdo !== null);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM inbound_events WHERE source_id = ?');
        $stmt->execute([$srcId]);
        $this->assertSame('1', (string) $stmt->fetchColumn());
    }

    // MySQL-02: UNIQUE制約（source_id + event_id）の冪等性
    public function testMysqlIdempotencyUniqueConstraint(): void
    {
        $srcId   = $this->createSource('mysql-src-2');
        $payload = ['event_id' => 'dup-evt', 'event_type' => 'payment.succeeded'];
        $this->webhookReq("/sources/{$srcId}/receive", $payload);

        $res  = $this->webhookReq("/sources/{$srcId}/receive", $payload);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('already_processed', $data['status']);

        assert($this->pdo !== null);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM inbound_events WHERE event_id = ?');
        $stmt->execute(['dup-evt']);
        $this->assertSame('1', (string) $stmt->fetchColumn());
    }

    // MySQL-03: 署名検証失敗は401・DBに保存されない
    public function testMysqlInvalidSignatureNotStored(): void
    {
        $srcId   = $this->createSource('mysql-src-3');
        $rawBody = json_encode(['event_id' => 'bad-sig', 'event_type' => 'push']);
        assert($rawBody !== false);
        $psr17   = new Psr17Factory();
        $stream  = $psr17->createStream($rawBody);
        $request = $psr17->createServerRequest('POST', $psr17->createUri("http://localhost/sources/{$srcId}/receive"))
            ->withBody($stream)
            ->withHeader('X-Webhook-Signature', 'sha256=invalid');
        assert($this->app !== null);
        $res = $this->app->handle($request);
        $this->assertSame(401, $res->getStatusCode());

        assert($this->pdo !== null);
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM inbound_events');
        assert($stmt !== false);
        $this->assertSame('0', (string) $stmt->fetchColumn());
    }

    // MySQL-04: イベント一覧がDBから正しく返る
    public function testMysqlListEvents(): void
    {
        $srcId = $this->createSource('mysql-src-4');
        $this->webhookReq("/sources/{$srcId}/receive", ['event_id' => 'e1', 'event_type' => 'delivered']);
        $this->webhookReq("/sources/{$srcId}/receive", ['event_id' => 'e2', 'event_type' => 'bounced']);
        $this->webhookReq("/sources/{$srcId}/receive", ['event_id' => 'e3', 'event_type' => 'clicked']);

        $data = $this->json($this->req('GET', "/sources/{$srcId}/events"));
        $this->assertSame(3, $data['count']);
    }

    // MySQL-05: 異なるソースの同一 event_id は別レコード（UNIQUE はソースごと）
    public function testMysqlSameEventIdDifferentSources(): void
    {
        $src1 = $this->createSource('mysql-src-5a');
        $src2 = $this->createSource('mysql-src-5b');
        $this->webhookReq("/sources/{$src1}/receive", ['event_id' => 'shared-id', 'event_type' => 'ping']);
        $res  = $this->webhookReq("/sources/{$src2}/receive", ['event_id' => 'shared-id', 'event_type' => 'ping']);
        $this->assertSame(201, $res->getStatusCode()); // Different source → new record

        assert($this->pdo !== null);
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM inbound_events WHERE event_id = \'shared-id\'');
        assert($stmt !== false);
        $this->assertSame('2', (string) $stmt->fetchColumn());
    }
}
