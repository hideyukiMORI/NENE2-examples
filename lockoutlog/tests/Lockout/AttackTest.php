<?php

declare(strict_types=1);

namespace Lockout\Tests\Lockout;

use Lockout\Lockout\LockoutRepository;
use Lockout\Lockout\RouteRegistrar;
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

/**
 * Cracker-mindset attack tests for FT128.
 *
 * Each test simulates one adversarial technique. A passing test means
 * the application withstood the attack.
 */
final class AttackTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/lockoutlog-attack-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
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
        $repo      = new LockoutRepository($executor);
        $registrar = new RouteRegistrar($repo, $json, $problems);

        $this->app = new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn(Router $r) => $registrar->register($r)],
        )->create();
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
                       ->withBody(new Psr17Factory()->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($req);
    }

    private function seed(string $email = 'target@example.com', string $pass = 'secret123'): void
    {
        $this->req('POST', '/users', ['email' => $email, 'password' => $pass]);
    }

    // ATTACK-01: Brute-force past lockout threshold — must be blocked with 423
    public function testBruteForceTriggersLockout(): void
    {
        $this->seed();

        for ($i = 0; $i < 5; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'target@example.com', 'password' => 'attempt-' . $i]);
        }

        // 6th attempt — still wrong password but should be 423 (locked), not 401
        $res = $this->req('POST', '/auth/login', ['email' => 'target@example.com', 'password' => 'attempt-6']);
        self::assertSame(423, $res->getStatusCode(), 'Brute-force should trigger 423 after threshold');
    }

    // ATTACK-02: Correct password submitted after lockout — must NOT bypass 423
    public function testCorrectPasswordDoesNotBypassLockout(): void
    {
        $this->seed();

        for ($i = 0; $i < 5; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'target@example.com', 'password' => 'wrong']);
        }

        $res = $this->req('POST', '/auth/login', ['email' => 'target@example.com', 'password' => 'secret123']);
        self::assertSame(423, $res->getStatusCode(), 'Correct password must not bypass account lockout');
    }

    // ATTACK-03: Lockout DoS — attacker locks out a legitimate user
    public function testLockoutDoSBlocksLegitimateUser(): void
    {
        $this->seed('victim@example.com', 'correct-password');

        // Attacker submits 5 wrong passwords for victim
        for ($i = 0; $i < 5; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'victim@example.com', 'password' => 'attacker-guess-' . $i]);
        }

        // Legitimate user is now locked out
        $res = $this->req('POST', '/auth/login', ['email' => 'victim@example.com', 'password' => 'correct-password']);
        self::assertSame(423, $res->getStatusCode(), 'Lockout DoS should be possible (design trade-off documented)');
    }

    // ATTACK-04: User enumeration — unknown user and wrong password must return same HTTP status
    public function testUserEnumerationPrevented(): void
    {
        $this->seed();

        $existsWrong  = $this->req('POST', '/auth/login', ['email' => 'target@example.com', 'password' => 'bad']);
        $unknownEmail = $this->req('POST', '/auth/login', ['email' => 'ghost@example.com', 'password' => 'bad']);

        self::assertSame(
            $existsWrong->getStatusCode(),
            $unknownEmail->getStatusCode(),
            'Unknown vs wrong-password must return identical HTTP status to prevent enumeration',
        );
    }

    // ATTACK-05: Oversized email — must be rejected, not cause a DB error
    public function testOversizedEmailRejected(): void
    {
        $longEmail = str_repeat('a', 250) . '@x.com';
        $res       = $this->req('POST', '/auth/login', ['email' => $longEmail, 'password' => 'pass']);
        self::assertLessThan(500, $res->getStatusCode(), 'Oversized email must not cause a 500 error');
        self::assertGreaterThanOrEqual(400, $res->getStatusCode(), 'Oversized email must be rejected with 4xx');
    }

    // ATTACK-06: SQL injection in email field — must return 4xx, not 500 or a result row
    public function testSqlInjectionInEmail(): void
    {
        $this->seed();
        $payloads = [
            "' OR '1'='1",
            "' OR 1=1 --",
            "admin'--",
            "' UNION SELECT * FROM users --",
        ];

        foreach ($payloads as $payload) {
            $res = $this->req('POST', '/auth/login', ['email' => $payload, 'password' => 'pass']);
            self::assertNotSame(200, $res->getStatusCode(), "SQL injection '$payload' must not return 200");
            self::assertLessThan(500, $res->getStatusCode(), "SQL injection '$payload' must not cause 500");
        }
    }

    // ATTACK-07: Null byte injection in password — must not bypass verification
    public function testNullByteInPassword(): void
    {
        $this->seed('user@example.com', 'correct');
        $res = $this->req('POST', '/auth/login', ['email' => 'user@example.com', 'password' => "correct\0injected"]);
        self::assertSame(401, $res->getStatusCode(), 'Null byte injection must not bypass password check');
    }

    // ATTACK-08: Case-sensitivity in email — "ALICE" and "alice" must be treated as same account
    public function testEmailCaseSensitivityHandled(): void
    {
        $this->seed('alice@example.com', 'pass123');

        // 5 failures using uppercase email
        for ($i = 0; $i < 5; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'ALICE@EXAMPLE.COM', 'password' => 'wrong']);
        }

        // Attempt with lowercase — should still be 401 or 423, but NOT 200
        $res = $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        self::assertNotSame(200, $res->getStatusCode(), 'Case variant of email must not bypass lockout or authentication');
    }

    // ATTACK-09: Empty string email — must be rejected before touching the DB
    public function testEmptyEmailRejected(): void
    {
        $res = $this->req('POST', '/auth/login', ['email' => '', 'password' => 'pass']);
        self::assertSame(422, $res->getStatusCode());
    }

    // ATTACK-10: Whitespace-only email — strip + reject must not match any user
    public function testWhitespaceEmailRejected(): void
    {
        $this->seed('alice@example.com', 'pass');
        $res = $this->req('POST', '/auth/login', ['email' => '   ', 'password' => 'pass']);
        self::assertSame(422, $res->getStatusCode(), 'Whitespace-only email must be rejected as empty');
    }

    // ATTACK-11: Password not in response — must not leak hash or plaintext
    public function testPasswordNotLeakedOnSuccess(): void
    {
        $this->seed('bob@example.com', 'mypassword');
        $res  = $this->req('POST', '/auth/login', ['email' => 'bob@example.com', 'password' => 'mypassword']);
        $body = (string) $res->getBody();

        self::assertStringNotContainsString('mypassword', $body);
        self::assertStringNotContainsString('password_hash', $body);
        self::assertStringNotContainsString('$argon', $body);
    }

    // ATTACK-12: Multiple accounts — lockout on one account must not affect another
    public function testLockoutIsPerAccount(): void
    {
        $this->seed('alice@example.com', 'pass-alice');
        $this->seed('bob@example.com', 'pass-bob');

        // Lock alice
        for ($i = 0; $i < 5; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        }

        // Bob must still be able to login
        $res = $this->req('POST', '/auth/login', ['email' => 'bob@example.com', 'password' => 'pass-bob']);
        self::assertSame(200, $res->getStatusCode(), 'Lockout on alice must not affect bob');
    }
}
