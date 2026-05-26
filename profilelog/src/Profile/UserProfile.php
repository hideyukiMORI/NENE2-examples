<?php

declare(strict_types=1);

namespace Profile\Profile;

final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH         = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH  = 2048;

    public function __construct(
        public int $id,
        public int $userId,
        public string $displayName,
        public string $bio,
        public string $avatarUrl,
        public string $updatedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'user_id'      => $this->userId,
            'display_name' => $this->displayName,
            'bio'          => $this->bio,
            'avatar_url'   => $this->avatarUrl,
            'updated_at'   => $this->updatedAt,
        ];
    }
}
