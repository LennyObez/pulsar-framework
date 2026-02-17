<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Reaction;

use Pulsar\Api\Api;

/**
 * Standard emoji reactions available on forum posts.
 */
#[Api(since: '1.0.0')]
enum ReactionType: string
{
    case ThumbsUp = 'thumbs_up';
    case ThumbsDown = 'thumbs_down';
    case Heart = 'heart';
    case Laugh = 'laugh';
    case Surprised = 'surprised';
    case Thinking = 'thinking';
    case Celebrate = 'celebrate';
    case Rocket = 'rocket';
    case Eyes = 'eyes';
    case Fire = 'fire';

    /**
     * Return the Unicode emoji for display.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::ThumbsUp => "\u{1F44D}",
            self::ThumbsDown => "\u{1F44E}",
            self::Heart => "\u{2764}\u{FE0F}",
            self::Laugh => "\u{1F604}",
            self::Surprised => "\u{1F62E}",
            self::Thinking => "\u{1F914}",
            self::Celebrate => "\u{1F389}",
            self::Rocket => "\u{1F680}",
            self::Eyes => "\u{1F440}",
            self::Fire => "\u{1F525}",
        };
    }
}
