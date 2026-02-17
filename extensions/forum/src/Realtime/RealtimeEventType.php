<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Realtime;

use Pulsar\Api\Api;

/**
 * Types of real-time events broadcast to forum clients.
 */
#[Api(since: '1.0.0')]
enum RealtimeEventType: string
{
    case NewPost = 'new_post';
    case PostEdited = 'post_edited';
    case PostDeleted = 'post_deleted';
    case TypingStarted = 'typing_started';
    case TypingStopped = 'typing_stopped';
    case ThreadLocked = 'thread_locked';
    case ThreadUnlocked = 'thread_unlocked';
    case UserJoined = 'user_joined';
    case UserLeft = 'user_left';
    case ReactionAdded = 'reaction_added';
    case ReactionRemoved = 'reaction_removed';
    case PollVoted = 'poll_voted';
}
