<?php

declare(strict_types=1);

namespace Sluglog\Article;

/**
 * Converts a title (or arbitrary string) into a URL-safe slug.
 *
 * Rules:
 *  - Lowercase ASCII
 *  - Replace non-alphanumeric characters with hyphens
 *  - Collapse consecutive hyphens
 *  - Trim leading/trailing hyphens
 *  - If empty after normalisation, falls back to 'untitled'
 */
final class SlugHelper
{
    public static function fromTitle(string $title): string
    {
        // Lowercase
        $slug = mb_strtolower($title);

        // Replace non-alphanumeric (ASCII) characters with hyphens
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Trim
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'untitled';
    }

    /**
     * Makes a slug unique by appending -2, -3, … until no collision is found.
     *
     * @param callable(string): bool $exists  Returns true if the slug is already taken.
     */
    public static function makeUnique(string $base, callable $exists): string
    {
        if (!$exists($base)) {
            return $base;
        }

        $counter = 2;
        while ($exists("{$base}-{$counter}")) {
            $counter++;
        }

        return "{$base}-{$counter}";
    }
}
