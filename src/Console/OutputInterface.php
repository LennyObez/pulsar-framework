<?php

declare(strict_types=1);

namespace Pulsar\Console;

use Pulsar\Api\Api;

/**
 * Contract for console output.
 * @api
 */
#[Api(since: '1.0.0')]
interface OutputInterface
{
    /**
     * Write a message to the output.
     */
    public function write(string $message): void;

    /**
     * Write a message followed by a newline.
     */
    public function writeln(string $message = ''): void;

    /**
     * Write an error message.
     */
    public function error(string $message): void;

    /**
     * Write an error message followed by a newline.
     */
    public function errorln(string $message = ''): void;

    /**
     * Write a success message.
     */
    public function success(string $message): void;

    /**
     * Write an info message.
     */
    public function info(string $message): void;

    /**
     * Write a warning message.
     */
    public function warning(string $message): void;

    /**
     * The current verbosity level.
     */
    public Verbosity $verbosity { get; set; }

    /**
     * Check if output is quiet.
     */
    public function isQuiet(): bool;

    /**
     * Check if output is verbose.
     */
    public function isVerbose(): bool;

    /**
     * Check if output is in debug mode.
     */
    public function isDebug(): bool;

    /**
     * Write a newline.
     */
    public function newLine(int $count = 1): void;
}
