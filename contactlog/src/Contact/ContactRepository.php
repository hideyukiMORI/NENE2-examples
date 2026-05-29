<?php

declare(strict_types=1);

namespace ContactLog\Contact;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

class ContactRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null owner-scoped (IDOR-safe) */
    public function findContact(int $id, string $ownerId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, owner_id, name, email, phone, notes, created_at, updated_at FROM contacts WHERE id = ? AND owner_id = ?',
            [$id, $ownerId],
        );
    }

    /**
     * Dynamic LIKE search (name/email) + optional EXISTS group filter.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $ownerId, ?string $query, ?int $groupId): array
    {
        $conds = ['c.owner_id = ?'];
        $params = [$ownerId];

        if ($query !== null && $query !== '') {
            // Escape LIKE wildcards so user input matches literally.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            $conds[] = "(c.name LIKE ? ESCAPE '\\' OR c.email LIKE ? ESCAPE '\\')";
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
        }
        if ($groupId !== null) {
            $conds[] = 'EXISTS (SELECT 1 FROM contact_groups cg WHERE cg.contact_id = c.id AND cg.group_id = ?)';
            $params[] = $groupId;
        }

        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT c.id, c.owner_id, c.name, c.email, c.phone, c.notes, c.created_at, c.updated_at
             FROM contacts c WHERE ' . implode(' AND ', $conds) . ' ORDER BY c.name ASC',
            $params,
        );
    }

    public function createContact(string $ownerId, string $name, string $email, string $phone, string $notes, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO contacts (owner_id, name, email, phone, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$ownerId, $name, $email, $phone, $notes, $now, $now],
        );
    }

    public function updateContact(int $id, string $ownerId, string $name, string $email, string $phone, string $notes, string $now): bool
    {
        return $this->db->execute(
            'UPDATE contacts SET name = ?, email = ?, phone = ?, notes = ?, updated_at = ? WHERE id = ? AND owner_id = ?',
            [$name, $email, $phone, $notes, $now, $id, $ownerId],
        ) > 0;
    }

    public function deleteContact(int $id, string $ownerId): bool
    {
        // Also drop the contact's group memberships.
        if ($this->db->execute('DELETE FROM contacts WHERE id = ? AND owner_id = ?', [$id, $ownerId]) === 0) {
            return false;
        }
        $this->db->execute('DELETE FROM contact_groups WHERE contact_id = ?', [$id]);
        return true;
    }

    /** Returns the new group id, or null when the name already exists for this owner. */
    public function createGroup(string $ownerId, string $name, string $now): ?int
    {
        try {
            return $this->db->insert(
                'INSERT INTO groups (owner_id, name, created_at) VALUES (?, ?, ?)',
                [$ownerId, $name, $now],
            );
        } catch (DatabaseConstraintException) {
            return null;
        }
    }

    /** @return list<array{id: int, name: string}> the contact's groups */
    public function groupsOf(int $contactId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->fetchAll(
            'SELECT g.id, g.name FROM groups g
             JOIN contact_groups cg ON cg.group_id = g.id
             WHERE cg.contact_id = ? ORDER BY g.name ASC',
            [$contactId],
        );
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    /**
     * Idempotent add: a duplicate membership (composite PK) is treated as success.
     *
     * @return 'not_found'|'ok'
     */
    public function addToGroup(int $contactId, int $groupId, string $ownerId): string
    {
        if (!$this->ownsBoth($contactId, $groupId, $ownerId)) {
            return 'not_found';
        }
        try {
            $this->db->execute('INSERT INTO contact_groups (contact_id, group_id) VALUES (?, ?)', [$contactId, $groupId]);
        } catch (DatabaseConstraintException) {
            // Already a member — idempotent.
        }
        return 'ok';
    }

    /**
     * @return 'not_found'|'ok'
     */
    public function removeFromGroup(int $contactId, int $groupId, string $ownerId): string
    {
        if ($this->findContact($contactId, $ownerId) === null) {
            return 'not_found';
        }
        $removed = $this->db->execute('DELETE FROM contact_groups WHERE contact_id = ? AND group_id = ?', [$contactId, $groupId]);
        return $removed > 0 ? 'ok' : 'not_found';
    }

    private function ownsBoth(int $contactId, int $groupId, string $ownerId): bool
    {
        $c = $this->db->fetchOne('SELECT 1 AS x FROM contacts WHERE id = ? AND owner_id = ?', [$contactId, $ownerId]);
        $g = $this->db->fetchOne('SELECT 1 AS x FROM groups WHERE id = ? AND owner_id = ?', [$groupId, $ownerId]);
        return $c !== null && $g !== null;
    }
}
