<?php

declare(strict_types=1);

namespace Pulsar\Saga\Port;

use Pulsar\Api\Api;

/**
 * Port for publishing integration events to other services.
 *
 * In regulated presets, saga step handlers MUST NOT inject this port
 * directly — they must use {@see OutboxPort} instead to ensure atomic
 * event emission. This is enforced by a PHPStan rule and an optional
 * runtime container guard.
 */
#[Api(since: '1.0.0')]
interface IntegrationEventBusPort
{
    /**
     * Publish an integration event.
     *
     * @param string               $eventType Event type identifier
     * @param array<string, mixed> $payload   Event payload
     */
    public function publish(string $eventType, array $payload): void;
}
