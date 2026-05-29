<?php

declare(strict_types=1);

namespace EncryptLog\Tests\Vault;

use EncryptLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class EncryptTest extends TestCase
{
    private const ENC_KEY = '0123456789abcdef0123456789abcdef';   // 32 bytes
    private const IDX_KEY = 'fedcba9876543210fedcba9876543210';   // 32 bytes

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

    /** @param array<string, string> $headers */
    private function req(string $method, string $path, array $headers = [], mixed $body = null, ?string $query = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile, self::ENC_KEY, self::IDX_KEY);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        if ($query !== null) {
            parse_str($query, $q);
            $request = $request->withQueryParams($q);
        }
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

    private function create(string $email, string $note = '', string $user = '1'): int
    {
        $res = $this->req('POST', '/vault', ['X-User-Id' => $user], ['email' => $email, 'note' => $note]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    // ── round-trip ────────────────────────────────────────────────────────

    public function testCreateAndDecryptRoundTrip(): void
    {
        $id = $this->create('alice@example.com', 'secret note');
        $data = $this->json($this->req('GET', '/vault/' . $id, ['X-User-Id' => '1']));
        $this->assertSame('alice@example.com', $data['email']);
        $this->assertSame('secret note', $data['note']);
    }

    public function testCiphertextNeverInResponse(): void
    {
        $id = $this->create('bob@example.com');
        $body = (string) $this->req('GET', '/vault/' . $id, ['X-User-Id' => '1'])->getBody();
        $this->assertStringNotContainsString('email_enc', $body);
        $this->assertStringContainsString('bob@example.com', $body);
    }

    public function testSamePlaintextDifferentCiphertext(): void
    {
        $this->create('same@example.com', '', '1');
        $this->create('same@example.com', '', '1');
        // two rows, distinct ciphertext (fresh nonce), same blind index
        $stmt = $this->pdo->query('SELECT email_enc, email_idx FROM records');
        assert($stmt !== false);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotSame($rows[0]['email_enc'], $rows[1]['email_enc']);
        $this->assertSame($rows[0]['email_idx'], $rows[1]['email_idx']);
    }

    // ── blind index search ────────────────────────────────────────────────────

    public function testSearchByBlindIndex(): void
    {
        $this->create('find@example.com', '', '1');
        $this->create('other@example.com', '', '1');
        $data = $this->json($this->req('GET', '/vault/search', ['X-User-Id' => '1'], null, 'email=find@example.com'));
        $this->assertSame(1, $data['count']);
        $this->assertSame('find@example.com', $data['records'][0]['email']);
    }

    public function testSearchIsOwnerScoped(): void
    {
        $this->create('shared@example.com', '', '1');
        $data = $this->json($this->req('GET', '/vault/search', ['X-User-Id' => '2'], null, 'email=shared@example.com'));
        $this->assertSame(0, $data['count']);
    }

    // ── tamper detection ────────────────────────────────────────────────────────

    public function testTamperedCiphertextYields500(): void
    {
        $id = $this->create('tamper@example.com');
        // Corrupt the stored ciphertext directly.
        $this->pdo->exec("UPDATE records SET email_enc = 'AAAA' || email_enc WHERE id = {$id}");
        $res = $this->req('GET', '/vault/' . $id, ['X-User-Id' => '1']);
        $this->assertSame(500, $res->getStatusCode());
    }

    // ── update reindexes ──────────────────────────────────────────────────────────

    public function testUpdateReindexes(): void
    {
        $id = $this->create('old@example.com', '', '1');
        $this->req('PATCH', '/vault/' . $id, ['X-User-Id' => '1'], ['email' => 'new@example.com']);

        // old index no longer matches, new one does
        $this->assertSame(0, $this->json($this->req('GET', '/vault/search', ['X-User-Id' => '1'], null, 'email=old@example.com'))['count']);
        $this->assertSame(1, $this->json($this->req('GET', '/vault/search', ['X-User-Id' => '1'], null, 'email=new@example.com'))['count']);
    }

    // ── IDOR ─────────────────────────────────────────────────────────────────────

    public function testCrossUserAccessIs404(): void
    {
        $id = $this->create('a@example.com', '', '1');
        $this->assertSame(404, $this->req('GET', '/vault/' . $id, ['X-User-Id' => '99'])->getStatusCode());
        $this->assertSame(404, $this->req('DELETE', '/vault/' . $id, ['X-User-Id' => '99'])->getStatusCode());
    }

    public function testRequiresUser(): void
    {
        $this->assertSame(401, $this->req('POST', '/vault', [], ['email' => 'x@y.com'])->getStatusCode());
    }

    public function testEmailRequired(): void
    {
        $this->assertSame(422, $this->req('POST', '/vault', ['X-User-Id' => '1'], ['note' => 'no email'])->getStatusCode());
    }
}
