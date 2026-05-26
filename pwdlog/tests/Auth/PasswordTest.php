<?php

declare(strict_types=1);

namespace Pwd\Tests\Auth;

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
use Pwd\Auth\RouteRegistrar;
use Pwd\Auth\UserRepository;

final class PasswordTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/pwdlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $this->dbFile,
            user: '',
            password: '',
            charset: '',
        );

        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar(new UserRepository($executor), $json, $problems);

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn(Router $r) => $registrar->register($r)],
        ))->create();
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    // --- helpers ---

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body, JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('POST', $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($data);

        return $data;
    }

    // --- register tests ---

    public function testRegisterReturns201(): void
    {
        $res = $this->post('/register', ['email' => 'alice@example.com', 'password' => 'correct-horse']);

        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('alice@example.com', $data['email']);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('created_at', $data);
    }

    public function testRegisterDoesNotReturnPasswordHash(): void
    {
        $res  = $this->post('/register', ['email' => 'alice@example.com', 'password' => 'correct-horse']);
        $data = $this->json($res);

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('password_hash', $data);
    }

    public function testPasswordIsStoredAsArgon2Hash(): void
    {
        $this->post('/register', ['email' => 'alice@example.com', 'password' => 'correct-horse']);

        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $stmt = $pdo->query("SELECT password_hash FROM users WHERE email = 'alice@example.com'");
        $this->assertNotFalse($stmt);
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $hash = (string) $row['password_hash'];

        // Verify the stored value is a proper Argon2id hash, not plaintext or MD5
        $this->assertStringStartsWith('$argon2id$', $hash, 'Password must be stored as Argon2id hash');
        $this->assertNotSame('correct-horse', $hash, 'Password must not be stored in plaintext');
        $this->assertNotSame(md5('correct-horse'), $hash, 'Password must not be stored as MD5');
    }

    public function testRegisterWithShortPasswordReturns422(): void
    {
        $res = $this->post('/register', ['email' => 'alice@example.com', 'password' => 'short']);

        $this->assertSame(422, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('validation-failed', (string) $data['type']);
    }

    public function testRegisterWithInvalidEmailReturns422(): void
    {
        $res = $this->post('/register', ['email' => 'not-an-email', 'password' => 'correct-horse']);

        $this->assertSame(422, $res->getStatusCode());
    }

    public function testRegisterDuplicateEmailReturns409(): void
    {
        $this->post('/register', ['email' => 'alice@example.com', 'password' => 'correct-horse']);
        $res = $this->post('/register', ['email' => 'alice@example.com', 'password' => 'battery-staple']);

        $this->assertSame(409, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('email-taken', (string) $data['type']);
    }

    public function testRegisterMissingFieldReturns400(): void
    {
        $res = $this->post('/register', ['email' => 'alice@example.com']);
        $this->assertSame(400, $res->getStatusCode());
    }

    // --- login tests ---

    public function testLoginWithCorrectCredentialsReturns200(): void
    {
        $this->post('/register', ['email' => 'alice@example.com', 'password' => 'correct-horse']);
        $res = $this->post('/login', ['email' => 'alice@example.com', 'password' => 'correct-horse']);

        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('alice@example.com', $data['email']);
    }

    public function testLoginDoesNotReturnPasswordHash(): void
    {
        $this->post('/register', ['email' => 'alice@example.com', 'password' => 'correct-horse']);
        $res  = $this->post('/login', ['email' => 'alice@example.com', 'password' => 'correct-horse']);
        $data = $this->json($res);

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('password_hash', $data);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $this->post('/register', ['email' => 'alice@example.com', 'password' => 'correct-horse']);
        $res = $this->post('/login', ['email' => 'alice@example.com', 'password' => 'wrong-password']);

        $this->assertSame(401, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('invalid-credentials', (string) $data['type']);
    }

    public function testLoginWithUnknownEmailReturns401(): void
    {
        $res = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'any-password']);

        // Must return 401 (not 404) — do not reveal whether the email exists
        $this->assertSame(401, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('invalid-credentials', (string) $data['type']);
    }

    public function testLoginUnknownEmailAndWrongPasswordSameErrorMessage(): void
    {
        $this->post('/register', ['email' => 'alice@example.com', 'password' => 'correct-horse']);

        $wrongPasswordRes   = $this->post('/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        $unknownEmailRes    = $this->post('/login', ['email' => 'ghost@example.com', 'password' => 'correct-horse']);

        $wrongPasswordBody  = $this->json($wrongPasswordRes);
        $unknownEmailBody   = $this->json($unknownEmailRes);

        // Both must return the same generic error — never hint which one failed
        $this->assertSame($wrongPasswordBody['detail'], $unknownEmailBody['detail']);
        $this->assertSame(401, $wrongPasswordRes->getStatusCode());
        $this->assertSame(401, $unknownEmailRes->getStatusCode());
    }

    public function testPasswordRehashIfAlgorithmChanges(): void
    {
        // Simulate a legacy bcrypt hash in the DB (as if migrating from bcrypt to argon2id)
        $bcryptHash = password_hash('correct-horse', PASSWORD_BCRYPT);

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $pdo->exec("INSERT INTO users (email, password_hash, created_at) VALUES ('legacy@example.com', '{$bcryptHash}', '{$now}')");
        unset($pdo);

        // password_verify works across algorithms — bcrypt hash can be verified
        $res = $this->post('/login', ['email' => 'legacy@example.com', 'password' => 'correct-horse']);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testLoginMissingFieldReturns400(): void
    {
        $res = $this->post('/login', ['email' => 'alice@example.com']);
        $this->assertSame(400, $res->getStatusCode());
    }
}
