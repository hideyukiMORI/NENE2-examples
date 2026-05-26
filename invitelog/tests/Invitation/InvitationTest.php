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

final class InvitationTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/invitelog-' . bin2hex(random_bytes(8)) . '.sqlite';
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

    // --- Users ---

    public function testCreateUserReturns201(): void
    {
        $res  = $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('alice@example.com', $body['email']);
    }

    public function testCreateDuplicateUserReturns409(): void
    {
        $this->req('POST', '/users', ['email' => 'dup@example.com', 'name' => 'Dup']);
        $res = $this->req('POST', '/users', ['email' => 'dup@example.com', 'name' => 'Dup2']);
        self::assertSame(409, $res->getStatusCode());
    }

    // --- Send invitation ---

    public function testSendInvitationReturns201WithToken(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $res  = $this->req('POST', '/users/1/invitations', ['email' => 'bob@example.com']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('bob@example.com', $body['email']);
        self::assertSame('pending', $body['status']);
        self::assertNotEmpty($body['token']);
    }

    public function testInviteAlreadyRegisteredEmailReturns409(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $this->req('POST', '/users', ['email' => 'bob@example.com', 'name' => 'Bob']);
        $res = $this->req('POST', '/users/1/invitations', ['email' => 'bob@example.com']);
        self::assertSame(409, $res->getStatusCode());
    }

    public function testSendInvitationFromNonExistentUserReturns404(): void
    {
        $res = $this->req('POST', '/users/999/invitations', ['email' => 'x@example.com']);
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Get invitation ---

    public function testGetInvitationReturns200(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'x@x.com']))['token'];

        $res  = $this->req('GET', "/invitations/{$token}");
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('pending', $body['status']);
    }

    public function testGetNonExistentInvitationReturns404(): void
    {
        $res = $this->req('GET', '/invitations/nosuchtoken');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Accept invitation ---

    public function testAcceptValidInvitationCreatesUser(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'bob@example.com']))['token'];

        $res  = $this->req('POST', "/invitations/{$token}/accept", ['name' => 'Bob']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('bob@example.com', $body['email']);
        self::assertSame('Bob', $body['name']);
    }

    public function testAcceptInvitationTwiceReturns409(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'carol@example.com']))['token'];
        $this->req('POST', "/invitations/{$token}/accept", ['name' => 'Carol']);

        $res = $this->req('POST', "/invitations/{$token}/accept", ['name' => 'Carol Again']);
        self::assertSame(409, $res->getStatusCode());
    }

    public function testAcceptExpiredInvitationReturns410(): void
    {
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $past = (new \DateTimeImmutable())->modify('-1 hour')->format('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO users (email, name, created_at) VALUES ('alice@example.com','Alice','{$past}')");
        $pdo->exec("INSERT INTO invitations (inviter_id, email, token, status, expires_at, created_at) VALUES (1,'eve@example.com','expiredtoken123','pending','{$past}','{$past}')");
        unset($pdo);

        $res = $this->req('POST', '/invitations/expiredtoken123/accept', ['name' => 'Eve']);
        self::assertSame(410, $res->getStatusCode());
    }

    public function testAcceptNonExistentTokenReturns404(): void
    {
        $res = $this->req('POST', '/invitations/nosuchtoken/accept', ['name' => 'Ghost']);
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Cancel invitation ---

    public function testCancelInvitationByInviterReturns204(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'd@example.com']))['token'];

        $res = $this->req('DELETE', "/invitations/{$token}", ['inviter_id' => 1]);
        self::assertSame(204, $res->getStatusCode());
    }

    public function testCancelInvitationByWrongUserReturns403(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $this->req('POST', '/users', ['email' => 'mallory@example.com', 'name' => 'Mallory']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'e@example.com']))['token'];

        $res = $this->req('DELETE', "/invitations/{$token}", ['inviter_id' => 2]);
        self::assertSame(403, $res->getStatusCode());
    }

    public function testAcceptCancelledInvitationReturns409(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice']);
        $token = $this->decode($this->req('POST', '/users/1/invitations', ['email' => 'f@example.com']))['token'];
        $this->req('DELETE', "/invitations/{$token}", ['inviter_id' => 1]);

        $res = $this->req('POST', "/invitations/{$token}/accept", ['name' => 'Frank']);
        self::assertSame(409, $res->getStatusCode());
    }
}
