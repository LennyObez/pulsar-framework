<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Event;

use Pulsar\Api\Api;
use Throwable;

/**
 * Emitted when a cache operation encounters an error.
 */
#[Api(since: '1.0.0')]
final readonly class CacheErrorEvent extends CacheEvent
{
    public string $errorMessage;
    public ?string $errorClass;

    public function __construct(
        string $poolName,
        string $driverName,
        string $hashedKey,
        int $durationMicroseconds,
        string $errorMessage,
        ?Throwable $exception = null,
    ) {
        parent::__construct($poolName, $driverName, $hashedKey, $durationMicroseconds, 'error');
        $this->errorMessage = $errorMessage;
        $this->errorClass = $exception !== null ? $exception::class : null;
    }
}
