<?php

declare(strict_types=1);

namespace ContentLog\Tests\Article;

use ContentLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class ContentNegotiationTest extends TestCase
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

    /** @param array<string, string> $headers */
    private function req(string $method, string $path, array $headers = [], mixed $body = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    private function create(string $title = 'Hello'): int
    {
        $res = $this->req('POST', '/articles', ['Content-Type' => 'application/json'], ['title' => $title]);
        assert($res->getStatusCode() === 201);
        $decoded = json_decode((string) $res->getBody(), true);
        assert(is_array($decoded));
        return (int) $decoded['id'];
    }

    // ── always JSON regardless of Accept ───────────────────────────────────────

    /**
     * @return list<array{string}>
     */
    public static function acceptHeaders(): array
    {
        return [['*/*'], ['application/json'], ['application/*'], ['text/html'], ['application/xml'], ['text/plain'], ['application/json;q=0.9']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptHeaders')]
    public function testSuccessIsAlwaysJson(string $accept): void
    {
        $this->create();
        $res = $this->req('GET', '/articles', ['Accept' => $accept]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
    }

    public function testSuccessJsonWithNoAcceptHeader(): void
    {
        $this->create();
        $res = $this->req('GET', '/articles');
        $this->assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
    }

    // ── error responses use application/problem+json ───────────────────────────

    public function testNotFoundIsProblemJson(): void
    {
        $res = $this->req('GET', '/articles/999', ['Accept' => 'application/json']);
        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $res->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $res->getBody(), true);
        assert(is_array($decoded));
        $this->assertSame('https://nene2.dev/problems/not-found', $decoded['type']);
    }

    public function testValidationFailureIsProblemJson(): void
    {
        $res = $this->req('POST', '/articles', ['Content-Type' => 'application/json'], ['title' => '   ']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $res->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $res->getBody(), true);
        assert(is_array($decoded));
        $this->assertSame('title', $decoded['errors'][0]['field']);
    }

    // ── request Content-Type — 415 ──────────────────────────────────────────────

    public function testNonJsonContentTypeIs415(): void
    {
        $res = $this->req('POST', '/articles', ['Content-Type' => 'text/plain'], ['title' => 'x']);
        $this->assertSame(415, $res->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $res->getHeaderLine('Content-Type'));
    }

    public function testNoContentTypeWithJsonBodyAccepted(): void
    {
        // no Content-Type header at all, but a valid (parsed) JSON body → 201
        $res = $this->req('POST', '/articles', [], ['title' => 'No CT']);
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testJsonContentTypeWithCharsetAccepted(): void
    {
        $res = $this->req('POST', '/articles', ['Content-Type' => 'application/json; charset=utf-8'], ['title' => 'x']);
        $this->assertSame(201, $res->getStatusCode());
    }

    // ── happy path shape ─────────────────────────────────────────────────────────

    public function testCreateAndGet(): void
    {
        $id = $this->create('My Title');
        $res = $this->req('GET', '/articles/' . $id);
        $this->assertSame(200, $res->getStatusCode());
        $decoded = json_decode((string) $res->getBody(), true);
        assert(is_array($decoded));
        $this->assertSame('My Title', $decoded['title']);
    }
}
