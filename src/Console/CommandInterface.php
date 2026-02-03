<?php

declare(strict_types=1);

namespace Pulsar\Console;

/**
 * Contract for console commands.
 */
interface CommandInterface
{
    /**
     * Get the command name.
     */
    public function getName(): string;

    /**
     * Get the command description.
     */
    public function getDescription(): string;

    /**
     * Execute the command.
     *
     * @return int Exit code (0 for success)
     */
    public function execute(InputInterface $input, OutputInterface $output): int;
}
