<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Parsed device information from user agent string.
 */
#[Api(since: '1.0.0')]
final readonly class DeviceInfo
{
    public function __construct(
        public string $browser,
        public string $browserVersion,
        public string $os,
        public string $osVersion,
        public DeviceType $deviceType,
    ) {}

    public static function unknown(): self
    {
        return new self(
            browser: 'Unknown',
            browserVersion: '',
            os: 'Unknown',
            osVersion: '',
            deviceType: DeviceType::Unknown,
        );
    }
}
