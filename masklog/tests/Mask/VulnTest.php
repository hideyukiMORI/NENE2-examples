<?php

declare(strict_types=1);

namespace MaskLog\Tests\Mask;

use MaskLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * FT169 脆弱性診断: VULN-A〜L
 * Data Masking セキュリティ評価
 */
final class VulnTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/masklog_vuln_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $this->app = AppFactory::createSqlite($this->dbFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function req(
        string $method,
        string $path,
        mixed $body = null,
        string $role = '',
        string $accessor = '',
    ): ResponseInterface {
        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        if ($role !== '') {
            $request = $request->withHeader('X-Role', $role);
        }
        if ($accessor !== '') {
            $request = $request->withHeader('X-Accessor', $accessor);
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $res->getBody(), true);
    }

    private function createCustomer(string $name, string $email, string $phone): int
    {
        $res = $this->req('POST', '/customers', compact('name', 'email', 'phone'));
        return (int) $this->json($res)['id'];
    }

    // VULN-A: PII never exposed in default GET response
    public function testVulnA_PiiNotExposedByDefault(): void
    {
        $id   = $this->createCustomer('Alice Secret', 'alice@private.com', '999-888-7777');
        $data = $this->json($this->req('GET', "/customers/{$id}"));
        // Raw PII must not appear
        $this->assertStringNotContainsString('Alice Secret', (string) json_encode($data));
        $this->assertStringNotContainsString('alice@private.com', (string) json_encode($data));
        $this->assertStringNotContainsString('999-888-7777', (string) json_encode($data));
    }

    // VULN-B: SQL injection in name field stored safely (parameterized query)
    public function testVulnB_SqlInjectionInNameSafe(): void
    {
        $maliciousName = "Robert'); DROP TABLE customers; --";
        $res = $this->req('POST', '/customers', [
            'name' => $maliciousName, 'email' => 'hack@test.com', 'phone' => '111-222-3333',
        ]);
        $this->assertSame(201, $res->getStatusCode());
        // Table must still exist — next query works
        $id  = (int) $this->json($res)['id'];
        $res2 = $this->req('GET', "/customers/{$id}");
        $this->assertSame(200, $res2->getStatusCode());
    }

    // VULN-C: SQL injection in email field
    public function testVulnC_SqlInjectionInEmailSafe(): void
    {
        $maliciousEmail = "x' OR '1'='1";
        $res = $this->req('POST', '/customers', [
            'name' => 'Inject', 'email' => $maliciousEmail, 'phone' => '000-000-0000',
        ]);
        $this->assertSame(201, $res->getStatusCode());
        // Second customer still isolated
        $id2 = $this->createCustomer('Normal', 'normal@test.com', '100-200-3000');
        $data = $this->json($this->req('GET', "/customers/{$id2}"));
        $this->assertSame($id2, (int) $data['id']);
    }

    // VULN-D: IDOR — non-admin cannot access other customer's full PII via customer ID
    public function testVulnD_IdorMaskedForAnonymous(): void
    {
        $id1 = $this->createCustomer('User One', 'one@example.com', '100-100-1111');
        $id2 = $this->createCustomer('User Two', 'two@example.com', '200-200-2222');

        $data1 = $this->json($this->req('GET', "/customers/{$id1}"));
        $data2 = $this->json($this->req('GET', "/customers/{$id2}"));

        // Neither should expose raw PII
        $this->assertStringNotContainsString('one@example.com', (string) json_encode($data1));
        $this->assertStringNotContainsString('two@example.com', (string) json_encode($data2));
    }

    // VULN-E: Role escalation attempt — arbitrary X-Role header value does not grant admin
    public function testVulnE_ArbitraryRoleNotAdmin(): void
    {
        $id  = $this->createCustomer('Victim', 'victim@secret.com', '777-888-9999');
        // Attacker sends X-Role: superuser or X-Role: ADMIN (wrong case)
        $psr17   = new Psr17Factory();
        $request = $psr17->createServerRequest('GET', $psr17->createUri("http://localhost/customers/{$id}"))
            ->withHeader('X-Role', 'superuser');
        $data = json_decode((string) $this->app->handle($request)->getBody(), true);
        $this->assertStringNotContainsString('victim@secret.com', (string) json_encode($data));

        $request2 = $psr17->createServerRequest('GET', $psr17->createUri("http://localhost/customers/{$id}"))
            ->withHeader('X-Role', 'ADMIN');
        $data2 = json_decode((string) $this->app->handle($request2)->getBody(), true);
        $this->assertStringNotContainsString('victim@secret.com', (string) json_encode($data2));
    }

    // VULN-F: Admin access without X-Accessor header → 403 (audit trail cannot be empty)
    public function testVulnF_AdminWithoutAccessorBlocked(): void
    {
        $id  = $this->createCustomer('Protected', 'protected@corp.com', '333-444-5555');
        $res = $this->req('GET', "/customers/{$id}", role: 'admin');
        $this->assertSame(403, $res->getStatusCode());
    }

    // VULN-G: Audit log not accessible to non-admin
    public function testVulnG_AuditLogProtected(): void
    {
        $id  = $this->createCustomer('Audited', 'audit@corp.com', '666-777-8888');
        $res = $this->req('GET', "/customers/{$id}/audit");
        $this->assertSame(403, $res->getStatusCode());
    }

    // VULN-H: Nonexistent customer returns 404, not 500 or data leak
    public function testVulnH_NonexistentCustomer404(): void
    {
        $res  = $this->req('GET', '/customers/99999');
        $data = $this->json($res);
        $this->assertSame(404, $res->getStatusCode());
        // No stack trace or internal info in response
        $this->assertArrayNotHasKey('trace', $data);
        $this->assertArrayNotHasKey('exception', $data);
    }

    // VULN-I: Extremely long input does not crash the application
    public function testVulnI_LongInputHandled(): void
    {
        $longName = str_repeat('A', 10000);
        $res = $this->req('POST', '/customers', [
            'name' => $longName, 'email' => 'long@test.com', 'phone' => '000-111-2222',
        ]);
        // Should succeed or fail gracefully, not 500
        $this->assertContains($res->getStatusCode(), [201, 422, 413]);
    }

    // VULN-J: XSS payload in name stored as literal, not executed in JSON response
    public function testVulnJ_XssPayloadStoredLiteral(): void
    {
        $xssName = '<script>alert("xss")</script>';
        $res  = $this->req('POST', '/customers', [
            'name' => $xssName, 'email' => 'xss@test.com', 'phone' => '111-222-3456',
        ]);
        $this->assertSame(201, $res->getStatusCode());
        $id   = (int) $this->json($res)['id'];
        // Admin view shows the raw literal (not executed)
        $data = $this->json($this->req('GET', "/customers/{$id}", role: 'admin', accessor: 'xss-tester'));
        $this->assertSame($xssName, $data['name']);
        // Content-Type is application/json, not HTML
        $this->assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
    }

    // VULN-K: Masked response does not contain original PII after storage
    public function testVulnK_MaskedResponseNeverRevealsFull(): void
    {
        $email = 'confidential.user@secure-domain.org';
        $phone = '012-345-6789';
        $name  = 'Confidential User';
        $id    = $this->createCustomer($name, $email, $phone);

        $data = $this->json($this->req('GET', "/customers/{$id}"));
        $json = (string) json_encode($data);
        $this->assertStringNotContainsString($email, $json);
        $this->assertStringNotContainsString($phone, $json);
        $this->assertStringNotContainsString($name, $json);
    }

    // VULN-L: Audit log entries are immutable — no delete/update route exists
    public function testVulnL_AuditLogImmutable(): void
    {
        $id = $this->createCustomer('Immutable', 'immutable@test.com', '555-666-7777');
        $this->req('GET', "/customers/{$id}", role: 'admin', accessor: 'first-accessor');

        // Attempt to DELETE audit entries (no such route → 404 or 405)
        $psr17   = new Psr17Factory();
        $request = $psr17->createServerRequest('DELETE', $psr17->createUri("http://localhost/customers/{$id}/audit"));
        $res     = $this->app->handle($request);
        $this->assertContains($res->getStatusCode(), [404, 405]);

        // Audit log still intact
        $audit = $this->json($this->req('GET', "/customers/{$id}/audit", role: 'admin'));
        $this->assertSame(1, $audit['count']);
    }
}
