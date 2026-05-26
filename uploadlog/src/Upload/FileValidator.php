<?php

declare(strict_types=1);

namespace Upload\Upload;

final class FileValidator
{
    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    private const int MAX_BYTES = 2 * 1024 * 1024; // 2 MiB

    /** Extensions that must never appear in stored filenames regardless of MIME type. */
    private const array DANGEROUS_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'bat',
    ];

    /**
     * Validate and decode a base64-encoded file upload.
     *
     * @return array{bytes: string, mime: string, size: int}
     * @throws UploadValidationException
     */
    public function validate(string $base64Content, string $originalFilename): array
    {
        // 1. Decode base64
        $bytes = base64_decode($base64Content, strict: true);
        if ($bytes === false) {
            throw new UploadValidationException(field: 'content', errorCode: 'invalid-base64', message: 'Content is not valid base64.');
        }

        // 2. Size check (decoded bytes, not base64 string length)
        $size = strlen($bytes);
        if ($size === 0) {
            throw new UploadValidationException('content', 'empty-file', 'File content must not be empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new UploadValidationException(
                'content',
                'file-too-large',
                sprintf('File size %d bytes exceeds the 2 MiB limit.', $size),
            );
        }

        // 3. MIME detection via finfo on actual bytes (never trust client-supplied type)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($bytes);
        if ($mime === false) {
            throw new UploadValidationException('content', 'mime-detection-failed', 'Could not detect file MIME type.');
        }

        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new UploadValidationException(
                'content',
                'unsupported-mime-type',
                sprintf('MIME type "%s" is not allowed. Allowed: %s.', $mime, implode(', ', self::ALLOWED_MIME_TYPES)),
            );
        }

        return ['bytes' => $bytes, 'mime' => $mime, 'size' => $size];
    }

    /**
     * Sanitize a filename against path traversal and other attacks.
     *
     * - Strips directory components (basename)
     * - Removes null bytes
     * - Removes leading dots (hidden files)
     * - Replaces whitespace and special chars with underscores
     * - Falls back to 'file' if result is empty
     */
    public function sanitizeFilename(string $filename): string
    {
        // Strip directory traversal
        $name = basename($filename);

        // Remove null bytes
        $name = str_replace("\x00", '', $name);

        // Remove leading dots (hidden files / extension-only names)
        $name = ltrim($name, '.');

        // Replace dangerous characters with underscores
        $name = preg_replace('/[^\w\-.]/', '_', $name) ?? '_';

        // Collapse multiple underscores/dots
        $name = preg_replace('/\.{2,}/', '.', $name) ?? $name;

        // Strip dangerous script extensions
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
            $name = pathinfo($name, PATHINFO_FILENAME) . '_' . $ext;
        }

        // Fallback
        if ($name === '' || $name === '.') {
            $name = 'file';
        }

        return $name;
    }

    public function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    /** @return list<string> */
    public function allowedMimeTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }
}
