<?php

declare(strict_types=1);

namespace TemplateLog\Tests\Template;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TemplateLog\AppFactory;

class TemplateTest extends TestCase
{
    private const ADMIN_KEY = 'admin-key';

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
        $app = AppFactory::createSqlite($this->dbFile, self::ADMIN_KEY);
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

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        assert(is_array($decoded));
        return $decoded;
    }

    private function createTemplate(string $name, string $body): int
    {
        $res = $this->req('POST', '/templates', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => $name, 'body' => $body]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    // ─── admin gating + CRUD ─────────────────────────────────────────────────

    public function testCreateRequiresAdmin(): void
    {
        $this->assertSame(403, $this->req('POST', '/templates', [], ['name' => 'a', 'body' => 'b'])->getStatusCode());
    }

    public function testCreateAndGet(): void
    {
        $id = $this->createTemplate('welcome', 'Hi {{name}}');
        $res = $this->req('GET', '/templates/' . $id);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Hi {{name}}', $this->json($res)['body']);
    }

    public function testDuplicateNameConflicts(): void
    {
        $this->createTemplate('welcome', 'a');
        $res = $this->req('POST', '/templates', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => 'welcome', 'body' => 'b']);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function testCreateValidation(): void
    {
        $this->assertSame(422, $this->req('POST', '/templates', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => '', 'body' => 5])->getStatusCode());
    }

    public function testListExcludesBody(): void
    {
        $this->createTemplate('t1', 'body-of-t1');
        $data = $this->json($this->req('GET', '/templates'));
        $this->assertSame(1, $data['count']);
        $this->assertArrayNotHasKey('body', $data['templates'][0]);
    }

    public function testUpdateBody(): void
    {
        $id = $this->createTemplate('t', 'old');
        $res = $this->req('PUT', '/templates/' . $id, ['X-Admin-Key' => self::ADMIN_KEY], ['body' => 'new {{x}}']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('new {{x}}', $this->json($res)['body']);
    }

    public function testUpdateRequiresAdmin(): void
    {
        $id = $this->createTemplate('t', 'old');
        $this->assertSame(403, $this->req('PUT', '/templates/' . $id, [], ['body' => 'x'])->getStatusCode());
    }

    public function testDelete(): void
    {
        $id = $this->createTemplate('t', 'x');
        $this->assertSame(204, $this->req('DELETE', '/templates/' . $id, ['X-Admin-Key' => self::ADMIN_KEY])->getStatusCode());
        $this->assertSame(404, $this->req('GET', '/templates/' . $id)->getStatusCode());
    }

    public function testDeleteRequiresAdmin(): void
    {
        $id = $this->createTemplate('t', 'x');
        $this->assertSame(403, $this->req('DELETE', '/templates/' . $id)->getStatusCode());
    }

    // ─── rendering ───────────────────────────────────────────────────────────

    public function testRenderSubstitutesKnownVars(): void
    {
        $id = $this->createTemplate('greet', 'Hello {{name}}, you are {{role}}.');
        $res = $this->req('POST', '/templates/' . $id . '/render', [], ['vars' => ['name' => 'Alice', 'role' => 'admin']]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Hello Alice, you are admin.', $this->json($res)['rendered']);
    }

    public function testRenderLeavesUnknownPlaceholders(): void
    {
        $id = $this->createTemplate('greet', 'Hi {{name}} {{unknown}}');
        $res = $this->req('POST', '/templates/' . $id . '/render', [], ['vars' => ['name' => 'Bob']]);
        $this->assertSame('Hi Bob {{unknown}}', $this->json($res)['rendered']);
    }

    public function testRenderHandlesSpacedPlaceholders(): void
    {
        $id = $this->createTemplate('greet', 'Hi {{ name }}');
        $res = $this->req('POST', '/templates/' . $id . '/render', [], ['vars' => ['name' => 'Cara']]);
        $this->assertSame('Hi Cara', $this->json($res)['rendered']);
    }

    public function testRenderIsPublic(): void
    {
        $id = $this->createTemplate('greet', '{{x}}');
        // no admin key needed
        $this->assertSame(200, $this->req('POST', '/templates/' . $id . '/render', [], ['vars' => ['x' => 'y']])->getStatusCode());
    }

    public function testRenderUnknownTemplate(): void
    {
        $this->assertSame(404, $this->req('POST', '/templates/999/render', [], ['vars' => []])->getStatusCode());
    }
}
