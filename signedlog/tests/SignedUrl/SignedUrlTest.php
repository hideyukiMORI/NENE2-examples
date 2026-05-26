<?php

declare(strict_types=1);

namespace Signed\Tests\SignedUrl;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Signed\SignedUrl\FileRepository;
use Signed\SignedUrl\HmacSigner;
use Signed\SignedUrl\RouteRegistrar;

final class SignedUrlTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;
    private HmacSigner $signer;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/signedlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $dbConfig = new DatabaseConfig(
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

        $factory       = new PdoConnectionFactory($dbConfig);
        $executor      = new PdoDatabaseQueryExecutor($factory);
        $psr17         = new Psr17Factory();
        $json          = new JsonResponseFactory($psr17, $psr17);
        $problems      = new ProblemDetailsResponseFactory($psr17, $psr17);
        $this->signer  = new HmacSigner('test-secret-key-for-unit-tests-only');
        $files         = new FileRepository($executor);

        $registrar = new RouteRegistrar($files, $this->signer, $json, $problems);

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
        ))->create();
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function req(string $method, string $uri, mixed $body = null): ResponseInterface
    {
        $req = new ServerRequest($method, $uri);
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')
                       ->withBody((new Psr17Factory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($req);
    }

    private function decode(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- POST /files ---

    public function testCreateFileReturns201(): void
    {
        $res  = $this->req('POST', '/files', ['name' => 'report.pdf', 'owner_id' => 1]);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('report.pdf', $body['name']);
        self::assertSame(1, $body['owner_id']);
        self::assertSame('application/octet-stream', $body['mime_type']);
    }

    public function testCreateFileMissingNameReturns422(): void
    {
        $res = $this->req('POST', '/files', ['owner_id' => 1]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testCreateFileMissingOwnerReturns422(): void
    {
        $res = $this->req('POST', '/files', ['name' => 'file.txt']);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- POST /files/{id}/sign ---

    public function testSignUrlReturnsToken(): void
    {
        $this->req('POST', '/files', ['name' => 'doc.pdf', 'owner_id' => 1]);

        $res  = $this->req('POST', '/files/1/sign', ['ttl_seconds' => 300]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertArrayHasKey('token', $body);
        self::assertArrayHasKey('expires_at', $body);
        self::assertArrayHasKey('url', $body);
        self::assertSame(300, $body['ttl_seconds']);
        self::assertStringStartsWith('/download?token=', $body['url']);
    }

    public function testSignUrlDefaultTtlIs3600(): void
    {
        $this->req('POST', '/files', ['name' => 'doc.pdf', 'owner_id' => 1]);
        $res  = $this->req('POST', '/files/1/sign', (object) []);
        $body = $this->decode($res);

        self::assertSame(3600, $body['ttl_seconds']);
    }

    public function testSignNonExistentFileReturns404(): void
    {
        $res = $this->req('POST', '/files/999/sign', ['ttl_seconds' => 60]);
        self::assertSame(404, $res->getStatusCode());
    }

    // --- GET /download?token=... ---

    public function testDownloadWithValidTokenReturnsFile(): void
    {
        $this->req('POST', '/files', ['name' => 'image.png', 'owner_id' => 2, 'mime_type' => 'image/png']);
        $sign = $this->decode($this->req('POST', '/files/1/sign', ['ttl_seconds' => 3600]));

        $token = urlencode($sign['token']);
        $res   = $this->req('GET', '/download?token=' . $token);
        $body  = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('image.png', $body['name']);
        self::assertSame('image/png', $body['mime_type']);
    }

    public function testDownloadMissingTokenReturns401(): void
    {
        $res = $this->req('GET', '/download');
        self::assertSame(401, $res->getStatusCode());
    }

    public function testDownloadTamperedTokenReturns401(): void
    {
        $this->req('POST', '/files', ['name' => 'secret.pdf', 'owner_id' => 1]);
        $sign  = $this->decode($this->req('POST', '/files/1/sign', ['ttl_seconds' => 3600]));
        $token = $sign['token'];

        // Flip a character in the middle of the token (not the expiry part)
        $tampered = substr($token, 0, -4) . 'XXXX';

        $res = $this->req('GET', '/download?token=' . urlencode($tampered));
        self::assertSame(401, $res->getStatusCode());
    }

    public function testDownloadExpiredTokenReturns410(): void
    {
        // Sign manually with a past expiry
        $pastExpiry = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $token      = $this->signer->sign(999, $pastExpiry);

        $res = $this->req('GET', '/download?token=' . urlencode($token));
        self::assertSame(410, $res->getStatusCode());
    }

    public function testDownloadTokenBoundToSpecificResource(): void
    {
        // Create two files
        $this->req('POST', '/files', ['name' => 'file-a.pdf', 'owner_id' => 1]);
        $this->req('POST', '/files', ['name' => 'file-b.pdf', 'owner_id' => 1]);

        // Sign file 1 token
        $sign  = $this->decode($this->req('POST', '/files/1/sign', ['ttl_seconds' => 3600]));
        $token = urlencode($sign['token']);

        // Token for file 1 returns file 1, not file 2
        $res  = $this->req('GET', '/download?token=' . $token);
        $body = $this->decode($res);

        self::assertSame('file-a.pdf', $body['name']);
    }

    public function testDownloadRandomStringReturns401(): void
    {
        $res = $this->req('GET', '/download?token=totally-invalid-garbage');
        self::assertSame(401, $res->getStatusCode());
    }

    // --- HmacSigner unit tests ---

    public function testSignerRoundTrip(): void
    {
        $future = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $token  = $this->signer->sign(42, $future);

        self::assertSame(42, $this->signer->verify($token, $now));
    }

    public function testSignerExpiredReturnsNull(): void
    {
        $past  = (new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s');
        $now   = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $token = $this->signer->sign(1, $past);

        self::assertNull($this->signer->verify($token, $now));
    }

    public function testSignerWrongSecretReturnsNull(): void
    {
        $future      = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $otherSigner = new HmacSigner('different-secret');
        $token       = $otherSigner->sign(1, $future);

        self::assertNull($this->signer->verify($token, $now));
    }

    public function testSignerDifferentResourceIdsDifferentTokens(): void
    {
        $future = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

        $token1 = $this->signer->sign(1, $future);
        $token2 = $this->signer->sign(2, $future);

        self::assertNotSame($token1, $token2);
    }

    public function testSignerExtractExpiresAt(): void
    {
        $future = '2099-12-31 23:59:59';
        $token  = $this->signer->sign(1, $future);

        self::assertSame($future, $this->signer->extractExpiresAt($token));
    }
}
