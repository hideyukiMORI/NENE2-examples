<?php

declare(strict_types=1);

namespace ShortLog\Tests\Link;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ShortLog\AppFactory;
use ShortLog\Link\UrlValidator;

class ShortTest extends TestCase
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
        // Stub resolver: deterministic, no real DNS.
        $resolver = static fn (string $host): string => match ($host) {
            'private.internal' => '10.0.0.1',
            'public.example.com' => '93.184.216.34',
            default => $host,
        };
        $app = AppFactory::createSqlite($this->dbFile, new UrlValidator($resolver));
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

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        assert(is_array($decoded));
        return $decoded;
    }

    private function shorten(string $url, string $user = '1'): ResponseInterface
    {
        return $this->req('POST', '/links', ['X-User-Id' => $user], ['url' => $url]);
    }

    // ── happy path ────────────────────────────────────────────────────────

    public function testCreateRequiresUser(): void
    {
        $this->assertSame(401, $this->req('POST', '/links', [], ['url' => 'https://example.com'])->getStatusCode());
    }

    public function testCreatePublicUrl(): void
    {
        $res = $this->shorten('https://public.example.com/page');
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(0, $this->json($res)['click_count']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $this->json($res)['slug']);
    }

    public function testGetAndDelete(): void
    {
        $slug = $this->json($this->shorten('https://public.example.com'))['slug'];
        $this->assertSame(200, $this->req('GET', '/links/' . $slug)->getStatusCode());
        $this->assertSame(204, $this->req('DELETE', '/links/' . $slug, ['X-User-Id' => '1'])->getStatusCode());
        $this->assertSame(404, $this->req('GET', '/links/' . $slug)->getStatusCode());
    }

    // ── SSRF prevention (VULN-K) ──────────────────────────────────────────────

    public function testSsrfVectorsBlocked(): void
    {
        foreach ([
            'http://127.0.0.1/admin',
            'http://localhost/',
            'http://internal.localhost/',
            'http://10.0.0.1/',
            'http://192.168.1.1/router',
            'http://169.254.169.254/',
            'javascript:alert(1)',
            'file:///etc/passwd',
            'ftp://example.com/',
            'http://private.internal/',     // resolves (stub) to 10.0.0.1 → blocked
        ] as $url) {
            $this->assertSame(422, $this->shorten($url)->getStatusCode(), "SSRF URL must be blocked: {$url}");
        }
    }

    public function testResolvedPublicHostAllowed(): void
    {
        // public.example.com resolves (stub) to a public IP → allowed
        $this->assertSame(201, $this->shorten('https://public.example.com/x')->getStatusCode());
    }

    // ── IDOR (VULN-E) ────────────────────────────────────────────────────────

    public function testCrossUserDeleteIs404(): void
    {
        $slug = $this->json($this->shorten('https://public.example.com', '1'))['slug'];
        $this->assertSame(404, $this->req('DELETE', '/links/' . $slug, ['X-User-Id' => '99'])->getStatusCode());
        $this->assertSame(200, $this->req('GET', '/links/' . $slug)->getStatusCode()); // survived
    }

    // ── type confusion / mass assignment ──────────────────────────────────────

    public function testNonStringUrlRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/links', ['X-User-Id' => '1'], ['url' => 12345])->getStatusCode());
    }

    public function testClickCountNotMassAssignable(): void
    {
        $res = $this->req('POST', '/links', ['X-User-Id' => '1'], ['url' => 'https://public.example.com', 'click_count' => 9999]);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(0, $this->json($res)['click_count']); // body value ignored
    }

    public function testListOwnerScoped(): void
    {
        $this->shorten('https://public.example.com/a', '1');
        $this->shorten('https://public.example.com/b', '2');
        $this->assertCount(1, $this->json($this->req('GET', '/links', ['X-User-Id' => '1']))['links']);
    }
}
