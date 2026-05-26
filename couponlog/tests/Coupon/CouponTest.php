<?php

declare(strict_types=1);

namespace CouponLog\Tests\Coupon;

use CouponLog\Coupon\CouponRepository;
use CouponLog\Coupon\RouteRegistrar;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class CouponTest extends TestCase
{
    private \PDO $pdo;
    private Router $router;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));

        $now = date('c');
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Admin', 'admin', '$now')");
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Alice', 'user', '$now')");
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Bob', 'user', '$now')");

        $this->psr17 = new Psr17Factory();
        $this->router = $this->buildRouterWithPdo($this->pdo);
    }

    private function buildRouterWithPdo(\PDO $pdo): Router
    {
        $factory = new class ($pdo) implements DatabaseConnectionFactoryInterface {
            public function __construct(private readonly \PDO $pdo)
            {
            }
            public function create(): \PDO
            {
                return $this->pdo;
            }
        };
        $executor = new PdoDatabaseQueryExecutor($factory);
        $repository = new CouponRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $router = new Router();
        $registrar = new RouteRegistrar($router, $repository, $responseFactory);
        $registrar->register();
        return $router;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function post(string $path, mixed $body = [], array $headers = []): array
    {
        $allHeaders = array_merge(['Content-Type' => 'application/json'], $headers);
        $request = new ServerRequest('POST', $path, $allHeaders);
        $json = (is_array($body) && empty($body)) ? '{}' : (json_encode($body) ?: '{}');
        $request = $request->withBody($this->psr17->createStream($json));
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function get(string $path, array $headers = []): array
    {
        $request = new ServerRequest('GET', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function delete(string $path, array $headers = []): array
    {
        $request = new ServerRequest('DELETE', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function adminCreate(string $code, int $discountPct = 20, array $extra = []): array
    {
        return $this->post('/coupons', array_merge(['code' => $code, 'discount_pct' => $discountPct], $extra), [
            'X-User-Id' => '1',
            'X-User-Role' => 'admin',
        ]);
    }

    public function testCreateCoupon_returns201(): void
    {
        $result = $this->adminCreate('SAVE20');
        $this->assertSame(201, $result['status']);
        $this->assertSame('SAVE20', $result['body']['code']);
        $this->assertSame(20, $result['body']['discount_pct']);
        $this->assertSame(0, $result['body']['use_count']);
        $this->assertTrue($result['body']['is_active']);
    }

    public function testCreateCoupon_withMaxUses(): void
    {
        $result = $this->adminCreate('LIMITED', 10, ['max_uses' => 5]);
        $this->assertSame(201, $result['status']);
        $this->assertSame(5, $result['body']['max_uses']);
    }

    public function testCreateCoupon_withExpiry(): void
    {
        $result = $this->adminCreate('EXPIRING', 15, ['expires_at' => '2030-12-31T23:59:59+00:00']);
        $this->assertSame(201, $result['status']);
        $this->assertSame('2030-12-31T23:59:59+00:00', $result['body']['expires_at']);
    }

    public function testCreateCoupon_noAuth_returns401(): void
    {
        $result = $this->post('/coupons', ['code' => 'X', 'discount_pct' => 10]);
        $this->assertSame(401, $result['status']);
    }

    public function testCreateCoupon_nonAdmin_returns403(): void
    {
        $result = $this->post('/coupons', ['code' => 'X', 'discount_pct' => 10], [
            'X-User-Id' => '2',
            'X-User-Role' => 'user',
        ]);
        $this->assertSame(403, $result['status']);
    }

    public function testCreateCoupon_missingCode_returns422(): void
    {
        $result = $this->post('/coupons', ['discount_pct' => 10], [
            'X-User-Id' => '1',
            'X-User-Role' => 'admin',
        ]);
        $this->assertSame(422, $result['status']);
    }

    public function testCreateCoupon_invalidDiscount_returns422(): void
    {
        $result = $this->adminCreate('BAD', 150);
        $this->assertSame(422, $result['status']);
    }

    public function testGetCoupon_returns200(): void
    {
        $this->adminCreate('GETME', 25);
        $result = $this->get('/coupons/GETME');
        $this->assertSame(200, $result['status']);
        $this->assertSame('GETME', $result['body']['code']);
        $this->assertSame(25, $result['body']['discount_pct']);
    }

    public function testGetCoupon_notFound_returns404(): void
    {
        $result = $this->get('/coupons/NOSUCH');
        $this->assertSame(404, $result['status']);
    }

    public function testUseCoupon_returns201(): void
    {
        $this->adminCreate('USE20', 20);
        $result = $this->post('/coupons/USE20/use', [], ['X-User-Id' => '2']);
        $this->assertSame(201, $result['status']);
        $this->assertSame('USE20', $result['body']['code']);
        $this->assertSame(20, $result['body']['discount_pct']);
        $this->assertSame(2, $result['body']['user_id']);
    }

    public function testUseCoupon_incrementsUseCount(): void
    {
        $this->adminCreate('INC', 10);
        $this->post('/coupons/INC/use', [], ['X-User-Id' => '2']);
        $this->post('/coupons/INC/use', [], ['X-User-Id' => '3']);

        $result = $this->get('/coupons/INC');
        $this->assertSame(2, $result['body']['use_count']);
    }

    public function testUseCoupon_notFound_returns404(): void
    {
        $result = $this->post('/coupons/NOPE/use', [], ['X-User-Id' => '2']);
        $this->assertSame(404, $result['status']);
    }

    public function testUseCoupon_noAuth_returns401(): void
    {
        $this->adminCreate('AUTH', 10);
        $result = $this->post('/coupons/AUTH/use', []);
        $this->assertSame(401, $result['status']);
    }

    public function testUseCoupon_alreadyUsed_returns422(): void
    {
        $this->adminCreate('ONCE', 10);
        $this->post('/coupons/ONCE/use', [], ['X-User-Id' => '2']);
        $result = $this->post('/coupons/ONCE/use', [], ['X-User-Id' => '2']);
        $this->assertSame(422, $result['status']);
    }

    public function testUseCoupon_differentUsersCanBothUse(): void
    {
        $this->adminCreate('MULTI', 10);
        $r1 = $this->post('/coupons/MULTI/use', [], ['X-User-Id' => '2']);
        $r2 = $this->post('/coupons/MULTI/use', [], ['X-User-Id' => '3']);
        $this->assertSame(201, $r1['status']);
        $this->assertSame(201, $r2['status']);
    }

    public function testUseCoupon_inactive_returns422(): void
    {
        $this->adminCreate('INACTIVE', 10);
        $this->delete('/coupons/INACTIVE', ['X-User-Id' => '1', 'X-User-Role' => 'admin']);
        $result = $this->post('/coupons/INACTIVE/use', [], ['X-User-Id' => '2']);
        $this->assertSame(422, $result['status']);
    }

    public function testUseCoupon_expired_returns422(): void
    {
        $this->adminCreate('EXPIRED', 10, ['expires_at' => '2000-01-01T00:00:00+00:00']);
        $result = $this->post('/coupons/EXPIRED/use', [], ['X-User-Id' => '2']);
        $this->assertSame(422, $result['status']);
    }

    public function testUseCoupon_maxUsesReached_returns422(): void
    {
        $this->adminCreate('LIMIT1', 10, ['max_uses' => 1]);
        $this->post('/coupons/LIMIT1/use', [], ['X-User-Id' => '2']);
        $result = $this->post('/coupons/LIMIT1/use', [], ['X-User-Id' => '3']);
        $this->assertSame(422, $result['status']);
    }

    public function testDeactivateCoupon_returns200(): void
    {
        $this->adminCreate('DEACT', 10);
        $result = $this->delete('/coupons/DEACT', ['X-User-Id' => '1', 'X-User-Role' => 'admin']);
        $this->assertSame(200, $result['status']);

        $get = $this->get('/coupons/DEACT');
        $this->assertFalse($get['body']['is_active']);
    }

    public function testDeactivateCoupon_nonAdmin_returns403(): void
    {
        $this->adminCreate('SAFE', 10);
        $result = $this->delete('/coupons/SAFE', ['X-User-Id' => '2', 'X-User-Role' => 'user']);
        $this->assertSame(403, $result['status']);
    }

    public function testListUses_adminCanSee(): void
    {
        $this->adminCreate('HIST', 10);
        $this->post('/coupons/HIST/use', [], ['X-User-Id' => '2']);
        $this->post('/coupons/HIST/use', [], ['X-User-Id' => '3']);

        $result = $this->get('/coupons/HIST/uses', ['X-User-Id' => '1', 'X-User-Role' => 'admin']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['body']['use_count']);
        $this->assertCount(2, $result['body']['uses']);
    }

    public function testListUses_nonAdmin_returns403(): void
    {
        $this->adminCreate('HIST2', 10);
        $result = $this->get('/coupons/HIST2/uses', ['X-User-Id' => '2', 'X-User-Role' => 'user']);
        $this->assertSame(403, $result['status']);
    }
}
