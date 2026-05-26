<?php

declare(strict_types=1);

namespace Upload\Upload;

final readonly class UploadedFile
{
    public function __construct(
        public int $id,
        public string $originalFilename,
        public string $storedFilename,
        public string $mimeType,
        public int $sizeBytes,
        public string $uploadedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'original_filename' => $this->originalFilename,
            'stored_filename'   => $this->storedFilename,
            'mime_type'         => $this->mimeType,
            'size_bytes'        => $this->sizeBytes,
            'uploaded_at'       => $this->uploadedAt,
        ];
    }
}
