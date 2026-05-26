<?php

declare(strict_types=1);

namespace Opt\Article;

/**
 * Thrown when an optimistic lock conflict is detected.
 *
 * This means another writer updated the record between the time the caller
 * read it and the time they tried to save it. The caller should re-fetch
 * the latest version and retry or present a merge UI to the user.
 */
final class ConflictException extends \RuntimeException
{
    public function __construct(int $id, int $expectedVersion)
    {
        parent::__construct(
            sprintf(
                'Article %d has been modified by another writer. Expected version %d.',
                $id,
                $expectedVersion,
            ),
        );
    }
}
