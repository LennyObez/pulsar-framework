<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Closure;
use Pulsar\Api\Api;

/**
 * User-defined compliance control with a callable verifier.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CustomControl
{
    /**
     * @param Closure(): CheckResult $verifier Produces the control's CheckResult on demand
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public Closure $verifier,
        public string $category = 'custom',
    ) {}
}
