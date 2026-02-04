<?php

declare(strict_types=1);

namespace Pulsar\Console;

/**
 * Contract for console commands.
 */
interface CommandInterface
{
    /**
     * The command name.
     */
    public string $name { get; }

    /**
     * The command description.
     */
    public string $description { get; }

    /**
     * Execute the command.
     *
     * @return int Exit code (0 for success)
     */
    public function execute(InputInterface $input, OutputInterface $output): int;
}
