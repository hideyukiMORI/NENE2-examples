<?php

declare(strict_types=1);

namespace Upload\Tests\Upload;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Upload\Upload\FileValidator;
use Upload\Upload\RouteRegistrar;
use Upload\Upload\SqliteUploadRepository;

final class FileUploadTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile   = '';
    private string $storeDir = '';

    protected function setUp(): void
    {
        $this->dbFile   = sys_get_temp_dir() . '/uploadlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->storeDir = sys_get_temp_dir() . '/uploadlog-store-' . bin2hex(random_bytes(8));
        mkdir($this->storeDir, 0o755, true);

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $dbConfig  = new DatabaseConfig(
            url:         null,
            environment: 'test',
            adapter:     'sqlite',
            host:        '',
            port:        1,
            name:        $this->dbFile,
            user:        '',
            password:    '',
            charset:     '',
        );
        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $validator = new FileValidator();
        $repo      = new SqliteUploadRepository($executor, $this->storeDir);
        $registrar = new RouteRegistrar($repo, $validator, $json, $problems);

        // Allow up to 10 MiB request bodies so base64-encoded 2 MiB files fit.
        // base64 adds ~33% overhead: 2 MiB file → ~2.7 MiB base64 + JSON envelope.
        // Default NENE2 limit is 1 MiB — too small for base64 file uploads without adjustment.
        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars:    [static fn (Router $r) => $registrar->register($r)],
            requestMaxBodyBytes: 10 * 1024 * 1024,
        ))->create();
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
        if ($this->storeDir !== '' && is_dir($this->storeDir)) {
            foreach (glob($this->storeDir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->storeDir);
        }
    }

    // --- helpers ---

    private function post(string $path, mixed $body): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body, JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('POST', $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        return $this->app->handle($request);
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle(new ServerRequest('GET', $path));
    }

    private function delete(string $path): ResponseInterface
    {
        return $this->app->handle(new ServerRequest('DELETE', $path));
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function jsonList(ResponseInterface $response): array
    {
        /** @var list<array<string, mixed>> */
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Minimal valid 1×1 JPEG (from PHP's GD or a known-good fixture) */
    private function minimalJpeg(): string
    {
        // 1×1 white JPEG — raw bytes
        $bytes = "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
               . "\xff\xdb\x00C\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\t\t\x08\n\x0c"
               . "\x14\r\x0c\x0b\x0b\x0c\x19\x12\x13\x0f\x14\x1d\x1a\x1f\x1e\x1d\x1a\x1c"
               . "\x1c $.' \",#\x1c\x1c(7),01444\x1f'9=82<.342\x1e\x1f=N;I<474;"
               . "\xff\xc0\x00\x0b\x08\x00\x01\x00\x01\x01\x01\x11\x00\xff\xc4\x00\x1f\x00"
               . "\x00\x01\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x01"
               . "\x02\x03\x04\x05\x06\x07\x08\t\n\x0b\xff\xc4\x00\xb5\x10\x00\x02\x01\x03"
               . "\x03\x02\x04\x03\x05\x05\x04\x04\x00\x00\x01}\x01\x02\x03\x00\x04\x11\x05"
               . "\x12!1A\x06\x13Qa\x07\"q\x142\x81\x91\xa1\x08#B\xb1\xc1\x15R\xd1"
               . "\xf0$3br\x82\t\n\x16\x17\x18\x19\x1a%&'()*456789:CDEFGHIJ"
               . "STUVWXYZcdefghijstuvwxyz\x83\x84\x85\x86\x87\x88\x89\x8a\x92\x93\x94\x95"
               . "\x96\x97\x98\x99\x9a\xa2\xa3\xa4\xa5\xa6\xa7\xa8\xa9\xaa\xb2\xb3\xb4\xb5"
               . "\xb6\xb7\xb8\xb9\xba\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xd2\xd3\xd4\xd5"
               . "\xd6\xd7\xd8\xd9\xda\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xf1\xf2\xf3"
               . "\xf4\xf5\xf6\xf7\xf8\xf9\xfa\xff\xda\x00\x08\x01\x01\x00\x00?\x00\xfb\xd2"
               . "\x8a(\x03\xff\xd9";

        return $bytes;
    }

    /** Minimal valid 1×1 PNG */
    private function minimalPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
    }

    // --- happy path ---

    public function testUploadValidJpeg(): void
    {
        $res = $this->post('/uploads', [
            'content'  => base64_encode($this->minimalJpeg()),
            'filename' => 'photo.jpg',
        ]);

        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('photo.jpg', $data['original_filename']);
        $this->assertSame('image/jpeg', $data['mime_type']);
        $this->assertGreaterThan(0, $data['size_bytes']);

        // File actually written to storage
        $stored = $this->storeDir . '/' . $data['stored_filename'];
        $this->assertFileExists($stored);
    }

    public function testUploadValidPng(): void
    {
        $res = $this->post('/uploads', [
            'content'  => base64_encode($this->minimalPng()),
            'filename' => 'image.png',
        ]);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('image/png', $this->json($res)['mime_type']);
    }

    public function testGetUpload(): void
    {
        $res = $this->post('/uploads', [
            'content'  => base64_encode($this->minimalPng()),
            'filename' => 'test.png',
        ]);
        $id = (int) $this->json($res)['id'];

        $get = $this->get("/uploads/{$id}");
        $this->assertSame(200, $get->getStatusCode());
        $this->assertSame($id, (int) $this->json($get)['id']);
    }

    public function testListUploads(): void
    {
        $this->post('/uploads', ['content' => base64_encode($this->minimalJpeg()), 'filename' => 'a.jpg']);
        $this->post('/uploads', ['content' => base64_encode($this->minimalPng()), 'filename' => 'b.png']);

        $res = $this->get('/uploads');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(2, $this->jsonList($res));
    }

    public function testDeleteUpload(): void
    {
        $res = $this->post('/uploads', ['content' => base64_encode($this->minimalPng()), 'filename' => 'del.png']);
        $id  = (int) $this->json($res)['id'];

        $stored = $this->storeDir . '/' . $this->json($res)['stored_filename'];

        $del = $this->delete("/uploads/{$id}");
        $this->assertSame(200, $del->getStatusCode());
        $this->assertFileDoesNotExist($stored); // file removed from disk
        $this->assertSame(404, $this->get("/uploads/{$id}")->getStatusCode());
    }

    // --- MIME type validation ---

    public function testRejectsPlainTextFile(): void
    {
        $res = $this->post('/uploads', [
            'content'  => base64_encode("Hello, world!"),
            'filename' => 'hello.txt',
        ]);

        $this->assertSame(422, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('validation-failed', (string) ($data['type'] ?? ''));
        $errors = (array) ($data['errors'] ?? []);
        $this->assertNotEmpty($errors);
        $first = (array) $errors[0];
        $this->assertSame('unsupported-mime-type', $first['code']);
    }

    public function testRejectsPdfFile(): void
    {
        // PDF magic bytes
        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n";
        $res = $this->post('/uploads', [
            'content'  => base64_encode($pdf),
            'filename' => 'document.pdf',
        ]);

        $this->assertSame(422, $res->getStatusCode());
    }

    /** PHP script disguised as JPEG — content check catches it */
    public function testRejectsPhpScriptWithJpegExtension(): void
    {
        $phpCode = '<?php system($_GET["cmd"]); ?>';
        $res     = $this->post('/uploads', [
            'content'  => base64_encode($phpCode),
            'filename' => 'avatar.jpg',  // extension says JPEG
        ]);

        // finfo detects text/x-php or text/plain — rejected regardless of filename
        $this->assertSame(422, $res->getStatusCode());
        $errors = (array) ($this->json($res)['errors'] ?? []);
        $first  = (array) $errors[0];
        $this->assertSame('unsupported-mime-type', $first['code']);
    }

    // --- Size limit ---

    public function testRejectsFileThatExceedsSizeLimit(): void
    {
        // 3 MiB of zeros — exceeds 2 MiB limit
        $bigContent = str_repeat("\x00", 3 * 1024 * 1024);
        $res        = $this->post('/uploads', [
            'content'  => base64_encode($bigContent),
            'filename' => 'huge.bin',
        ]);

        $this->assertSame(422, $res->getStatusCode());
        $errors = (array) ($this->json($res)['errors'] ?? []);
        $first  = (array) $errors[0];
        $this->assertSame('file-too-large', $first['code']);
    }

    public function testRejectsEmptyContent(): void
    {
        // base64_encode('') = '' — RouteRegistrar sees it as a missing/empty field
        // and returns 'required' before FileValidator is reached.
        // This is correct: there is no meaningful way to distinguish "absent" from
        // "base64 of empty bytes" via the JSON API layer.
        $res = $this->post('/uploads', [
            'content'  => base64_encode(''),
            'filename' => 'empty.jpg',
        ]);

        $this->assertSame(422, $res->getStatusCode());
        $errors = (array) ($this->json($res)['errors'] ?? []);
        $this->assertNotEmpty($errors);
        $first = (array) $errors[0];
        $this->assertSame('content', $first['field']);
    }

    // --- Path traversal prevention ---

    public function testPathTraversalInFilenameIsStripped(): void
    {
        $res = $this->post('/uploads', [
            'content'  => base64_encode($this->minimalPng()),
            'filename' => '../../../etc/passwd',
        ]);

        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);

        // original_filename preserves what was sent
        $this->assertSame('../../../etc/passwd', $data['original_filename']);

        // stored_filename must NOT contain directory separators
        $stored = (string) $data['stored_filename'];
        $this->assertStringNotContainsString('..', $stored);
        $this->assertStringNotContainsString('/', $stored);

        // File was NOT written outside storage dir
        $this->assertFileDoesNotExist('/etc/passwd_overwritten_by_test');
        $this->assertFileExists($this->storeDir . '/' . $stored);
    }

    public function testWindowsPathTraversalIsStripped(): void
    {
        $res = $this->post('/uploads', [
            'content'  => base64_encode($this->minimalPng()),
            'filename' => '..\\..\\Windows\\System32\\evil.png',
        ]);

        $this->assertSame(201, $res->getStatusCode());
        $stored = (string) $this->json($res)['stored_filename'];
        $this->assertStringNotContainsString('..', $stored);
    }

    public function testNullByteInFilenameIsRemoved(): void
    {
        $res = $this->post('/uploads', [
            'content'  => base64_encode($this->minimalPng()),
            'filename' => "image.png\x00.php",
        ]);

        $this->assertSame(201, $res->getStatusCode());
        $stored = (string) $this->json($res)['stored_filename'];
        $this->assertStringNotContainsString("\x00", $stored);
        // .php is neutralised: stored as image.png_php (extension replaced with suffix)
        $this->assertStringNotContainsString('.php', $stored);
    }

    public function testDotsOnlyFilenameGetsFallback(): void
    {
        $res = $this->post('/uploads', [
            'content'  => base64_encode($this->minimalPng()),
            'filename' => '...',
        ]);

        $this->assertSame(201, $res->getStatusCode());
    }

    // --- Invalid base64 ---

    public function testRejectsInvalidBase64(): void
    {
        $res = $this->post('/uploads', [
            'content'  => 'not-valid-base64!!!',
            'filename' => 'test.jpg',
        ]);

        $this->assertSame(422, $res->getStatusCode());
        $errors = (array) ($this->json($res)['errors'] ?? []);
        $first  = (array) $errors[0];
        $this->assertSame('invalid-base64', $first['code']);
    }

    // --- Missing fields ---

    public function testMissingContentReturns422(): void
    {
        $res = $this->post('/uploads', ['filename' => 'test.jpg']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testMissingFilenameReturns422(): void
    {
        $res = $this->post('/uploads', ['content' => base64_encode($this->minimalPng())]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // --- Not found ---

    public function testGetNonExistentUploadReturns404(): void
    {
        $res = $this->get('/uploads/99999');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testDeleteNonExistentUploadReturns404(): void
    {
        $res = $this->delete('/uploads/99999');
        $this->assertSame(404, $res->getStatusCode());
    }
}
