<?php

declare(strict_types=1);

namespace Pulsar\Support;

use Pulsar\Api\Api;

use function is_array;

/**
 * Narrows an APCu result to the boolean the caller actually wants.
 *
 * apcu_store(), apcu_add(), apcu_delete() and apcu_exists() are all
 * argument-polymorphic: given one key they answer with a boolean, and given an
 * array of keys they answer with an array — of the keys that failed for the
 * writers, of the keys that exist for apcu_exists(). Code that passes a single key
 * is right to expect a boolean, but nothing in the signature says so, and returning
 * the raw result from a `: bool` method is a TypeError waiting for the day someone
 * passes a list.
 *
 * The framework's APCu driver and lock did exactly that. It went unnoticed because
 * the hand-written APCu stub this repository carried was never wired into
 * phpstan.neon, so every call was analysed against an unknown function.
 *
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class ApcuReply
{
    /**
     * Whether a single-key write succeeded.
     *
     * An array reply lists the keys that failed, so an empty one means everything
     * stored — which is also the correct answer for the single-key form.
     *
     * @param array<array-key, mixed>|bool $reply
     */
    public static function stored(array|bool $reply): bool
    {
        return is_array($reply) ? $reply === [] : $reply;
    }

    /**
     * Whether a single probed key is present.
     *
     * apcu_exists() answers with the subset of keys that exist, so a non-empty
     * array means the key was found.
     *
     * @param array<array-key, mixed>|bool $reply
     */
    public static function present(array|bool $reply): bool
    {
        return is_array($reply) ? $reply !== [] : $reply;
    }

    /**
     * Whether a single-key delete succeeded.
     *
     * apcu_delete() answers with the keys it could NOT delete, so empty is success.
     *
     * @param array<array-key, mixed>|bool $reply
     */
    public static function deleted(array|bool $reply): bool
    {
        return is_array($reply) ? $reply === [] : $reply;
    }
}
