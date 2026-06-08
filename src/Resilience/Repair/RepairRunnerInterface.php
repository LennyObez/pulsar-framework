<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Repair;

use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
interface RepairRunnerInterface
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function register(RepairJobInterface $job): void;

    /**
     * @return list<RepairDiagnosis>
     */
    public function diagnoseAll(): array;

    /**
     * @return list<RepairResult>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function repairAll(): array;

    public function repair(string $name): RepairResult;

    /**
     * @return list<string>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function names(): array;
}
