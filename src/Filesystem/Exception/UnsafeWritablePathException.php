<?php

declare(strict_types=1);

namespace Pulsar\Filesystem\Exception;

use RuntimeException;

use function sprintf;

/**
 * Thrown at boot when a framework-state path (cache, logs, sessions, ...)
 * resolves to a location inside the public document root, where a web request
 * could read it.
 * @api
 */
final class UnsafeWritablePathException extends RuntimeException
{
    public static function insideDocumentRoot(
        string $configKey,
        string $configured,
        string $resolved,
        string $documentRoot,
    ): self {
        return new self(sprintf(
            'Refusing to use "%s" for framework state: the configured value "%s" resolves to "%s", '
            . 'which is inside the public document root "%s" — a web request could read it. '
            . 'Set PULSAR_BASE_PATH to the project root (so paths resolve outside public/), '
            . 'or point "%s" at a location outside the document root.',
            $configKey,
            $configured,
            $resolved,
            $documentRoot,
            $configKey,
        ));
    }
}
