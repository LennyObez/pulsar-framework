<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use Pulsar\Api\Api;

#[Api]
interface HealthCheckRunnerInterface
{
    public function register(HealthCheckInterface $check): void;

    public function runAll(): HealthReport;

    public function run(string $name): HealthCheckResult;

    /**
     * @return list<string>
     */
    public function names(): array;
}
