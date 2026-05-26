<?php

declare(strict_types=1);

namespace Invitation\Invitation;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class InvitationRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function createUser(string $email, string $name, string $now): ?User
    {
        try {
            $this->executor->execute(
                'INSERT INTO users (email, name, created_at) VALUES (?, ?, ?)',
                [$email, $name, $now],
            );
        } catch (\RuntimeException) {
            return null;
        }

        return $this->findUserByEmail($email);
    }

    public function findUserById(int $id): ?User
    {
        $row = $this->executor->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrateUser($row) : null;
    }

    public function findUserByEmail(string $email): ?User
    {
        $row = $this->executor->fetchOne('SELECT * FROM users WHERE email = ?', [$email]);

        return $row !== null ? $this->hydrateUser($row) : null;
    }

    public function createInvitation(int $inviterId, string $email, string $token, string $expiresAt, string $now): Invitation
    {
        $this->executor->execute(
            'INSERT INTO invitations (inviter_id, email, token, status, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$inviterId, $email, $token, 'pending', $expiresAt, $now],
        );

        return $this->findByToken($token);
    }

    public function findByToken(string $token): Invitation
    {
        $row = $this->executor->fetchOne('SELECT * FROM invitations WHERE token = ?', [$token]);

        if ($row === null) {
            throw new \RuntimeException('Invitation not found');
        }

        return $this->hydrateInvitation($row);
    }

    public function findByTokenOrNull(string $token): ?Invitation
    {
        $row = $this->executor->fetchOne('SELECT * FROM invitations WHERE token = ?', [$token]);

        return $row !== null ? $this->hydrateInvitation($row) : null;
    }

    public function accept(string $token, string $now): Invitation
    {
        $this->executor->execute(
            "UPDATE invitations SET status = 'accepted', accepted_at = ? WHERE token = ?",
            [$now, $token],
        );

        return $this->findByToken($token);
    }

    public function cancel(string $token): Invitation
    {
        $this->executor->execute(
            "UPDATE invitations SET status = 'cancelled' WHERE token = ?",
            [$token],
        );

        return $this->findByToken($token);
    }

    /** @param array<string, mixed> $row */
    private function hydrateUser(array $row): User
    {
        return new User(
            id:        (int) $row['id'],
            email:     (string) $row['email'],
            name:      (string) $row['name'],
            createdAt: (string) $row['created_at'],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateInvitation(array $row): Invitation
    {
        return new Invitation(
            id:         (int) $row['id'],
            inviterId:  (int) $row['inviter_id'],
            email:      (string) $row['email'],
            token:      (string) $row['token'],
            status:     (string) $row['status'],
            expiresAt:  (string) $row['expires_at'],
            acceptedAt: isset($row['accepted_at']) ? (string) $row['accepted_at'] : null,
            createdAt:  (string) $row['created_at'],
        );
    }
}
