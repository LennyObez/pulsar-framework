<?php

declare(strict_types=1);

namespace Pulsar\Deploy;

use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
interface DeployCheckRunnerInterface
{
    public function register(DeployCheckInterface $check): void;

    public function run(string $environment): DeployReport;

    /**
     * @return list<DeployCheckInterface>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function checks(): array;
}
