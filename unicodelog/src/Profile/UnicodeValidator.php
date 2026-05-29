<?php

declare(strict_types=1);

namespace UnicodeLog\Profile;

use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;

/**
 * Unicode-aware field validation: counts codepoints with mb_strlen (never bytes),
 * rejects null bytes before anything else, and validates a tag array.
 */
final class UnicodeValidator
{
    private const int NAME_MAX = 50;
    private const int BIO_MAX = 500;
    private const int TAG_MAX = 30;
    private const int TAGS_MAX = 10;

    public function name(mixed $raw): string
    {
        if (!is_string($raw)) {
            throw $this->error('name', 'invalid', 'name must be a string');
        }
        $this->rejectNullByte($raw, 'name');
        if (mb_strlen($raw, 'UTF-8') === 0) {
            throw $this->error('name', 'required', 'name is required');
        }
        // mb_strlen counts codepoints — 50 Japanese chars (150 bytes) pass; 51 fail.
        if (mb_strlen($raw, 'UTF-8') > self::NAME_MAX) {
            throw $this->error('name', 'too_long', 'Max ' . self::NAME_MAX . ' characters');
        }
        return $raw;
    }

    public function bio(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }
        if (!is_string($raw)) {
            throw $this->error('bio', 'invalid', 'bio must be a string');
        }
        $this->rejectNullByte($raw, 'bio');
        if (mb_strlen($raw, 'UTF-8') > self::BIO_MAX) {
            throw $this->error('bio', 'too_long', 'Max ' . self::BIO_MAX . ' characters');
        }
        return $raw;
    }

    /**
     * @return list<string>
     */
    public function tags(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw $this->error('tags', 'invalid', 'tags must be an array');
        }
        if (count($raw) > self::TAGS_MAX) {
            // Cap checked before per-element processing (DoS guard).
            throw $this->error('tags', 'too_many', 'Maximum ' . self::TAGS_MAX . ' tags');
        }
        $tags = [];
        foreach ($raw as $i => $tag) {
            if (!is_string($tag)) {
                throw $this->error("tags[{$i}]", 'invalid', 'Each tag must be a string');
            }
            $this->rejectNullByte($tag, "tags[{$i}]");
            if (mb_strlen($tag, 'UTF-8') === 0) {
                throw $this->error("tags[{$i}]", 'required', 'tag must not be empty');
            }
            if (mb_strlen($tag, 'UTF-8') > self::TAG_MAX) {
                throw $this->error("tags[{$i}]", 'too_long', 'Max ' . self::TAG_MAX . ' characters per tag');
            }
            $tags[] = $tag;
        }
        return $tags;
    }

    private function rejectNullByte(string $value, string $field): void
    {
        // Null bytes can truncate C-string processing / bypass downstream checks.
        if (str_contains($value, "\x00")) {
            throw $this->error($field, 'invalid', 'Null bytes are not allowed');
        }
    }

    private function error(string $field, string $code, string $message): ValidationException
    {
        return new ValidationException([new ValidationError($field, $message, $code)]);
    }
}
