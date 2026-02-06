<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console;

use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use Pulsar\Api\Internal;

/**
 * Shared helper for consistent JSON output in Studio CLI commands.
 */
#[Internal]
final class JsonOutputHelper
{
    private function __construct() {}

    /**
     * Encode data as a JSON envelope for CLI output.
     *
     * @param array<string, mixed> $data
     */
    public static function encode(string $command, bool $success, array $data): string
    {
        $envelope = [
            'command' => $command,
            'success' => $success,
            'data' => $data,
        ];

        return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
