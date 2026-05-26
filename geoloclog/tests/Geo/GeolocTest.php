<?php

declare(strict_types=1);

namespace GeolocLog\Tests\Geo;

use GeolocLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class GeolocTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/geoloclog_test_' . uniqid() . '.sqlite';
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

    private function createPlace(string $name, float $lat, float $lng, string $category = 'general'): int
    {
        $res = $this->req('POST', '/places', [
            'name'      => $name,
            'latitude'  => $lat,
            'longitude' => $lng,
            'category'  => $category,
        ]);
        return (int) $this->json($res)['id'];
    }

    // =========================================================================
    // CRUD

    public function testCreatePlaceReturns201(): void
    {
        $res  = $this->req('POST', '/places', [
            'name' => 'Tokyo Station', 'latitude' => 35.6812, 'longitude' => 139.7671,
        ]);
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Tokyo Station', $data['name']);
        $this->assertSame(35.6812, $data['latitude']);
    }

    public function testListPlaces(): void
    {
        $this->createPlace('A', 35.0, 139.0);
        $this->createPlace('B', 36.0, 140.0);
        $data = $this->json($this->req('GET', '/places'));
        $this->assertSame(2, $data['count']);
    }

    public function testGetPlace(): void
    {
        $id  = $this->createPlace('Shibuya', 35.658, 139.701);
        $res = $this->req('GET', "/places/{$id}");
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Shibuya', $this->json($res)['name']);
    }

    public function testGetNonexistentPlaceReturns404(): void
    {
        $res = $this->req('GET', '/places/9999');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testDeletePlace(): void
    {
        $id  = $this->createPlace('Tmp', 35.0, 139.0);
        $res = $this->req('DELETE', "/places/{$id}");
        $this->assertSame(204, $res->getStatusCode());
        $this->assertSame(404, $this->req('GET', "/places/{$id}")->getStatusCode());
    }

    public function testDeleteNonexistentPlaceReturns404(): void
    {
        $this->assertSame(404, $this->req('DELETE', '/places/9999')->getStatusCode());
    }

    // =========================================================================
    // Nearby search

    public function testNearbyReturnsPlacesWithinRadius(): void
    {
        // Tokyo Station ~2 km from Shibuya
        $this->createPlace('Tokyo Station', 35.6812, 139.7671);
        $this->createPlace('Osaka Station', 34.7024, 135.4959); // ~400 km away

        $res  = $this->req('GET', '/places/nearby', queryParams: [
            'lat' => '35.658', 'lng' => '139.701', 'radius_km' => '10',
        ]);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, $data['count']); // only Tokyo Station
        $this->assertSame('Tokyo Station', $data['places'][0]['name']);
        $this->assertArrayHasKey('distance_km', $data['places'][0]);
    }

    public function testNearbyResultsSortedByDistance(): void
    {
        $this->createPlace('Near', 35.66, 139.70);
        $this->createPlace('Far', 35.70, 139.74);

        $res    = $this->req('GET', '/places/nearby', queryParams: [
            'lat' => '35.658', 'lng' => '139.701', 'radius_km' => '10',
        ]);
        $places = $this->json($res)['places'];
        $this->assertSame('Near', $places[0]['name']);
    }

    public function testNearbyWithLargeRadiusReturnsAll(): void
    {
        $this->createPlace('Tokyo', 35.681, 139.767);
        $this->createPlace('Osaka', 34.702, 135.496);

        $res  = $this->req('GET', '/places/nearby', queryParams: [
            'lat' => '35.0', 'lng' => '135.0', 'radius_km' => '1000',
        ]);
        $this->assertSame(2, $this->json($res)['count']);
    }

    // =========================================================================
    // Bounding box search

    public function testBboxReturnsPlacesInBox(): void
    {
        $this->createPlace('In', 35.68, 139.76);
        $this->createPlace('Out', 34.70, 135.49);

        $res  = $this->req('GET', '/places/bbox', queryParams: [
            'min_lat' => '35.0', 'max_lat' => '36.0',
            'min_lng' => '139.0', 'max_lng' => '140.0',
        ]);
        $data = $this->json($res);
        $this->assertSame(1, $data['count']);
        $this->assertSame('In', $data['places'][0]['name']);
    }

    // =========================================================================
    // Validation

    public function testCreateRequiresName(): void
    {
        $res = $this->req('POST', '/places', ['latitude' => 35.0, 'longitude' => 139.0]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateRequiresValidLatitude(): void
    {
        $res = $this->req('POST', '/places', ['name' => 'X', 'latitude' => 'abc', 'longitude' => 139.0]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testNearbyRequiresRadius(): void
    {
        $res = $this->req('GET', '/places/nearby', queryParams: ['lat' => '35.0', 'lng' => '139.0']);
        $this->assertSame(422, $res->getStatusCode());
    }
}
