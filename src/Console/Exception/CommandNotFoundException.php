<?php

declare(strict_types=1);

namespace Pulsar\Console\Exception;

use Pulsar\Api\Api;

use function sprintf;

/**
 * Exception for when a command is not found.
 */
#[Api]
final class CommandNotFoundException extends ConsoleException
{
    /**
     * Create exception for unknown command.
     *
     * @param list<string> $alternatives Similar command names
     */
    public static function forCommand(string $name, array $alternatives = []): self
    {
        $message = sprintf('Command "%s" not found.', $name);

        if ($alternatives !== []) {
            $message .= sprintf(' Did you mean one of these? %s', implode(', ', $alternatives));
        }

        return new self($message);
    }

    /**
     * Create exception for ambiguous command.
     *
     * @param list<string> $matches Matching command names
     */
    public static function ambiguous(string $name, array $matches): self
    {
        return new self(sprintf(
            'Command "%s" is ambiguous. Matching commands: %s',
            $name,
            implode(', ', $matches),
        ));
    }
}
