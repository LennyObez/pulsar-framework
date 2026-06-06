<?php

declare(strict_types=1);

namespace Pulsar\Event\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks an event listener for asynchronous (queued) execution.
 *
 * When a listener method or class is annotated with #[ShouldQueue],
 * the event dispatcher pushes it to the queue instead of invoking
 * it synchronously. The optional queue and connection parameters
 * control which queue the job is dispatched to.
 *
 * Usage:
 *   #[ShouldQueue(queue: 'notifications')]
 *   public function onUserRegistered(UserRegistered $event): void
 *   {
 *       // Runs asynchronously via queue worker
 *   }
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class ShouldQueue
{
    public function __construct(
        public string $queue = 'default',
        public string $connection = 'default',
        public int $delay = 0,
        public int $maxRetries = 3,
    ) {}
}
