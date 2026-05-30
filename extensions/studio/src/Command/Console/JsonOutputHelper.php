<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console;

use JsonException;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Console\OutputInterface;

use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Shared helper for consistent JSON output in Studio CLI commands.
 */
#[Internal]
final class JsonOutputHelper
{
    /** Standard JSON encoding flags for CLI output. */
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    /**
     * Encode data as a JSON envelope for CLI output.
     *
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
    #[NoDiscard]
    public static function encode(string $command, bool $success, array $data): string
    {
        $envelope = [
            'command' => $command,
            'success' => $success,
            'data' => $data,
        ];

        return json_encode($envelope, self::JSON_FLAGS);
    }

    /**
     * Encode an arbitrary payload as pretty-printed JSON.
     *
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
    #[NoDiscard]
    public static function formatJson(array $data): string
    {
        return json_encode($data, self::JSON_FLAGS);
    }

    /**
     * Write an error to output, formatting as JSON or plain text.
     *
     * When $isJson is true, the error is written as a JSON object with
     * "error" and "message" keys. Otherwise, the message is written
     * as a plain-text error line.
     *
     * @throws JsonException
     */
    public static function writeError(
        OutputInterface $output,
        bool $isJson,
        string $errorCode,
        string $message,
    ): void {
        if ($isJson) {
            $output->writeln(self::formatJson([
                'error' => $errorCode,
                'message' => $message,
            ]));
        } else {
            $output->errorln($message);
        }
    }

    /**
     * Write a labeled key-value field to console output.
     *
     * Formats as "  label:      value" with consistent label-column width.
     */
    public static function writeField(OutputInterface $output, string $label, string $value, int $labelWidth = 12): void
    {
        $output->writeln(sprintf('  %-' . $labelWidth . 's %s', $label . ':', $value));
    }
}
