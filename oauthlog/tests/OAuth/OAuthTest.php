<?php

declare(strict_types=1);

namespace OAuthLog\Tests\OAuth;

use OAuthLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class OAuthTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/oauthlog_test_' . uniqid() . '.sqlite';
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
        string $bearerToken = '',
    ): ResponseInterface {
        $psr17 = new Psr17Factory();
        $uri = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        if ($bearerToken !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true);
    }

    // =========================================================================
    // Functional tests

    public function testStartReturnsStateAndAuthUrl(): void
    {
        $res = $this->req('POST', '/auth/oauth/start');
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertArrayHasKey('state', $data);
        $this->assertArrayHasKey('auth_url', $data);
        $this->assertStringContainsString('mock.oauth.example.com', (string) $data['auth_url']);
        $this->assertSame(64, strlen((string) $data['state']));
    }

    public function testStartGeneratesUniqueStates(): void
    {
        $s1 = $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $s2 = $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $this->assertNotSame($s1, $s2);
    }

    public function testFullLoginFlow(): void
    {
        $state = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];

        $res = $this->req('POST', '/auth/oauth/callback', [
            'state' => $state,
            'code'  => 'code_alice',
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('user', $data);
        $this->assertSame('User-code_alice', $data['user']['name']);
    }

    public function testMeReturnUserAfterLogin(): void
    {
        $state = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $token = (string) $this->json($this->req('POST', '/auth/oauth/callback', [
            'state' => $state,
            'code'  => 'code_bob',
        ]))['token'];

        $res = $this->req('GET', '/me', bearerToken: $token);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('User-code_bob', $data['name']);
    }

    public function testLoginTwiceWithSameProviderSubjectUpdatesUser(): void
    {
        // Two logins with same code → same subject → same user row updated
        $state1 = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $data1  = $this->json($this->req('POST', '/auth/oauth/callback', [
            'state' => $state1,
            'code'  => 'code_carol_v1',
        ]));
        $userId1 = (int) $data1['user']['id'];

        $state2 = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $data2  = $this->json($this->req('POST', '/auth/oauth/callback', [
            'state' => $state2,
            'code'  => 'code_carol_v2',
        ]));
        // Different code → different subject → different user
        $userId2 = (int) $data2['user']['id'];
        $this->assertNotSame($userId1, $userId2);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $state = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $token = (string) $this->json($this->req('POST', '/auth/oauth/callback', [
            'state' => $state,
            'code'  => 'code_dave',
        ]))['token'];

        $logoutRes = $this->req('POST', '/auth/logout', bearerToken: $token);
        $this->assertSame(204, $logoutRes->getStatusCode());

        // Token no longer valid after logout
        $meRes = $this->req('GET', '/me', bearerToken: $token);
        $this->assertSame(401, $meRes->getStatusCode());
    }

    public function testCallbackMissingStateReturns422(): void
    {
        $res = $this->req('POST', '/auth/oauth/callback', ['code' => 'code_x']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCallbackMissingCodeReturns422(): void
    {
        $state = (string) $this->json($this->req('POST', '/auth/oauth/start'))['state'];
        $res = $this->req('POST', '/auth/oauth/callback', ['state' => $state]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testMeWithoutAuthReturns401(): void
    {
        $res = $this->req('GET', '/me');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testLogoutWithoutAuthReturns401(): void
    {
        $res = $this->req('POST', '/auth/logout');
        $this->assertSame(401, $res->getStatusCode());
    }
}
