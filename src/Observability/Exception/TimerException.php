<?php

declare(strict_types=1);

namespace Pulsar\Observability\Exception;

use LogicException;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when `Timer` is used outside its documented contract.
 *
 * These are programming errors — callers must not catch them and
 * continue; fix the call site instead.
 * @api
 */
#[Api(since: '1.0.0')]
final class TimerException extends LogicException
{
    public static function alreadyStopped(string $label): self
    {
        return new self(sprintf(
            'Cannot record a mark on timer "%s": the timer has already been stopped.',
            $label,
        ));
    }

    public static function emptyMarkName(string $label): self
    {
        return new self(sprintf(
            'Mark name must be non-empty on timer "%s".',
            $label,
        ));
    }

    public static function duplicateMark(string $label, string $name): self
    {
        return new self(sprintf(
            'Duplicate mark "%s" on timer "%s": mark names must be unique per timer.',
            $name,
            $label,
        ));
    }
}
