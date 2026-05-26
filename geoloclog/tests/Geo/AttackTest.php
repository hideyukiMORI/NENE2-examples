<?php

declare(strict_types=1);

namespace GeolocLog\Tests\Geo;

use GeolocLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * クラッカー攻撃試験 ATK-01〜12 (FT164)
 *
 * 攻撃者視点でGeolocation APIの脆弱性を突く12テスト。
 */
final class AttackTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/geoloclog_atk_' . uniqid() . '.sqlite';
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

    /** @param array<string, string> $queryParams */
    private function req(string $method, string $path, mixed $body = null, array $queryParams = []): ResponseInterface
    {
        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        if ($queryParams !== []) {
            $request = $request->withQueryParams($queryParams);
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $res->getBody(), true);
    }

    // ATK-01: SQLインジェクション — nameフィールドに悪意のある文字列を送り込む
    public function testAtk01SqlInjectionInName(): void
    {
        $res = $this->req('POST', '/places', [
            'name'      => "'; DROP TABLE places; --",
            'latitude'  => 35.0,
            'longitude' => 139.0,
        ]);
        // Should succeed (201) — injection treated as literal string
        $this->assertSame(201, $res->getStatusCode());

        // DB must still be intact (list should return 1 place)
        $list = $this->req('GET', '/places');
        $this->assertSame(200, $list->getStatusCode());
        $this->assertSame(1, $this->json($list)['count']);
    }

    // ATK-02: SQLインジェクション — nearby radius_km に悪意のある文字列
    public function testAtk02SqlInjectionInRadiusParam(): void
    {
        $res = $this->req('GET', '/places/nearby', queryParams: [
            'lat' => '35.0', 'lng' => '139.0', 'radius_km' => "10; DROP TABLE places; --",
        ]);
        // Non-numeric radius must be rejected (422)
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-03: 緯度範囲外（+91）でバリデーションをバイパスしようとする
    public function testAtk03LatitudeOverflow(): void
    {
        $res = $this->req('POST', '/places', [
            'name' => 'BadLat', 'latitude' => 91.0, 'longitude' => 0.0,
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-04: 緯度範囲外（-91）
    public function testAtk04LatitudeUnderflow(): void
    {
        $res = $this->req('POST', '/places', [
            'name' => 'BadLat2', 'latitude' => -91.0, 'longitude' => 0.0,
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-05: 経度範囲外（+181）
    public function testAtk05LongitudeOverflow(): void
    {
        $res = $this->req('POST', '/places', [
            'name' => 'BadLng', 'latitude' => 0.0, 'longitude' => 181.0,
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-06: NaN文字列をlatitudeに渡す
    public function testAtk06NanLatitude(): void
    {
        $res = $this->req('POST', '/places', [
            'name' => 'NaN', 'latitude' => 'NaN', 'longitude' => 139.0,
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-07: ゼロ以下の負の半径でnearby検索を試みる
    public function testAtk07NegativeRadius(): void
    {
        $res = $this->req('GET', '/places/nearby', queryParams: [
            'lat' => '35.0', 'lng' => '139.0', 'radius_km' => '-1',
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-08: 巨大な半径（地球半周超）がクランプされて正常終了する
    public function testAtk08HugeRadiusClamped(): void
    {
        $res = $this->req('GET', '/places/nearby', queryParams: [
            'lat' => '0.0', 'lng' => '0.0', 'radius_km' => '999999999',
        ]);
        // Should return 200, not crash or return unvalidated results
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        // radius in response must be clamped to MAX (20000 km)
        $this->assertLessThanOrEqual(20000.0, (float) $data['radius_km']);
    }

    // ATK-09: bbox min_lat > max_lat（反転バウンディングボックス）
    public function testAtk09InvertedBbox(): void
    {
        $res = $this->req('GET', '/places/bbox', queryParams: [
            'min_lat' => '36.0', 'max_lat' => '35.0',
            'min_lng' => '139.0', 'max_lng' => '140.0',
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-10: bbox に非数値パラメータを渡す
    public function testAtk10NonNumericBboxParam(): void
    {
        $res = $this->req('GET', '/places/bbox', queryParams: [
            'min_lat' => 'abc', 'max_lat' => '36.0',
            'min_lng' => '139.0', 'max_lng' => '140.0',
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-11: 存在しないIDへのDELETEリクエストが繰り返されても500にならない
    public function testAtk11RepeatedDeleteNonexistent(): void
    {
        $this->assertSame(404, $this->req('DELETE', '/places/9999')->getStatusCode());
        $this->assertSame(404, $this->req('DELETE', '/places/9999')->getStatusCode());
        $this->assertSame(404, $this->req('DELETE', '/places/9999')->getStatusCode());
    }

    // ATK-12: nearbyのlatパラメータ欠落でバリデーションエラーが返る
    public function testAtk12NearbyMissingLatParam(): void
    {
        $res = $this->req('GET', '/places/nearby', queryParams: [
            'lng' => '139.0', 'radius_km' => '10',
        ]);
        $this->assertSame(422, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertArrayHasKey('errors', $data);
    }
}
