<?php

declare(strict_types=1);

namespace Pulsar\Mail;

use Pulsar\Api\Api;

/**
 * An email address with an optional display name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Address
{
    public function __construct(
        public string $email,
        public string $name = '',
    ) {}
}
