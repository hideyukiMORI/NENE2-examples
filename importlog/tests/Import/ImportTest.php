<?php

declare(strict_types=1);

namespace ImportLog\Tests\Import;

use ImportLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class ImportTest extends TestCase
{
    private PDO $pdo;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $this->pdo->exec($schema);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function req(string $method, string $path, mixed $body = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $uri = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
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

    // ── POST /imports ────────────────────────────────────────────────────────

    public function testImportValidCsvReturns201(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,alice@example.com,30', 'Bob,bob@example.com,25'),
            'filename' => 'users.csv',
        ]);
        $this->assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(2, $data['total_rows']);
        $this->assertSame(2, $data['imported_rows']);
        $this->assertSame(0, $data['failed_rows']);
        $this->assertSame([], $data['errors']);
        $this->assertSame('completed', $data['status']);
    }

    public function testImportPartialSuccess(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,alice@example.com,30', 'Bob,not-an-email,25', 'Carol,carol@example.com,'),
        ]);
        $this->assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(3, $data['total_rows']);
        $this->assertSame(2, $data['imported_rows']);
        $this->assertSame(1, $data['failed_rows']);
        $this->assertCount(1, $data['errors']);
        $this->assertSame(3, $data['errors'][0]['row']);
    }

    public function testImportAllInvalidRows(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => $this->csv('', 'bad-email,99', ',bad@example.com,'),
        ]);
        $this->assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(0, $data['imported_rows']);
        $this->assertSame(3, $data['failed_rows']);
    }

    public function testImportErrorContainsRowNumber(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,alice@example.com,30', 'Bob,bad-email,25'),
        ]);
        $data = $this->json($response);
        $this->assertSame(3, $data['errors'][0]['row']); // row 3 = data row 2 (row 1 is header)
    }

    public function testImportInvalidEmailReturnsError(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => $this->csv('Bob,not-valid,25'),
        ]);
        $data = $this->json($response);
        $this->assertSame(1, $data['failed_rows']);
        $this->assertStringContainsString('email', $data['errors'][0]['error']);
    }

    public function testImportMissingNameReturnsError(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => $this->csv(',alice@example.com,30'),
        ]);
        $data = $this->json($response);
        $this->assertSame(1, $data['failed_rows']);
        $this->assertStringContainsString('name', $data['errors'][0]['error']);
    }

    public function testImportAgeOutOfRangeReturnsError(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,alice@example.com,999'),
        ]);
        $data = $this->json($response);
        $this->assertSame(1, $data['failed_rows']);
        $this->assertStringContainsString('age', $data['errors'][0]['error']);
    }

    public function testImportAgeIsOptional(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,alice@example.com,'),
        ]);
        $this->assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(1, $data['imported_rows']);
    }

    public function testImportDuplicateEmailInBatchIsRejected(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,dup@example.com,30', 'Bob,dup@example.com,25'),
        ]);
        $data = $this->json($response);
        $this->assertSame(1, $data['imported_rows']);
        $this->assertSame(1, $data['failed_rows']);
        $this->assertStringContainsString('duplicate', $data['errors'][0]['error']);
    }

    public function testImportCsvWithCrlfLineEndings(): void
    {
        $csv = "name,email,age\r\nAlice,alice@example.com,30\r\nBob,bob@example.com,25";
        $response = $this->req('POST', '/imports', ['csv' => $csv]);
        $data = $this->json($response);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(2, $data['imported_rows']);
    }

    public function testImportDefaultFilenameWhenNotProvided(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,alice@example.com,30'),
        ]);
        $data = $this->json($response);
        $this->assertSame('upload.csv', $data['filename']);
    }

    public function testImportCsvMissingReturns422(): void
    {
        $response = $this->req('POST', '/imports', []);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testImportCsvEmptyStringReturns422(): void
    {
        $response = $this->req('POST', '/imports', ['csv' => '']);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testImportWrongHeaderReturns422(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => "id,username,born\nAlice,alice,1990",
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testImportEmptyDataRowsSucceeds(): void
    {
        $response = $this->req('POST', '/imports', [
            'csv' => "name,email,age",
        ]);
        $this->assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(0, $data['total_rows']);
        $this->assertSame(0, $data['imported_rows']);
    }

    // ── GET /imports ─────────────────────────────────────────────────────────

    public function testListImportsReturnsAll(): void
    {
        $this->req('POST', '/imports', ['csv' => $this->csv('Alice,alice@example.com,30')]);
        $this->req('POST', '/imports', ['csv' => $this->csv('Bob,bob@example.com,25')]);

        $response = $this->req('GET', '/imports');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(2, $data['count']);
        $this->assertCount(2, $data['imports']);
    }

    public function testListImportsOrderedNewestFirst(): void
    {
        $this->req('POST', '/imports', ['csv' => $this->csv('Alice,alice@example.com,30'), 'filename' => 'first.csv']);
        $this->req('POST', '/imports', ['csv' => $this->csv('Bob,bob@example.com,25'), 'filename' => 'second.csv']);

        $data = $this->json($this->req('GET', '/imports'));
        $this->assertSame('second.csv', $data['imports'][0]['filename']);
    }

    // ── GET /imports/{id} ────────────────────────────────────────────────────

    public function testGetImportReturnsJobWithRecords(): void
    {
        $created = $this->json($this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,alice@example.com,30', 'Bob,bob@example.com,'),
        ]));
        $id = (int) $created['id'];

        $response = $this->req('GET', "/imports/{$id}");
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);

        $this->assertSame($id, $data['id']);
        $this->assertArrayHasKey('records', $data);
        $this->assertCount(2, $data['records']);
    }

    public function testGetImportRecordsHaveCorrectFields(): void
    {
        $created = $this->json($this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,alice@example.com,30'),
        ]));
        $id = (int) $created['id'];

        $data = $this->json($this->req('GET', "/imports/{$id}"));
        $record = $data['records'][0];

        $this->assertSame('Alice', $record['name']);
        $this->assertSame('alice@example.com', $record['email']);
        $this->assertSame(30, $record['age']);
        $this->assertArrayHasKey('created_at', $record);
    }

    public function testGetImportAgeNullWhenNotProvided(): void
    {
        $created = $this->json($this->req('POST', '/imports', [
            'csv' => $this->csv('Alice,alice@example.com,'),
        ]));
        $id = (int) $created['id'];

        $data = $this->json($this->req('GET', "/imports/{$id}"));
        $this->assertNull($data['records'][0]['age']);
    }

    public function testGetImportNotFoundReturns404(): void
    {
        $response = $this->req('GET', '/imports/9999');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testImportLargeBatch(): void
    {
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = "User{$i},user{$i}@example.com,{$i}";
        }
        $csv = implode("\n", ['name,email,age', ...$rows]);

        $response = $this->req('POST', '/imports', ['csv' => $csv]);
        $this->assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(100, $data['imported_rows']);
    }
}
