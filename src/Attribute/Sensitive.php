<?php

declare(strict_types=1);

namespace Pulsar\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a property as containing sensitive data.
 *
 * Properties annotated with this attribute will have their values redacted
 * in REPL output, debug dumps, and error reports. Apply this to DTO properties
 * that hold passwords, tokens, API keys, or other secrets.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class Sensitive
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $reason = '',
    ) {}
}
