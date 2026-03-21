<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Domain exception for device registration and authentication failures.
 *
 * Static factories expose the exact failure mode so callers can pattern-match
 * on semantic intent without string-parsing exception messages.
 * @api
 */
#[Api(since: '1.0.0')]
final class DeviceException extends RuntimeException
{
    public static function deviceLimitReached(string $userId, int $currentCount, int $maxAllowed): self
    {
        return new self(sprintf(
            'Device limit reached: user %s already has %d registered devices (max %d)',
            $userId,
            $currentCount,
            $maxAllowed,
        ));
    }

    public static function deviceNotFoundOrForbidden(string $deviceId, string $userId): self
    {
        return new self(sprintf(
            'Device %s not found or does not belong to user %s',
            $deviceId,
            $userId,
        ));
    }
}
