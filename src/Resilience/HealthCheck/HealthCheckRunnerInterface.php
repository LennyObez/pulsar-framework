<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
interface HealthCheckRunnerInterface
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function register(HealthCheckInterface $check): void;

    public function runAll(): HealthReport;

    public function run(string $name): HealthCheckResult;

    /**
     * @return list<string>
     */
    public function names(): array;
}
