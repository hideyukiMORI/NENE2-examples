<?php

declare(strict_types=1);

namespace GeolocLog\Geo;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly PlaceRepository $repo,
        private readonly GeoCalculator $geo,
        private readonly JsonResponseFactory $json,
    ) {}

    public function register(Router $router): void
    {
        // Fixed paths must be registered before pattern paths
        $router->get('/places/nearby', $this->handleNearby(...));
        $router->get('/places/bbox', $this->handleBbox(...));
        $router->get('/places', $this->handleList(...));
        $router->post('/places', $this->handleCreate(...));
        $router->get('/places/{id}', $this->handleGet(...));
        $router->delete('/places/{id}', $this->handleDelete(...));
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $body     = (array) ($request->getParsedBody() ?? []);
        $errors   = [];

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            $errors[] = new ValidationError('name', 'name is required', 'required');
        }

        [$lat, $lng, $coordErrors] = $this->parseCoords(
            $body['latitude'] ?? null,
            $body['longitude'] ?? null,
        );
        $errors = array_merge($errors, $coordErrors);

        $category = isset($body['category']) && is_string($body['category']) ? trim($body['category']) : 'general';

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now   = $this->now();
        $id    = $this->repo->create($name, $lat, $lng, $category, $now);
        $place = $this->repo->find($id);
        assert($place !== null);
        return $this->json->create($place, 201);
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $places = $this->repo->findAll();
        return $this->json->create(['places' => $places, 'count' => count($places)]);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $id    = $this->id($request);
        $place = $this->repo->find($id);
        if ($place === null) {
            return $this->json->create(['error' => 'Place not found'], 404);
        }
        return $this->json->create($place);
    }

    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->id($request);
        if (!$this->repo->delete($id)) {
            return $this->json->create(['error' => 'Place not found'], 404);
        }
        return $this->json->createEmpty(204);
    }

    private function handleNearby(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $errors = [];

        [$lat, $lng, $coordErrors] = $this->parseCoords($q['lat'] ?? null, $q['lng'] ?? null);
        $errors = array_merge($errors, $coordErrors);

        $radiusKm = 0.0;
        if (!isset($q['radius_km']) || !is_numeric($q['radius_km'])) {
            $errors[] = new ValidationError('radius_km', 'radius_km must be a positive number', 'invalid');
        } else {
            $radiusKm = (float) $q['radius_km'];
            if ($radiusKm <= 0) {
                $errors[] = new ValidationError('radius_km', 'radius_km must be positive', 'invalid');
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $radiusKm = $this->geo->clampRadius($radiusKm);
        $box      = $this->geo->boundingBox($lat, $lng, $radiusKm);

        $candidates = $this->repo->findInBoundingBox(
            $box['min_lat'],
            $box['max_lat'],
            $box['min_lng'],
            $box['max_lng'],
        );

        $results = [];
        foreach ($candidates as $place) {
            $dist = $this->geo->haversineKm($lat, $lng, (float) $place['latitude'], (float) $place['longitude']);
            if ($dist <= $radiusKm) {
                $results[] = array_merge($place, ['distance_km' => round($dist, 4)]);
            }
        }

        usort($results, static fn(array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);

        return $this->json->create(['places' => $results, 'count' => count($results), 'center' => ['lat' => $lat, 'lng' => $lng], 'radius_km' => $radiusKm]);
    }

    private function handleBbox(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $errors = [];

        $params = ['min_lat', 'max_lat', 'min_lng', 'max_lng'];
        $vals   = [];
        foreach ($params as $p) {
            if (!isset($q[$p]) || !is_numeric($q[$p])) {
                $errors[] = new ValidationError($p, "{$p} must be a number", 'invalid');
            } else {
                $vals[$p] = (float) $q[$p];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        assert(isset($vals['min_lat'], $vals['max_lat'], $vals['min_lng'], $vals['max_lng']));

        if ($vals['min_lat'] > $vals['max_lat']) {
            throw new ValidationException([new ValidationError('min_lat', 'min_lat must be ≤ max_lat', 'invalid')]);
        }
        if ($vals['min_lng'] > $vals['max_lng']) {
            throw new ValidationException([new ValidationError('min_lng', 'min_lng must be ≤ max_lng', 'invalid')]);
        }

        $places = $this->repo->findInBoundingBox(
            $vals['min_lat'],
            $vals['max_lat'],
            $vals['min_lng'],
            $vals['max_lng'],
        );
        return $this->json->create(['places' => $places, 'count' => count($places)]);
    }

    /**
     * @param mixed $rawLat
     * @param mixed $rawLng
     * @return array{0: float, 1: float, 2: list<ValidationError>}
     */
    private function parseCoords(mixed $rawLat, mixed $rawLng): array
    {
        $errors = [];
        $lat    = 0.0;
        $lng    = 0.0;

        if ($rawLat === null || $rawLat === '' || !is_numeric($rawLat)) {
            $errors[] = new ValidationError('latitude', 'latitude must be a number', 'invalid');
        } else {
            $lat = (float) $rawLat;
            if ($lat < -90.0 || $lat > 90.0) {
                $errors[] = new ValidationError('latitude', 'latitude must be between -90 and 90', 'invalid');
            }
        }

        if ($rawLng === null || $rawLng === '' || !is_numeric($rawLng)) {
            $errors[] = new ValidationError('longitude', 'longitude must be a number', 'invalid');
        } else {
            $lng = (float) $rawLng;
            if ($lng < -180.0 || $lng > 180.0) {
                $errors[] = new ValidationError('longitude', 'longitude must be between -180 and 180', 'invalid');
            }
        }

        return [$lat, $lng, $errors];
    }

    private function id(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['id'] ?? 0);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
