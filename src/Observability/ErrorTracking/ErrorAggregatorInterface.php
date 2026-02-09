<?php

declare(strict_types=1);

namespace Pulsar\Observability\ErrorTracking;

use Pulsar\Api\Api;

#[Api]
interface ErrorAggregatorInterface
{
    public function capture(ErrorEvent $event): void;

    /**
     * @return list<ErrorGroup>
     */
    public function groups(): array;

    public function group(ErrorFingerprint $fingerprint): ?ErrorGroup;

    public function count(): int;

    public function clear(): void;
}
