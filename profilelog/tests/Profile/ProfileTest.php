<?php

declare(strict_types=1);

namespace Profile\Tests\Profile;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Profile\AppFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ProfileTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/profilelog-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $this->app = AppFactory::createSqliteApp($this->dbFile);
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private function request(
        string $method,
        string $path,
        mixed $body = null,
        array $headers = [],
        array $query = [],
    ): ResponseInterface {
        $req = new ServerRequest($method, $path);

        if ($body !== null) {
            $req = $req
                ->withBody(Stream::create((string) json_encode($body)))
                ->withHeader('Content-Type', 'application/json');
        }

        foreach ($headers as $name => $value) {
            $req = $req->withHeader($name, $value);
        }

        if ($query !== []) {
            $req = $req->withQueryParams($query);
        }

        return $this->app->handle($req);
    }

    private function json(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function createUser(string $email = 'alice@example.com'): int
    {
        $res = $this->request('POST', '/users', ['email' => $email]);
        $this->assertSame(201, $res->getStatusCode());

        return (int) $this->json($res)['id'];
    }

    // --- User creation ---

    public function testCreateUser(): void
    {
        $res  = $this->request('POST', '/users', ['email' => 'alice@example.com']);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('alice@example.com', $body['email']);
        $this->assertIsInt($body['id']);
    }

    public function testCreateUserInvalidEmailReturns422(): void
    {
        $res = $this->request('POST', '/users', ['email' => 'not-an-email']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateUserEmptyEmailReturns422(): void
    {
        $res = $this->request('POST', '/users', ['email' => '']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateUserDuplicateEmailReturns409(): void
    {
        $this->request('POST', '/users', ['email' => 'dup@example.com']);
        $res = $this->request('POST', '/users', ['email' => 'dup@example.com']);
        $this->assertSame(409, $res->getStatusCode());
    }

    // --- Profile creation ---

    public function testCreateProfile(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', "/users/{$userId}/profile", [
            'display_name' => 'Alice',
            'bio'          => 'Hello world',
            'avatar_url'   => 'https://example.com/alice.png',
        ]);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Alice', $body['display_name']);
        $this->assertSame('Hello world', $body['bio']);
        $this->assertSame('https://example.com/alice.png', $body['avatar_url']);
        $this->assertSame($userId, $body['user_id']);
    }

    public function testCreateProfileWithDefaults(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', "/users/{$userId}/profile", ['other' => 'value']);
        $body   = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('', $body['display_name']);
        $this->assertSame('', $body['bio']);
        $this->assertSame('', $body['avatar_url']);
    }

    public function testCreateProfileDuplicateReturns409(): void
    {
        $userId = $this->createUser();
        $this->request('POST', "/users/{$userId}/profile", ['display_name' => 'Alice', 'bio' => '', 'avatar_url' => '']);
        $res = $this->request('POST', "/users/{$userId}/profile", ['display_name' => 'Alice2', 'bio' => '', 'avatar_url' => '']);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function testCreateProfileUnknownUserReturns404(): void
    {
        $res = $this->request('POST', '/users/9999/profile', ['display_name' => 'X', 'bio' => '', 'avatar_url' => '']);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Get profile ---

    public function testGetProfile(): void
    {
        $userId = $this->createUser();
        $this->request('POST', "/users/{$userId}/profile", [
            'display_name' => 'Alice',
            'bio'          => 'Bio text',
            'avatar_url'   => 'https://example.com/alice.png',
        ]);

        $res  = $this->request('GET', "/users/{$userId}/profile");
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Alice', $body['display_name']);
    }

    public function testGetProfileNotFoundReturns404(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('GET', "/users/{$userId}/profile");
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGetProfileUnknownUserReturns404(): void
    {
        $res = $this->request('GET', '/users/9999/profile');
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Update profile ---

    public function testUpdateProfile(): void
    {
        $userId = $this->createUser();
        $this->request('POST', "/users/{$userId}/profile", ['display_name' => 'Alice', 'bio' => '', 'avatar_url' => '']);

        $res  = $this->request(
            'PUT',
            "/users/{$userId}/profile",
            ['display_name' => 'Alice Updated', 'bio' => 'New bio', 'avatar_url' => 'https://cdn.example.com/a.png'],
            ['X-User-Id' => (string) $userId],
        );
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Alice Updated', $body['display_name']);
        $this->assertSame('New bio', $body['bio']);
    }

    public function testUpdateProfileForbiddenWithoutHeader(): void
    {
        $userId = $this->createUser();
        $this->request('POST', "/users/{$userId}/profile", ['display_name' => 'Alice', 'bio' => '', 'avatar_url' => '']);

        $res = $this->request('PUT', "/users/{$userId}/profile", ['display_name' => 'X', 'bio' => '', 'avatar_url' => '']);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testUpdateProfileForbiddenDifferentUser(): void
    {
        $userId1 = $this->createUser('alice@example.com');
        $userId2 = $this->createUser('bob@example.com');
        $this->request('POST', "/users/{$userId1}/profile", ['display_name' => 'Alice', 'bio' => '', 'avatar_url' => '']);

        // Bob tries to update Alice's profile
        $res = $this->request(
            'PUT',
            "/users/{$userId1}/profile",
            ['display_name' => 'Hacked', 'bio' => '', 'avatar_url' => ''],
            ['X-User-Id' => (string) $userId2],
        );
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testUpdateProfileNotFoundReturns404(): void
    {
        $userId = $this->createUser();
        $res    = $this->request(
            'PUT',
            "/users/{$userId}/profile",
            ['display_name' => 'X', 'bio' => '', 'avatar_url' => ''],
            ['X-User-Id' => (string) $userId],
        );
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Validation ---

    public function testBioTooLongReturns422(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', "/users/{$userId}/profile", [
            'display_name' => 'Alice',
            'bio'          => str_repeat('a', 501),
            'avatar_url'   => '',
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testDisplayNameTooLongReturns422(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', "/users/{$userId}/profile", [
            'display_name' => str_repeat('x', 101),
            'bio'          => '',
            'avatar_url'   => '',
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testInvalidAvatarUrlReturns422(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', "/users/{$userId}/profile", [
            'display_name' => 'Alice',
            'bio'          => '',
            'avatar_url'   => 'http://example.com/pic.png',
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testValidHttpsAvatarUrl(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', "/users/{$userId}/profile", [
            'display_name' => 'Alice',
            'bio'          => '',
            'avatar_url'   => 'https://cdn.example.com/pic.jpg',
        ]);
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testEmptyAvatarUrlIsAllowed(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', "/users/{$userId}/profile", [
            'display_name' => 'Alice',
            'bio'          => '',
            'avatar_url'   => '',
        ]);
        $this->assertSame(201, $res->getStatusCode());
    }
}
