<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Contrast;

use RuntimeException;

use function sprintf;

/**
 * Thrown when a color value cannot be parsed.
 */
final class InvalidColorException extends RuntimeException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Cannot parse color value: "%s"',
            $value,
        ));
    }
}
