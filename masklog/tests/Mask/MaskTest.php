<?php

declare(strict_types=1);

namespace MaskLog\Tests\Mask;

use MaskLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MaskTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/masklog_test_' . uniqid() . '.sqlite';
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

    private function createCustomer(
        string $name = 'John Doe',
        string $email = 'john@example.com',
        string $phone = '555-123-4567',
    ): int {
        $res = $this->req('POST', '/customers', compact('name', 'email', 'phone'));
        return (int) $this->json($res)['id'];
    }

    // FT169-01: POST /customers returns 201 with masked PII
    public function testCreateCustomerReturnsMasked(): void
    {
        $res  = $this->req('POST', '/customers', [
            'name' => 'John Doe', 'email' => 'john@example.com', 'phone' => '555-123-4567',
        ]);
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('J*** D***', $data['name']);
        $this->assertSame('j***@example.com', $data['email']);
        $this->assertSame('***-***-4567', $data['phone']);
    }

    // FT169-02: Missing required fields → 422
    public function testCreateCustomerRequiresAllFields(): void
    {
        $this->assertSame(422, $this->req('POST', '/customers', ['name' => 'Alice'])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/customers', ['email' => 'a@b.com'])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/customers', ['phone' => '123'])->getStatusCode());
    }

    // FT169-03: GET /customers/{id} returns masked data for non-admin
    public function testGetCustomerReturnsMasked(): void
    {
        $id   = $this->createCustomer();
        $data = $this->json($this->req('GET', "/customers/{$id}"));
        $this->assertStringStartsWith('J***', $data['name']);
        $this->assertStringStartsWith('j***@', $data['email']);
        $this->assertStringEndsWith('4567', $data['phone']);
    }

    // FT169-04: GET /customers/{id} with X-Role: admin returns full PII
    public function testAdminGetCustomerReturnsFull(): void
    {
        $id   = $this->createCustomer('Jane Smith', 'jane@corp.com', '800-555-9999');
        $data = $this->json($this->req('GET', "/customers/{$id}", role: 'admin', accessor: 'ops-user'));
        $this->assertSame('Jane Smith', $data['name']);
        $this->assertSame('jane@corp.com', $data['email']);
        $this->assertSame('800-555-9999', $data['phone']);
    }

    // FT169-05: Admin access without X-Accessor → 403
    public function testAdminWithoutAccessorForbidden(): void
    {
        $id  = $this->createCustomer();
        $res = $this->req('GET', "/customers/{$id}", role: 'admin');
        $this->assertSame(403, $res->getStatusCode());
    }

    // FT169-06: Admin access logs to audit table
    public function testAdminAccessLogged(): void
    {
        $id = $this->createCustomer();
        $this->req('GET', "/customers/{$id}", role: 'admin', accessor: 'auditor-1');
        $this->req('GET', "/customers/{$id}", role: 'admin', accessor: 'auditor-2');

        $audit = $this->json($this->req('GET', "/customers/{$id}/audit", role: 'admin'));
        $this->assertSame(2, $audit['count']);
        $this->assertSame('auditor-1', $audit['entries'][0]['accessor']);
        $this->assertSame('auditor-2', $audit['entries'][1]['accessor']);
    }

    // FT169-07: Non-admin cannot view audit log → 403
    public function testNonAdminCannotViewAudit(): void
    {
        $id  = $this->createCustomer();
        $res = $this->req('GET', "/customers/{$id}/audit");
        $this->assertSame(403, $res->getStatusCode());
    }

    // FT169-08: GET nonexistent customer → 404
    public function testGetNonexistentCustomerReturns404(): void
    {
        $this->assertSame(404, $this->req('GET', '/customers/9999')->getStatusCode());
    }

    // FT169-09: Email masking preserves domain
    public function testEmailMaskingPreservesDomain(): void
    {
        $id   = $this->createCustomer('Bob', 'bob.roberts@subdomain.example.org', '123-456-7890');
        $data = $this->json($this->req('GET', "/customers/{$id}"));
        $this->assertStringEndsWith('@subdomain.example.org', $data['email']);
        $this->assertStringStartsWith('b***@', $data['email']);
    }

    // FT169-10: Phone masking preserves last 4 digits
    public function testPhoneMaskingPreservesLast4(): void
    {
        $id   = $this->createCustomer('Carol', 'carol@test.com', '+1-800-123-7777');
        $data = $this->json($this->req('GET', "/customers/{$id}"));
        $this->assertStringEndsWith('7777', $data['phone']);
    }

    // FT169-11: Single-word name masked correctly
    public function testSingleWordNameMasked(): void
    {
        $id   = $this->createCustomer('Madonna', 'madonna@pop.com', '999-888-1234');
        $data = $this->json($this->req('GET', "/customers/{$id}"));
        $this->assertSame('M***', $data['name']);
    }

    // FT169-12: Multiple admin accesses all logged separately
    public function testMultipleAdminAccessesAllLogged(): void
    {
        $id = $this->createCustomer();
        for ($i = 1; $i <= 5; $i++) {
            $this->req('GET', "/customers/{$id}", role: 'admin', accessor: "analyst-{$i}");
        }
        $audit = $this->json($this->req('GET', "/customers/{$id}/audit", role: 'admin'));
        $this->assertSame(5, $audit['count']);
    }
}
