<?php

declare(strict_types=1);

namespace Pulsar\Support;

use Pulsar\Api\Api;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

use function array_map;
use function implode;

/**
 * Renders a reflected type the way PHP itself prints it, without the deprecation.
 *
 * `(string) $reflectionType` is deprecated: casting a ReflectionType raises a
 * deprecation notice, and PHP 8.5 means it. Four places in this repository did it —
 * the REPL's signature formatter, the API-snapshot builder, and two test helpers —
 * and none of them noticed, because the analyser that reports it only started doing
 * so in phpstan 2.2.5. The cast still works, which is exactly why it survived.
 *
 * The output is byte-identical to the cast, which matters more than it sounds: the
 * API snapshot records every public signature as one of these strings, so a
 * formatting difference here would show up as a false BC break across 1731 classes.
 * The reference behaviour is reproduced deliberately, including its two surprises —
 * PHP prints union members in reflection order rather than source order
 * (`int|string` renders as `string|int`), and parenthesises an intersection that
 * appears inside a union.
 *
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class ReflectionTypeName
{
    /**
     * The type as PHP would print it, or an empty string when there is none.
     */
    public static function of(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            // The leading `?` is printed only where it adds information: `null` and
            // `mixed` already admit null, and PHP does not decorate them.
            return $type->allowsNull() && $name !== 'null' && $name !== 'mixed'
                ? '?' . $name
                : $name;
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = [];

            foreach ($type->getTypes() as $member) {
                $rendered = self::of($member);
                $parts[] = $member instanceof ReflectionIntersectionType ? '(' . $rendered . ')' : $rendered;
            }

            return implode('|', $parts);
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(self::of(...), $type->getTypes()));
        }

        // No other ReflectionType subclass exists today; returning the empty string
        // keeps a future one from crashing a snapshot generator mid-run.
        return '';
    }
}
