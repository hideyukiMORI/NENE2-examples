<?php

declare(strict_types=1);

namespace EventSource\Tests\EventSource;

use EventSource\EventSource\EventSourceRepository;
use EventSource\EventSource\RouteRegistrar;
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

final class EventSourceTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/eventsourcelog-' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $repo      = new EventSourceRepository($executor);
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

    private function decode(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- Account creation ---

    public function testCreateAccountReturns201(): void
    {
        $res  = $this->req('POST', '/accounts', ['owner' => 'Alice']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('Alice', $body['owner']);
    }

    public function testCreateAccountMissingOwnerReturns422(): void
    {
        $res = $this->req('POST', '/accounts', ['owner' => '']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testGetNonExistentAccountBalanceReturns404(): void
    {
        $res = $this->req('GET', '/accounts/999/balance');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Deposit ---

    public function testDepositReturns201(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Bob']));
        $res     = $this->req('POST', "/accounts/{$account['id']}/deposit", ['amount' => 1000]);

        self::assertSame(201, $res->getStatusCode());
    }

    public function testDepositZeroReturns422(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Carol']));
        $res     = $this->req('POST', "/accounts/{$account['id']}/deposit", ['amount' => 0]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testDepositNegativeReturns422(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Dan']));
        $res     = $this->req('POST', "/accounts/{$account['id']}/deposit", ['amount' => -100]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testDepositOversizedAmountReturns422(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Dan2']));
        $res     = $this->req('POST', "/accounts/{$account['id']}/deposit", ['amount' => 2_000_000_000]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testDepositFloatAmountReturns422(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Dan3']));
        $res     = $this->req('POST', "/accounts/{$account['id']}/deposit", ['amount' => 1.9]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testDepositToNonExistentAccountReturns404(): void
    {
        $res = $this->req('POST', '/accounts/999/deposit', ['amount' => 100]);
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Withdraw ---

    public function testWithdrawReturns201(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Eve']));
        $this->req('POST', "/accounts/{$account['id']}/deposit", ['amount' => 500]);
        $res = $this->req('POST', "/accounts/{$account['id']}/withdraw", ['amount' => 200]);

        self::assertSame(201, $res->getStatusCode());
    }

    public function testWithdrawMoreThanBalanceReturns422(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Frank']));
        $this->req('POST', "/accounts/{$account['id']}/deposit", ['amount' => 100]);
        $res = $this->req('POST', "/accounts/{$account['id']}/withdraw", ['amount' => 200]);

        self::assertSame(422, $res->getStatusCode());
    }

    public function testWithdrawFromEmptyAccountReturns422(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Grace']));
        $res     = $this->req('POST', "/accounts/{$account['id']}/withdraw", ['amount' => 50]);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- Balance via replay ---

    public function testBalanceAfterDepositAndWithdraw(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Heidi']));
        $id      = $account['id'];

        $this->req('POST', "/accounts/{$id}/deposit", ['amount' => 1000]);
        $this->req('POST', "/accounts/{$id}/deposit", ['amount' => 500]);
        $this->req('POST', "/accounts/{$id}/withdraw", ['amount' => 300]);

        $res  = $this->req('GET', "/accounts/{$id}/balance");
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(1200, $body['balance']);
    }

    public function testNewAccountBalanceIsZero(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Ivan']));
        $res     = $this->req('GET', "/accounts/{$account['id']}/balance");
        $body    = $this->decode($res);

        self::assertSame(0, $body['balance']);
    }

    // --- Event list ---

    public function testEventListContainsAllEvents(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Judy']));
        $id      = $account['id'];

        $this->req('POST', "/accounts/{$id}/deposit", ['amount' => 200]);
        $this->req('POST', "/accounts/{$id}/withdraw", ['amount' => 50]);

        $res  = $this->req('GET', "/accounts/{$id}/events");
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        // account_created + deposited + withdrawn = 3
        self::assertCount(3, $body['events']);
        self::assertSame('account_created', $body['events'][0]['event_type']);
        self::assertSame('deposited', $body['events'][1]['event_type']);
        self::assertSame('withdrawn', $body['events'][2]['event_type']);
    }

    public function testEventPayloadContainsAmount(): void
    {
        $account = $this->decode($this->req('POST', '/accounts', ['owner' => 'Karl']));
        $this->req('POST', "/accounts/{$account['id']}/deposit", ['amount' => 750]);

        $res    = $this->req('GET', "/accounts/{$account['id']}/events");
        $body   = $this->decode($res);
        $events = $body['events'];

        self::assertSame(750, $events[1]['payload']['amount']);
    }

    // --- Isolation: separate accounts ---

    public function testSeparateAccountsDoNotShareBalance(): void
    {
        $a1 = $this->decode($this->req('POST', '/accounts', ['owner' => 'Lena']));
        $a2 = $this->decode($this->req('POST', '/accounts', ['owner' => 'Mike']));

        $this->req('POST', "/accounts/{$a1['id']}/deposit", ['amount' => 500]);

        $res1 = $this->decode($this->req('GET', "/accounts/{$a1['id']}/balance"));
        $res2 = $this->decode($this->req('GET', "/accounts/{$a2['id']}/balance"));

        self::assertSame(500, $res1['balance']);
        self::assertSame(0, $res2['balance']);
    }
}
