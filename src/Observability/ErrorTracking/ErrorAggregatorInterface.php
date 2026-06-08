<?php

declare(strict_types=1);

namespace Pulsar\Observability\ErrorTracking;

use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
interface ErrorAggregatorInterface
{
    public function capture(ErrorEvent $event): void;

    /**
     * Register an observer to be notified on every captured error event.
     *
     * @param callable(ErrorEvent): void $observer
     */
    public function addObserver(callable $observer): void;

    /**
     * @return list<ErrorGroup>
     */
    public function groups(): array;

    public function group(ErrorFingerprint $fingerprint): ?ErrorGroup;

    public function count(): int;

    public function clear(): void;
}
