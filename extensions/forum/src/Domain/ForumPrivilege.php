<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Domain;

use Pulsar\Api\Api;

/**
 * Forum privileges gated by reputation level.
 *
 * Each privilege requires the user to have reached a certain reputation
 * level (and score) before the action is unlocked.
 * @api
 */
#[Api(since: '1.0.0')]
enum ForumPrivilege: string
{
    case Upvote = 'upvote';
    case Downvote = 'downvote';
    case BypassModQueue = 'bypass_mod_queue';
    case EditWikiPosts = 'edit_wiki_posts';
    case CloseThreads = 'close_threads';
    case AccessModTools = 'access_mod_tools';

    /**
     * The minimum reputation level required for this privilege.
     */
    public function requiredLevel(): ReputationLevel
    {
        return match ($this) {
            self::Upvote => ReputationLevel::Contributor,
            self::Downvote => ReputationLevel::Regular,
            self::BypassModQueue => ReputationLevel::Trusted,
            self::EditWikiPosts => ReputationLevel::Veteran,
            self::CloseThreads => ReputationLevel::Expert,
            self::AccessModTools => ReputationLevel::Champion,
        };
    }

    /**
     * The minimum reputation score required for this privilege.
     */
    public function requiredScore(): int
    {
        return $this->requiredLevel()->minimumScore();
    }

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Upvote => 'Upvote',
            self::Downvote => 'Downvote',
            self::BypassModQueue => 'Bypass Moderation Queue',
            self::EditWikiPosts => 'Edit Wiki Posts',
            self::CloseThreads => 'Close Threads',
            self::AccessModTools => 'Access Moderation Tools',
        };
    }
}
