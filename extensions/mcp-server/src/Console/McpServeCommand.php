<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Console;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcCodec;
use Pulsar\Extension\McpServer\Internal\Protocol\MessageHandler;
use Pulsar\Extension\McpServer\Internal\Protocol\StdioTransport;
use Throwable;

/**
 * Starts the MCP server on stdin/stdout using the JSON-RPC 2.0 protocol.
 */
final class McpServeCommand extends Command
{
    public function __construct(
        private readonly MessageHandler $messageHandler,
        private readonly StdioTransport $transport,
        private readonly McpAccessGateInterface $accessGate,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'mcp:serve';
        $this->description = 'Start the MCP server (JSON-RPC 2.0 over stdio)';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Environment gating check
        try {
            $this->accessGate->assertEnvironmentAllowed();
        } catch (Throwable $e) {
            $output->errorln('MCP server blocked: ' . $e->getMessage());

            return ExitCode::Error->value;
        }

        $codec = new JsonRpcCodec();

        $this->transport->writeError('Pulsar MCP server started. Listening on stdio...');

        while (true) {
            $line = $this->transport->readLine();

            if ($line === null) {
                // EOF: client disconnected
                break;
            }

            if ($line === '') {
                continue;
            }

            try {
                $request = $codec->decode($line);
                $response = $this->messageHandler->handle($request);

                if ($response !== null) {
                    $this->transport->writeLine($response);
                }
            } catch (McpException $e) {
                // Protocol-level errors get a JSON-RPC error response
                $errorResponse = $codec->encodeError(null, $e->getCode(), $e->getMessage());
                $this->transport->writeLine($errorResponse);
            } catch (Throwable $e) {
                // Unexpected errors; log to stderr, send generic error to client
                $this->transport->writeError('Internal error: ' . $e->getMessage());
                $errorResponse = $codec->encodeError(null, -32603, 'Internal error');
                $this->transport->writeLine($errorResponse);
            }
        }

        $this->transport->writeError('MCP server shutting down.');

        return ExitCode::Success->value;
    }
}
