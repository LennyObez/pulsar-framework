<?php

declare(strict_types=1);

namespace Pulsar\Database\Seeder;

use Pulsar\Api\Api;

/**
 * Contract for running database seeders.
 */
#[Api(since: '1.0.0')]
interface SeederRunnerInterface
{
    /**
     * Run all discovered seeders.
     *
     * @return list<string> Identifiers of executed seeders
     */
    public function runAll(): array;

    /**
     * Run a specific seeder by class name.
     *
     * @param string $name The seeder class name
     */
    public function runByName(string $name): void;
}
