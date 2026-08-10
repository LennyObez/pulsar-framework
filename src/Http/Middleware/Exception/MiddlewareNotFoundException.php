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
 * The registry fails fast rather than passing an unknown name through
 * as a faux `class-string`: a typo would otherwise surface much later,
 * as an instantiation crash deep inside the pipeline with no mention of
 * the offending reference.
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
