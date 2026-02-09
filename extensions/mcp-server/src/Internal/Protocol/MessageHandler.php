<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Protocol;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\McpServer\Config\McpConfig;
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Contracts\ToolPermissionCheckerInterface;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Throwable;

use function array_map;
use function in_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Dispatches incoming JSON-RPC requests to MCP protocol handlers.
 *
 * Handles the full MCP method set: initialize, ping, tools/list, tools/call,
 * and notification methods. Integrates permission checks, rate limiting,
 * audit logging, and output redaction into the tool call pipeline.
 */
#[Internal]
final readonly class MessageHandler
{
    private const string SERVER_NAME = 'pulsar-mcp';
    private const string SERVER_VERSION = '1.0.0-rc.9';
    private const int METHOD_NOT_FOUND = -32601;
    private const int INVALID_PARAMS = -32602;
    private const int INTERNAL_ERROR = -32603;

    /** @var list<string> Known MCP protocol versions in descending preference order */
    private const array KNOWN_PROTOCOL_VERSIONS = [
        '2025-11-25',
        '2025-03-26',
        '2024-11-05',
    ];

    private JsonRpcCodec $codec;

    public function __construct(
        private McpToolRegistryInterface $registry,
        private ToolPermissionCheckerInterface $permissionChecker,
        private McpAccessGateInterface $accessGate,
        private McpRedactionPipelineInterface $redactionPipeline,
        private ?AuditLoggerInterface $auditLogger,
        private ?RateLimiterInterface $rateLimiter,
        private McpConfig $config,
        private SensitiveDataScrubber $scrubber,
    ) {
        $this->codec = new JsonRpcCodec();
    }

    /**
     * Handle a parsed JSON-RPC request and return the response string.
     *
     * Returns null for notifications (no response expected by the protocol).
     */
    public function handle(JsonRpcRequest $request): ?string
    {
        if ($request->isNotification()) {
            return $this->handleNotification($request);
        }

        /** @var string|int $id */
        $id = $request->id;

        return match ($request->method) {
            'initialize' => $this->handleInitialize($id, $request->params),
            'ping' => $this->codec->encodeResult($id, []),
            'tools/list' => $this->handleToolsList($id, $request->params),
            'tools/call' => $this->handleToolsCall($id, $request->params),
            default => $this->codec->encodeError(
                $id,
                self::METHOD_NOT_FOUND,
                sprintf('Method not found: %s', $request->method),
            ),
        };
    }

    private function handleNotification(JsonRpcRequest $request): ?string
    {
        match ($request->method) {
            'notifications/initialized' => null,
            'notifications/cancelled' => $this->handleCancelled($request->params),
            default => null,
        };

        return null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleCancelled(array $params): void
    {
        // Best-effort cancel tracking. The requestId identifies which in-flight
        // request the client wants cancelled. Since tool execution is synchronous
        // in the current implementation, this is a no-op acknowledgment.
        // Future async execution can check a cancellation registry keyed by requestId.
        $_ = $params['requestId'] ?? null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleInitialize(string|int $id, array $params): string
    {
        $clientProtocolVersion = $params['protocolVersion'] ?? null;

        if (!is_string($clientProtocolVersion) || $clientProtocolVersion === '') {
            return $this->codec->encodeError(
                $id,
                self::INVALID_PARAMS,
                'Missing or invalid protocolVersion parameter',
            );
        }

        // Validate the version looks like a date (YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $clientProtocolVersion) !== 1) {
            return $this->codec->encodeError(
                $id,
                self::INVALID_PARAMS,
                'protocolVersion must be a valid date string (YYYY-MM-DD)',
            );
        }

        // Negotiate protocol version
        $negotiatedVersion = $this->negotiateProtocolVersion($clientProtocolVersion);

        return $this->codec->encodeResult($id, [
            'protocolVersion' => $negotiatedVersion,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ]);
    }

    private function negotiateProtocolVersion(string $clientVersion): string
    {
        // If the client requests a known version, use it
        if (in_array($clientVersion, self::KNOWN_PROTOCOL_VERSIONS, true)) {
            return $clientVersion;
        }

        // Unknown but valid date format: negotiate to the latest known version
        return self::KNOWN_PROTOCOL_VERSIONS[0];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleToolsList(string|int $id, array $params): string
    {
        $tools = $this->registry->list();

        $toolDefinitions = array_map(
            static fn($tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,
                'inputSchema' => $tool->inputSchema,
                'outputSchema' => $tool->outputSchema,
            ],
            $tools,
        );

        return $this->codec->encodeResult($id, [
            'tools' => $toolDefinitions,
            'nextCursor' => $params['cursor'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleToolsCall(string|int $id, array $params): string
    {
        $toolName = $params['name'] ?? null;

        if (!is_string($toolName) || $toolName === '') {
            return $this->codec->encodeError($id, self::INVALID_PARAMS, 'Missing tool name');
        }

        /** @var array<string, mixed> $arguments */
        $arguments = (array) ($params['arguments'] ?? []);

        // Permission check
        try {
            $this->permissionChecker->assertAllowed($toolName);
        } catch (McpSecurityException $e) {
            $this->auditToolCall($toolName, AuditOutcome::Denied, $e->getMessage());

            return $this->encodeToolResult($id, ToolResult::error($e->getMessage()));
        }

        // Rate limiting
        if ($this->rateLimiter !== null) {
            $rateLimitKey = sprintf('mcp:tool:%s', $toolName);
            $rateLimitResult = $this->rateLimiter->hit($rateLimitKey);

            if ($rateLimitResult->exceeded()) {
                $this->auditToolCall($toolName, AuditOutcome::Denied, 'Rate limited');

                return $this->encodeToolResult(
                    $id,
                    ToolResult::error(
                        sprintf('Rate limited, retry after %d seconds', $rateLimitResult->retryAfter),
                    ),
                );
            }
        }

        // Execute
        try {
            $result = $this->registry->call($toolName, $arguments);
        } catch (McpException $e) {
            $this->auditToolCall($toolName, AuditOutcome::Error, $e->getMessage());

            return $this->encodeToolResult($id, ToolResult::error($e->getMessage()));
        } catch (Throwable $e) {
            $this->auditToolCall($toolName, AuditOutcome::Error, $e->getMessage());

            return $this->encodeToolResult($id, ToolResult::error('Internal tool execution error'));
        }

        // Redact sensitive data
        $result = $this->redactionPipeline->redact($result);

        // Scrub any remaining sensitive fields
        $scrubbed = $this->scrubber->scrub($result->structuredContent);
        if ($scrubbed !== $result->structuredContent) {
            $result = new ToolResult(
                structuredContent: $scrubbed,
                textContent: $result->textContent,
                isError: $result->isError,
                meta: $result->meta,
            );
        }

        // Audit success
        $this->auditToolCall(
            $toolName,
            $result->isError ? AuditOutcome::Failure : AuditOutcome::Success,
            $result->isError ? $result->textContent : 'OK',
        );

        return $this->encodeToolResult($id, $result);
    }

    private function encodeToolResult(string|int $id, ToolResult $result): string
    {
        $response = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $result->textContent,
                ],
            ],
            'isError' => $result->isError,
        ];

        if ($result->structuredContent !== []) {
            $response['structuredContent'] = $result->structuredContent;
        }

        if ($result->meta !== []) {
            $response['_meta'] = $result->meta;
        }

        return $this->codec->encodeResult($id, $response);
    }

    private function auditToolCall(string $toolName, AuditOutcome $outcome, string $detail): void
    {
        $this->auditLogger?->log(
            event: AuditEvent::DataAccess,
            outcome: $outcome,
            actor: sprintf('mcp-client:%s', $this->config->clientId),
            action: sprintf('mcp:tools/call:%s', $toolName),
            resource: $toolName,
            metadata: ['detail' => $detail],
        );
    }
}
