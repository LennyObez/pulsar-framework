<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console;

use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Console\OutputInterface;

/**
 * Shared helper for consistent JSON output in Studio CLI commands.
 */
#[Internal]
final class JsonOutputHelper
{
    /** Standard JSON encoding flags for CLI output. */
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    private function __construct() {}

    /**
     * Encode data as a JSON envelope for CLI output.
     *
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
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
}
