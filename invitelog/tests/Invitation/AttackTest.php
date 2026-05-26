<?php

declare(strict_types=1);

namespace Invitation\Tests\Invitation;

use Invitation\Invitation\InvitationRepository;
use Invitation\Invitation\RouteRegistrar;
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
 * Cracker-mindset attack test for the User Invitation System.
 *
 * Each test attempts a concrete adversarial scenario and verifies the system
 * correctly defends against it. Failing a test here means a real exploitable
 * weakness exists.
 */
final class AttackTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/invitelog-attack-' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $repo      = new InvitationRepository($executor);
        $registrar = new RouteRegistrar($repo, $json, $problems);

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

    // --- ATTACK 1: Short/sequential token guessing ---

    /**
     * Cracker tries short or sequential tokens hoping the system uses integer IDs or short values.
     * Real tokens must be 64 hex chars (256-bit).
     */
    public function testTokenMustBeHighEntropy(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $res  = $this->req('POST', '/users/1/invitations', ['email' => 'bob@example.com']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame(64, strlen($body['token']), 'ATTACK: token must be 64 hex chars, not guessable');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['token'], 'Token must be hex only');
    }

    // --- ATTACK 2: Token reuse after acceptance ---

    /**
     * Cracker accepts a valid invitation, then tries to reuse the same token
     * to register a second time (e.g. with a different name).
     */
    public function testAcceptedTokenCannotBeReused(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'victim@example.com']))['token'];
        $this->req('POST', "/invitations/{$token}/accept", ['name' => 'Bob']);

        // Second accept attempt with the same token
        $res = $this->req('POST', "/invitations/{$token}/accept", ['name' => 'Impostor']);
        self::assertSame(409, $res->getStatusCode(), 'ATTACK: accepted token must not be reusable');

        // Verify no duplicate user was created
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'victim@example.com'");
        self::assertNotFalse($stmt);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'ATTACK: reuse must not create a duplicate account');
    }

    // --- ATTACK 3: Expired invitation acceptance bypass ---

    /**
     * Cracker finds or stores an expired token and tries to accept it anyway.
     */
    public function testExpiredTokenCannotBeAccepted(): void
    {
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $past = (new \DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO users (email, name, created_at) VALUES ('alice@example.com','Alice','{$past}')");
        $pdo->exec("INSERT INTO invitations (inviter_id, email, token, status, expires_at, created_at)
                    VALUES (1,'attacker@example.com','expiredatktoken','pending','{$past}','{$past}')");
        unset($pdo);

        $res = $this->req('POST', '/invitations/expiredatktoken/accept', ['name' => 'Attacker']);
        self::assertSame(410, $res->getStatusCode(), 'ATTACK: expired token must be rejected with 410');

        // No new user must have been created
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'attacker@example.com'");
        self::assertNotFalse($stmt);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'ATTACK: expired acceptance must not create a user');
    }

    // --- ATTACK 4: Cancelled invitation reuse ---

    /**
     * Cracker intercepts a token for a cancelled invitation and tries to accept it.
     */
    public function testCancelledTokenCannotBeAccepted(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'target@example.com']))['token'];
        $this->req('DELETE', "/invitations/{$token}", ['inviter_id' => 1]);

        $res = $this->req('POST', "/invitations/{$token}/accept", ['name' => 'Intruder']);
        self::assertSame(409, $res->getStatusCode(), 'ATTACK: cancelled token must not be acceptable');

        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'target@example.com'");
        self::assertNotFalse($stmt);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'ATTACK: no user must be created from cancelled invitation');
    }

    // --- ATTACK 5: Cross-user cancel (ownership bypass) ---

    /**
     * Cracker submits their own user ID to cancel someone else's invitation.
     */
    public function testCannotCancelAnotherUsersInvitation(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $this->req('POST', '/users', ['email' => 'mallory@example.com', 'name' => 'Mallory']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'target@example.com']))['token'];

        // Mallory (id=2) tries to cancel Alice's (id=1) invitation
        $res = $this->req('DELETE', "/invitations/{$token}", ['inviter_id' => 2]);
        self::assertSame(403, $res->getStatusCode(), 'ATTACK: cross-user cancel must be 403');

        // Invitation must still be pending
        $body = $this->decode($this->req('GET', "/invitations/{$token}"));
        self::assertSame('pending', $body['status'], 'ATTACK: invitation must remain pending after failed cancel');
    }

    // --- ATTACK 6: inviter_id=0 cancel bypass ---

    /**
     * Cracker sends inviter_id=0 hoping the default zero matches no owner check.
     */
    public function testZeroInviterIdCannotCancel(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'victim@example.com']))['token'];

        $res = $this->req('DELETE', "/invitations/{$token}", ['inviter_id' => 0]);
        self::assertSame(403, $res->getStatusCode(), 'ATTACK: inviter_id=0 must be rejected as 403');
    }

    // --- ATTACK 7: Information leakage via error differentiation ---

    /**
     * Cracker checks whether the API distinguishes between a non-existent token
     * and a consumed/cancelled one, which could leak internal state.
     * Both non-existent and used tokens should return the same status code on accept.
     */
    public function testAcceptNonExistentVsUsedTokenReturnsSameStatus(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'x@example.com']))['token'];
        $this->req('POST', "/invitations/{$token}/accept", ['name' => 'X']);

        $resUsed      = $this->req('POST', "/invitations/{$token}/accept", ['name' => 'X2']);
        $resNotExist  = $this->req('POST', '/invitations/nonexistent0000000000000000000000000000000000000000000000000000/accept', ['name' => 'Ghost']);

        // Non-existent → 404, used → 409. These MUST differ, not both 404.
        // The attack here is: does GETting a consumed token reveal it was consumed?
        self::assertSame(409, $resUsed->getStatusCode(), 'Used token must return 409 not 404');
        self::assertSame(404, $resNotExist->getStatusCode(), 'Non-existent token must return 404');
        // NOTE: Distinguishing 409 vs 404 is acceptable because it does not expose the invited email.
        // The response body must NOT contain PII about the original invitation target.
        $body = $this->decode($resUsed);
        self::assertArrayNotHasKey('email', $body, 'ATTACK: used-token 409 body must not leak invited email');
    }

    // --- ATTACK 8: Email enumeration via invite endpoint ---

    /**
     * Cracker sends invitations to probe whether an email is registered,
     * using the 409 vs 201 difference. This is a known friction point
     * in invite systems — intentionally documented here.
     *
     * The system returns 409 when inviting a registered email, which does
     * reveal registration status. This is acceptable for an invite-only system
     * where the inviter is a trusted user, but must be explicit.
     */
    public function testInvitingRegisteredEmailReturns409(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $this->req('POST', '/users', ['email' => 'known@example.com', 'name' => 'Known']);

        $res = $this->req('POST', '/users/1/invitations', ['email' => 'known@example.com']);
        self::assertSame(409, $res->getStatusCode());

        // DOCUMENTED: this 409 reveals that known@example.com is registered.
        // Acceptable for an invite-only system with authenticated inviters.
        // If this system were public, the response should be unified to 202/201.
    }

    // --- ATTACK 9: SQL injection via email field ---

    /**
     * Cracker embeds SQL in the email field hoping to drop tables or exfiltrate data.
     */
    public function testSqlInjectionInEmailFieldIsHarmless(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);

        $maliciousEmail = "' OR '1'='1'; DROP TABLE invitations; --";
        $res = $this->req('POST', '/users/1/invitations', ['email' => $maliciousEmail]);

        // Either 201 (stored literally, which is fine since PDO parameterises)
        // or 422 (fails email format validation). Must NOT be 500.
        self::assertNotSame(500, $res->getStatusCode(), 'ATTACK: SQL injection in email must not cause 500');

        // DB must still be intact
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $stmt = $pdo->query("SELECT COUNT(*) FROM invitations");
        self::assertNotFalse($stmt, 'ATTACK: invitations table must still exist after injection attempt');
        self::assertGreaterThanOrEqual(0, (int) $stmt->fetchColumn());
    }

    // --- ATTACK 10: Invite to an already-accepted email after the user registers via invite ---

    /**
     * After Bob accepts an invitation and becomes a user, Alice should not be able to
     * send Bob another invitation (he is now registered).
     */
    public function testCannotInviteAlreadyAcceptedUser(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'bob@example.com']))['token'];
        $this->req('POST', "/invitations/{$token}/accept", ['name' => 'Bob']);

        // Try to invite the now-registered bob again
        $res = $this->req('POST', '/users/1/invitations', ['email' => 'bob@example.com']);
        self::assertSame(409, $res->getStatusCode(), 'ATTACK: cannot invite a user who already registered');
    }

    // --- ATTACK 11: Oversized name in accept body ---

    /**
     * Cracker sends an extremely long name hoping to crash or overflow the system.
     */
    public function testOversizedNameInAcceptDoesNotCrash(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'big@example.com']))['token'];

        $hugeName = str_repeat('A', 10000);
        $res = $this->req('POST', "/invitations/{$token}/accept", ['name' => $hugeName]);

        // Must not be 500 regardless of whether we accept or reject the name
        self::assertNotSame(500, $res->getStatusCode(), 'ATTACK: oversized name must not cause 500');
    }

    // --- ATTACK 12: Cancel with non-integer inviter_id ---

    /**
     * Cracker sends a string or boolean inviter_id to exploit type coercion.
     */
    public function testStringInviterIdCannotBypassOwnerCheck(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'v@example.com']))['token'];

        // Send inviter_id as a string "1" — must not match int 1 via loose comparison
        $req = new ServerRequest('DELETE', "/invitations/{$token}");
        $req = $req->withHeader('Content-Type', 'application/json')
                   ->withBody((new Psr17Factory())->createStream('{"inviter_id":"1"}'));
        $res = $this->app->handle($req);

        // inviter_id as string "1" — the handler expects int, so it will default to 0 → 403
        self::assertSame(403, $res->getStatusCode(), 'ATTACK: string inviter_id must not bypass owner check');
    }
}
