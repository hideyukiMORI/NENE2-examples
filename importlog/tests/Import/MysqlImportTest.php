<?php

declare(strict_types=1);

namespace ImportLog\Tests\Import;

use ImportLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * MySQL integration tests for FT158.
 * Skipped automatically when MYSQL_HOST is not set.
 */
final class MysqlImportTest extends TestCase
{
    private bool $mysqlEnabled = false;
    private ?PDO $pdo = null;
    private ?\Psr\Http\Server\RequestHandlerInterface $app = null;

    protected function setUp(): void
    {
        $host = (string) (getenv('MYSQL_HOST') ?: '');
        if ($host === '') {
            self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
        }

        $this->mysqlEnabled = true;
        $port = (int) (getenv('MYSQL_PORT') ?: 3306);
        $database = (string) (getenv('MYSQL_DATABASE') ?: 'ft_test');
        $user = (string) (getenv('MYSQL_USER') ?: 'ft_user');
        $password = (string) (getenv('MYSQL_PASSWORD') ?: 'ft_pass');

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS imported_records');
        $this->pdo->exec('DROP TABLE IF EXISTS import_jobs');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.mysql.sql');
        $this->pdo->exec($schema);

        $this->app = AppFactory::createMysql($host, $port, $database, $user, $password);
    }

    protected function tearDown(): void
    {
        if ($this->mysqlEnabled && $this->pdo !== null) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->pdo->exec('DROP TABLE IF EXISTS imported_records');
            $this->pdo->exec('DROP TABLE IF EXISTS import_jobs');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function req(string $method, string $path, mixed $body = null): ResponseInterface
    {
        assert($this->app !== null);
        $psr17 = new Psr17Factory();
        $uri = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true);
    }

    private function csv(string ...$rows): string
    {
        return implode("\n", ['name,email,age', ...$rows]);
    }

    public function testMysqlBasicImportAndRetrieval(): void
    {
        $res = $this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,alice@example.com,30', 'Bob,bob@example.com,25'),
            'filename' => 'mysql-test.csv',
        ]);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame(2, $data['imported_rows']);

        $id = (int) $data['id'];
        $detail = $this->json($this->req('GET', "/imports/{$id}"));
        $this->assertCount(2, $detail['records']);
        $this->assertSame('Alice', $detail['records'][0]['name']);
    }

    public function testMysqlLargeBatchImport(): void
    {
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = "User{$i},user{$i}@example.com,{$i}";
        }
        $csv = implode("\n", ['name,email,age', ...$rows]);

        $res = $this->req('POST', '/imports', ['csv' => $csv]);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame(100, $data['imported_rows']);
        $this->assertSame(100, $data['total_rows']);

        // Verify in DB
        assert($this->pdo !== null);
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM imported_records');
        assert($stmt !== false);
        $this->assertSame('100', (string) $stmt->fetchColumn());
    }

    public function testMysqlPartialSuccessStoresOnlyValidRows(): void
    {
        $res = $this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,alice@example.com,30', 'Bad,bad-email,25', 'Carol,carol@example.com,28'),
        ]);
        $data = $this->json($res);
        $this->assertSame(2, $data['imported_rows']);
        $this->assertSame(1, $data['failed_rows']);

        assert($this->pdo !== null);
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM imported_records');
        assert($stmt !== false);
        $this->assertSame('2', (string) $stmt->fetchColumn());
    }

    public function testMysqlDuplicateEmailInBatch(): void
    {
        $res = $this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,dup@example.com,30', 'Bob,dup@example.com,25'),
        ]);
        $data = $this->json($res);
        $this->assertSame(1, $data['imported_rows']);
        $this->assertSame(1, $data['failed_rows']);
    }

    public function testMysqlListImportJobs(): void
    {
        $this->req('POST', '/imports', ['csv' => $this->csv('Alice,a@example.com,30'), 'filename' => 'a.csv']);
        $this->req('POST', '/imports', ['csv' => $this->csv('Bob,b@example.com,25'), 'filename' => 'b.csv']);

        $res = $this->req('GET', '/imports');
        $data = $this->json($res);
        $this->assertSame(2, $data['count']);
        // newest first
        $this->assertSame('b.csv', $data['imports'][0]['filename']);
    }
}
