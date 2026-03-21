<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Raised by `MiddlewareRegistry::resolve()` when a middleware reference
 * is neither a registered alias / group, nor an existing class.
 *
 * F7.11: previously the registry quietly returned the unknown name as
 * a faux `class-string` and let the pipeline crash much later when it
 * tried to instantiate `'typo'`. The registry now fails fast with a
 * precise diagnostic that names the offending reference.
 * @api
 */
#[Api(since: '1.0.0')]
final class MiddlewareNotFoundException extends RuntimeException
{
    #[NoDiscard]
    public static function unknownReference(string $name): self
    {
        return new self(sprintf(
            'Middleware reference "%s" could not be resolved: not a registered alias or group, and not an existing class.',
            $name,
        ));
    }
}
