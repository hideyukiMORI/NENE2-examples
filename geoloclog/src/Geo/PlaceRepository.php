<?php

declare(strict_types=1);

namespace GeolocLog\Geo;

use Nene2\Database\DatabaseQueryExecutorInterface;

class PlaceRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db) {}

    public function create(string $name, float $lat, float $lng, string $category, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO places (name, latitude, longitude, category, created_at) VALUES (?, ?, ?, ?, ?)',
            [$name, $lat, $lng, $category, $now],
        );
    }

    /** @return list<array<string, mixed>> */
    public function findAll(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll('SELECT * FROM places ORDER BY id ASC', []);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT * FROM places WHERE id = ?', [$id]);
    }

    public function delete(int $id): bool
    {
        if ($this->find($id) === null) {
            return false;
        }
        $this->db->insert('DELETE FROM places WHERE id = ?', [$id]);
        return true;
    }

    /**
     * Bounding-box pre-filter for nearby search.
     * @return list<array<string, mixed>>
     */
    public function findInBoundingBox(float $minLat, float $maxLat, float $minLng, float $maxLng): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT * FROM places WHERE latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?',
            [$minLat, $maxLat, $minLng, $maxLng],
        );
    }
}
