<?php

declare(strict_types=1);

namespace Pulsar\Deploy;

use Pulsar\Api\Api;

#[Api]
interface DeployCheckRunnerInterface
{
    public function register(DeployCheckInterface $check): void;

    public function run(string $environment): DeployReport;

    /**
     * @return list<DeployCheckInterface>
     */
    public function checks(): array;
}
