<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Domain;

use Pulsar\Api\Api;

/**
 * Achievement badges that can be awarded to forum users.
 * @api
 */
#[Api(since: '1.0.0')]
enum Badge: string
{
    case FirstPost = 'first_post';
    case FirstAnswer = 'first_answer';
    case Helpful = 'helpful';
    case PopularThread = 'popular_thread';
    case Solver = 'solver';
    case BugHunter = 'bug_hunter';
    case Contributor = 'contributor';
    case Multilingual = 'multilingual';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::FirstPost => 'First Post',
            self::FirstAnswer => 'First Answer',
            self::Helpful => 'Helpful',
            self::PopularThread => 'Popular Thread',
            self::Solver => 'Solver',
            self::BugHunter => 'Bug Hunter',
            self::Contributor => 'Contributor',
            self::Multilingual => 'Multilingual',
        };
    }

    /**
     * Description of how to earn this badge.
     */
    public function description(): string
    {
        return match ($this) {
            self::FirstPost => 'Created your first forum post',
            self::FirstAnswer => 'Answered a question for the first time',
            self::Helpful => 'Received multiple upvotes on answers',
            self::PopularThread => 'Created a thread with many views',
            self::Solver => 'Had an answer marked as the solution',
            self::BugHunter => 'Reported a confirmed bug',
            self::Contributor => 'Reached Contributor reputation level',
            self::Multilingual => 'Posted in multiple languages',
        };
    }
}
