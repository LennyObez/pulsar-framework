<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Protocol;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Exception\McpException;

use function fgets;
use function fwrite;
use function strlen;

use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * STDIO transport for MCP JSON-RPC communication.
 *
 * Reads line-delimited JSON from STDIN, writes responses to STDOUT,
 * and emits diagnostic messages to STDERR.
 */
#[Internal]
final readonly class StdioTransport
{
    /**
     * Maximum allowed message size in bytes (2 MB).
     */
    public const int MAX_MESSAGE_SIZE = 2_097_152;

    /**
     * Read a single line from STDIN.
     *
     * @return string|null The line content (without trailing newline), or null on EOF
     *
     * @throws McpException When a line exceeds MAX_MESSAGE_SIZE
     */
    public function readLine(): ?string
    {
        $line = fgets(STDIN);

        if ($line === false) {
            return null;
        }

        $line = rtrim($line, "\r\n");

        if (strlen($line) > self::MAX_MESSAGE_SIZE) {
            throw McpException::protocolError(
                'Message exceeds maximum size of ' . self::MAX_MESSAGE_SIZE . ' bytes',
                -32700,
            );
        }

        return $line;
    }

    /**
     * Write a line to STDOUT.
     *
     * The caller is responsible for ensuring the data does not exceed MAX_MESSAGE_SIZE.
     * This method enforces the limit as a safety net.
     *
     * @throws McpException When the data exceeds MAX_MESSAGE_SIZE
     */
    public function writeLine(string $data): void
    {
        if (strlen($data) > self::MAX_MESSAGE_SIZE) {
            throw McpException::protocolError(
                'Response exceeds maximum size of ' . self::MAX_MESSAGE_SIZE . ' bytes',
                -32603,
            );
        }

        fwrite(STDOUT, $data);
    }

    /**
     * Write a diagnostic message to STDERR.
     */
    public function writeError(string $message): void
    {
        fwrite(STDERR, $message . "\n");
    }
}
