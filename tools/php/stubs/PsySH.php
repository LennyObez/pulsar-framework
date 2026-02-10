<?php

/**
 * Minimal PsySH stub for Psalm static analysis.
 *
 * psy/psysh is optional (listed in composer.json suggest).
 * This stub provides just enough type information for Psalm
 * to analyze ShellCommand.
 */

namespace Psy;

final class Configuration
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = []) {}
}

final class Shell
{
    public function __construct(?Configuration $config = null) {}

    /**
     * @param array<string, mixed> $variables
     */
    public function setScopeVariables(array $variables): void {}

    public function addInput(string $input): void {}

    public function run(): int {}
}
