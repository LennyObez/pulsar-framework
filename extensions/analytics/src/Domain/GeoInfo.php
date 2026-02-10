<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Geographic information for a visitor, resolved from IP address.
 */
#[Api(since: '1.0.0')]
final readonly class GeoInfo
{
    public function __construct(
        public string $countryCode,
        public string $region = '',
    ) {}

    public static function unknown(): self
    {
        return new self(countryCode: 'XX');
    }
}
