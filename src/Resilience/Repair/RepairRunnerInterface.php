<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Repair;

use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
interface RepairRunnerInterface
{
    public function register(RepairJobInterface $job): void;

    /**
     * @return list<RepairDiagnosis>
     */
    public function diagnoseAll(): array;

    /**
     * @return list<RepairResult>
     */
    public function repairAll(): array;

    public function repair(string $name): RepairResult;

    /**
     * @return list<string>
     */
    public function names(): array;
}
