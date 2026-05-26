<?php

declare(strict_types=1);

namespace Signed\SignedUrl;

final readonly class FileRecord
{
    public function __construct(
        public int    $id,
        public string $name,
        public string $mimeType,
        public int    $sizeBytes,
        public int    $ownerId,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'mime_type'  => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'owner_id'   => $this->ownerId,
            'created_at' => $this->createdAt,
        ];
    }
}
