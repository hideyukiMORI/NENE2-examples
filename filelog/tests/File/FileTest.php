<?php

declare(strict_types=1);

namespace FileLog\Tests\File;

use FileLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class FileTest extends TestCase
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

        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Alice', '2026-01-01T00:00:00Z')");
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Bob', '2026-01-01T00:00:00Z')");
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Carol', '2026-01-01T00:00:00Z')");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $headers */
    private function req(
        string $method,
        string $path,
        array $headers = [],
        mixed $body = null,
    ): ResponseInterface {
        $app = AppFactory::createSqlite($this->dbFile);
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
    private function json(ResponseInterface $r): array
    {
        $d = json_decode((string) $r->getBody(), true);
        assert(is_array($d));
        return $d;
    }

    /** @param array<string, mixed> $body */
    private function createFile(string $userId, array $body = []): ResponseInterface
    {
        return $this->req('POST', '/files', ['X-User-Id' => $userId], array_merge([
            'name' => 'test.txt',
            'size' => 1024,
            'mime_type' => 'text/plain',
        ], $body));
    }

    // ─── GET /files ───────────────────────────────────────────────────────

    public function testListFilesRequiresAuth(): void
    {
        $res = $this->req('GET', '/files');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testListFilesEmpty(): void
    {
        $res = $this->req('GET', '/files', ['X-User-Id' => '1']);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame([], $data['files']);
        $this->assertSame(0, $data['count']);
    }

    public function testListFilesShowsOwnFiles(): void
    {
        $this->createFile('1');
        $this->createFile('1', ['name' => 'doc.pdf', 'mime_type' => 'application/pdf']);
        $res = $this->req('GET', '/files', ['X-User-Id' => '1']);
        $this->assertSame(2, $this->json($res)['count']);
    }

    public function testListFilesIncludesSharedFiles(): void
    {
        $cr = $this->createFile('2');
        $fileId = $this->json($cr)['id'];
        // Share file from user 2 to user 1
        $this->req('POST', "/files/{$fileId}/shares", ['X-User-Id' => '2'], ['user_id' => 1]);

        $res = $this->req('GET', '/files', ['X-User-Id' => '1']);
        $this->assertSame(1, $this->json($res)['count']);
    }

    public function testListFilesDoesNotShowOtherPrivateFiles(): void
    {
        $this->createFile('2', ['visibility' => 'private']);
        $res = $this->req('GET', '/files', ['X-User-Id' => '1']);
        $this->assertSame(0, $this->json($res)['count']);
    }

    // ─── POST /files ──────────────────────────────────────────────────────

    public function testCreateFileRequiresAuth(): void
    {
        $res = $this->req('POST', '/files', [], ['name' => 'a.txt', 'size' => 1, 'mime_type' => 'text/plain']);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testCreateFileReturns201(): void
    {
        $res = $this->createFile('1');
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('test.txt', $data['name']);
        $this->assertSame(1024, $data['size']);
        $this->assertSame('private', $data['visibility']);
        $this->assertSame(1, $data['user_id']);
    }

    public function testCreateFilePublicVisibility(): void
    {
        $res = $this->createFile('1', ['visibility' => 'public']);
        $this->assertSame('public', $this->json($res)['visibility']);
    }

    public function testCreateFileValidationMissingName(): void
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '1'], ['size' => 1, 'mime_type' => 'text/plain']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateFileValidationSizeMustBeInt(): void
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '1'], ['name' => 'a.txt', 'size' => '1kb', 'mime_type' => 'text/plain']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateFileValidationNegativeSize(): void
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '1'], ['name' => 'a.txt', 'size' => -1, 'mime_type' => 'text/plain']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateFileValidationInvalidVisibility(): void
    {
        $res = $this->req('POST', '/files', ['X-User-Id' => '1'], ['name' => 'a.txt', 'size' => 1, 'mime_type' => 'text/plain', 'visibility' => 'world']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ─── GET /files/{id} ─────────────────────────────────────────────────

    public function testGetFileRequiresAuth(): void
    {
        $res = $this->req('GET', '/files/1');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testGetOwnFile(): void
    {
        $cr = $this->createFile('1');
        $id = $this->json($cr)['id'];
        $res = $this->req('GET', "/files/{$id}", ['X-User-Id' => '1']);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertTrue($data['is_owner']);
        $this->assertIsArray($data['shares']);
    }

    public function testGetOthersPrivateFileReturns404(): void
    {
        $cr = $this->createFile('2');
        $id = $this->json($cr)['id'];
        $res = $this->req('GET', "/files/{$id}", ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGetPublicFileFromAnyUser(): void
    {
        $cr = $this->createFile('2', ['visibility' => 'public']);
        $id = $this->json($cr)['id'];
        $res = $this->req('GET', "/files/{$id}", ['X-User-Id' => '1']);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testGetSharedFileReturns200(): void
    {
        $cr = $this->createFile('2');
        $fileId = $this->json($cr)['id'];
        $this->req('POST', "/files/{$fileId}/shares", ['X-User-Id' => '2'], ['user_id' => 1]);

        $res = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '1']);
        $this->assertSame(200, $res->getStatusCode());
    }

    // ─── PUT /files/{id} ─────────────────────────────────────────────────

    public function testUpdateFileRequiresAuth(): void
    {
        $res = $this->req('PUT', '/files/1', [], ['name' => 'new.txt', 'size' => 1, 'mime_type' => 'text/plain']);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testUpdateOwnFile(): void
    {
        $cr = $this->createFile('1');
        $id = $this->json($cr)['id'];
        $res = $this->req('PUT', "/files/{$id}", ['X-User-Id' => '1'], [
            'name' => 'renamed.txt',
            'size' => 2048,
            'mime_type' => 'text/plain',
            'visibility' => 'public',
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('renamed.txt', $data['name']);
        $this->assertSame('public', $data['visibility']);
    }

    public function testUpdateOthersFileReturns404(): void
    {
        $cr = $this->createFile('2');
        $id = $this->json($cr)['id'];
        $res = $this->req('PUT', "/files/{$id}", ['X-User-Id' => '1'], ['name' => 'a.txt', 'size' => 1, 'mime_type' => 'text/plain']);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testUpdateWithEditShare(): void
    {
        $cr = $this->createFile('2');
        $fileId = $this->json($cr)['id'];
        $this->req('POST', "/files/{$fileId}/shares", ['X-User-Id' => '2'], ['user_id' => 1, 'can_edit' => true]);

        $res = $this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '1'], [
            'name' => 'edited.txt',
            'size' => 512,
            'mime_type' => 'text/plain',
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('edited.txt', $this->json($res)['name']);
    }

    public function testUpdateWithViewShareForbidden(): void
    {
        $cr = $this->createFile('2');
        $fileId = $this->json($cr)['id'];
        $this->req('POST', "/files/{$fileId}/shares", ['X-User-Id' => '2'], ['user_id' => 1, 'can_edit' => false]);

        $res = $this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '1'], ['name' => 'a.txt', 'size' => 1, 'mime_type' => 'text/plain']);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testEditShareCannotChangeVisibility(): void
    {
        $cr = $this->createFile('2', ['visibility' => 'private']);
        $fileId = $this->json($cr)['id'];
        $this->req('POST', "/files/{$fileId}/shares", ['X-User-Id' => '2'], ['user_id' => 1, 'can_edit' => true]);

        $this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '1'], [
            'name' => 'a.txt',
            'size' => 1,
            'mime_type' => 'text/plain',
            'visibility' => 'public',
        ]);

        $check = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '2']);
        $this->assertSame('private', $this->json($check)['visibility']);
    }

    // ─── DELETE /files/{id} ──────────────────────────────────────────────

    public function testDeleteFileRequiresAuth(): void
    {
        $res = $this->req('DELETE', '/files/1');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testDeleteOwnFile(): void
    {
        $cr = $this->createFile('1');
        $id = $this->json($cr)['id'];
        $res = $this->req('DELETE', "/files/{$id}", ['X-User-Id' => '1']);
        $this->assertSame(204, $res->getStatusCode());

        $check = $this->req('GET', "/files/{$id}", ['X-User-Id' => '1']);
        $this->assertSame(404, $check->getStatusCode());
    }

    public function testDeleteOthersFileReturns404(): void
    {
        $cr = $this->createFile('2');
        $id = $this->json($cr)['id'];
        $res = $this->req('DELETE', "/files/{$id}", ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    // ─── POST /files/{id}/shares ─────────────────────────────────────────

    public function testAddShareRequiresAuth(): void
    {
        $res = $this->req('POST', '/files/1/shares', [], ['user_id' => 2]);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testAddShareByNonOwnerReturns404(): void
    {
        $cr = $this->createFile('1');
        $id = $this->json($cr)['id'];
        $res = $this->req('POST', "/files/{$id}/shares", ['X-User-Id' => '2'], ['user_id' => 3]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testAddShareReturns201(): void
    {
        $cr = $this->createFile('1');
        $id = $this->json($cr)['id'];
        $res = $this->req('POST', "/files/{$id}/shares", ['X-User-Id' => '1'], ['user_id' => 2]);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame(2, $data['shared_with_user_id']);
        $this->assertFalse($data['can_edit']);
    }

    public function testAddShareWithEditPermission(): void
    {
        $cr = $this->createFile('1');
        $id = $this->json($cr)['id'];
        $res = $this->req('POST', "/files/{$id}/shares", ['X-User-Id' => '1'], ['user_id' => 2, 'can_edit' => true]);
        $this->assertTrue($this->json($res)['can_edit']);
    }

    public function testAddShareDuplicateReturns409(): void
    {
        $cr = $this->createFile('1');
        $id = $this->json($cr)['id'];
        $this->req('POST', "/files/{$id}/shares", ['X-User-Id' => '1'], ['user_id' => 2]);
        $res = $this->req('POST', "/files/{$id}/shares", ['X-User-Id' => '1'], ['user_id' => 2]);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function testAddShareWithSelfReturns422(): void
    {
        $cr = $this->createFile('1');
        $id = $this->json($cr)['id'];
        $res = $this->req('POST', "/files/{$id}/shares", ['X-User-Id' => '1'], ['user_id' => 1]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ─── DELETE /files/{id}/shares/{userId} ──────────────────────────────

    public function testRemoveShareReturns204(): void
    {
        $cr = $this->createFile('1');
        $id = $this->json($cr)['id'];
        $this->req('POST', "/files/{$id}/shares", ['X-User-Id' => '1'], ['user_id' => 2]);
        $res = $this->req('DELETE', "/files/{$id}/shares/2", ['X-User-Id' => '1']);
        $this->assertSame(204, $res->getStatusCode());
    }

    public function testRemoveNonExistentShareReturns404(): void
    {
        $cr = $this->createFile('1');
        $id = $this->json($cr)['id'];
        $res = $this->req('DELETE', "/files/{$id}/shares/2", ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testRemoveShareByNonOwnerReturns404(): void
    {
        $cr = $this->createFile('1');
        $id = $this->json($cr)['id'];
        $this->req('POST', "/files/{$id}/shares", ['X-User-Id' => '1'], ['user_id' => 2]);
        $res = $this->req('DELETE', "/files/{$id}/shares/2", ['X-User-Id' => '3']);
        $this->assertSame(404, $res->getStatusCode());
    }
}
