<?php

declare(strict_types=1);

namespace Pulsar\Support;

use Pulsar\Api\Api;
use Redis;
use RuntimeException;

use function get_debug_type;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Narrows a phpredis command result to the value it carries.
 *
 * Every phpredis command is mode-polymorphic: in the default mode it returns its
 * value, and inside MULTI or a pipeline it returns the client itself so calls can
 * be chained, with the values arriving later from exec(). The extension therefore
 * types each command as `Redis|<value>|false`, and callers that assume the value
 * form are only correct because they never queue.
 *
 * Pulsar's drivers had that assumption written nowhere and checked by nothing: the
 * hand-written Redis stub this repository used to carry declared `get(): string|false`
 * and `sAdd(): int`, dropping both the `Redis` arm and, in places, `false`. Analysis
 * passed on signatures that did not describe the extension, so 28 call sites across
 * the cache driver, the queue driver and the forum broadcaster were never checked at
 * all. Replacing that stub with the maintained upstream one surfaced them.
 *
 * These helpers make the assumption explicit and enforce it. Receiving the client
 * back means the connection was left in MULTI/pipeline mode by an earlier call — a
 * programming error that would otherwise corrupt the next unrelated read, so it
 * throws rather than guessing. A genuine `false` from the server is a normal outcome
 * and is passed through, because callers already distinguish it from a value.
 *
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class RedisReply
{
    /**
     * Accepts `mixed` because not every command is typed as tightly as GET:
     * hGet() is declared `mixed` upstream, so the union cannot be spelled out and
     * the reply has to be validated rather than trusted.
     *
     * @return string|false `false` when the key or field does not exist
     */
    public static function stringOrFalse(mixed $reply): string|false
    {
        if ($reply === false || is_string($reply)) {
            return $reply;
        }

        if ($reply instanceof Redis) {
            throw self::queued('a string');
        }

        // Redis values are byte strings on the wire, so anything else means the
        // client is not speaking the protocol we think it is. Returning "absent"
        // here would turn that into a cache miss or a lost job, silently.
        throw new RuntimeException('Expected a string from Redis but got ' . get_debug_type($reply) . '.');
    }

    /**
     * @param Redis|int|false $reply
     * @return int|false `false` when the command failed
     */
    public static function intOrFalse(Redis|int|false $reply): int|false
    {
        if ($reply === false || is_int($reply)) {
            return $reply;
        }

        throw self::queued('an integer');
    }

    /**
     * A count where a failure is indistinguishable from zero for the caller.
     *
     * The boolean arm is not a failure flag: EXISTS answers `true` for a single
     * key that is present and `false` for one that is not, so those map to 1 and 0
     * rather than to an error. `(bool)` casting the raw reply happened to give the
     * right answer for one key and the wrong one for none — both `false` and `0`
     * are falsy — but it silently lost the count when several keys were probed.
     *
     * @param Redis|int|bool $reply
     */
    public static function count(Redis|int|bool $reply): int
    {
        if (is_bool($reply)) {
            return $reply ? 1 : 0;
        }

        if (is_int($reply)) {
            return $reply;
        }

        throw self::queued('a count');
    }

    /**
     * Whether a write succeeded.
     *
     * A string arm is accepted because SET with the GET option answers with the
     * previous value instead of true, and a returned value still means it stored.
     *
     * @param Redis|string|bool $reply
     */
    public static function success(Redis|string|bool $reply): bool
    {
        if (is_bool($reply)) {
            return $reply;
        }

        if (is_string($reply)) {
            return true;
        }

        throw self::queued('a boolean');
    }

    /**
     * A list reply, with a failure flattened to the empty list.
     *
     * Callers iterate these, and `foreach` over `false` is a TypeError rather than
     * an empty loop, so the failure is absorbed here instead of at every call site.
     *
     * @param Redis|array<array-key, mixed>|false $reply
     * @return array<array-key, mixed>
     */
    public static function items(Redis|array|false $reply): array
    {
        if ($reply === false) {
            return [];
        }

        if (is_array($reply)) {
            return $reply;
        }

        throw self::queued('a list');
    }

    /**
     * Every string entry of a list reply, which is what key and member scans yield.
     *
     * @param Redis|array<array-key, mixed>|false $reply
     * @return list<string>
     */
    public static function strings(Redis|array|false $reply): array
    {
        $strings = [];

        /** @var mixed $value */
        foreach (self::items($reply) as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    private static function queued(string $expected): RuntimeException
    {
        return new RuntimeException(
            'Expected ' . $expected . ' from Redis but got the client back: the connection is in '
            . 'MULTI/pipeline mode, so this reply is queued rather than resolved. Read queued values '
            . 'from exec() instead.',
        );
    }
}
