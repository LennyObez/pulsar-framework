<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Pulsar\Api\Api;

/**
 * User-defined compliance control with a callable verifier.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CustomControl
{
    /**
     * @param callable(): CheckResult $verifier
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public mixed $verifier,
        public string $category = 'custom',
    ) {}
}
