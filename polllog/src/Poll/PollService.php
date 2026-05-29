<?php

declare(strict_types=1);

namespace PollLog\Poll;

use Nene2\Database\DatabaseTransactionManagerInterface;

/**
 * Creates a poll and all of its options atomically — a failure partway through
 * leaves no orphaned poll with missing options.
 */
final class PollService
{
    public function __construct(private readonly DatabaseTransactionManagerInterface $tx)
    {
    }

    /**
     * @param list<string> $options
     */
    public function create(string $question, bool $isPublic, array $options, string $now): int
    {
        $pollId = 0;
        $this->tx->transactional(function ($executor) use (&$pollId, $question, $isPublic, $options, $now): void {
            $repo = new PollRepository($executor);
            $pollId = $repo->createPoll($question, $isPublic, $now);
            foreach ($options as $i => $label) {
                $repo->addOption($pollId, $label, $i);
            }
        });

        return $pollId;
    }
}
