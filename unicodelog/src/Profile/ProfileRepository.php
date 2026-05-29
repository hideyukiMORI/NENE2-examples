<?php

declare(strict_types=1);

namespace UnicodeLog\Profile;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ProfileRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /**
     * @param list<string> $tags
     */
    public function create(string $name, string $bio, array $tags, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO profiles (name, bio, tags, created_at) VALUES (?, ?, ?, ?)',
            [$name, $bio, $this->encodeTags($tags), $now],
        );
    }

    /**
     * @param list<string> $tags
     */
    public function update(int $id, string $name, string $bio, array $tags): void
    {
        $this->db->execute(
            'UPDATE profiles SET name = ?, bio = ?, tags = ? WHERE id = ?',
            [$name, $bio, $this->encodeTags($tags), $id],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM profiles WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll('SELECT * FROM profiles ORDER BY id DESC', []);
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM profiles WHERE id = ?', [$id]);
    }

    /** @param list<string> $tags */
    private function encodeTags(array $tags): string
    {
        // JSON_UNESCAPED_UNICODE keeps tags human-readable in storage.
        return json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
