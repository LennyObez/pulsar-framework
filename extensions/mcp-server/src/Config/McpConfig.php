<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;

/**
 * Top-level MCP server configuration DTO.
 *
 * Environment variables MCP_ENABLED and MCP_CLIENT_ID override
 * their corresponding config file values.
 */
#[Internal]
final readonly class McpConfig
{
    public function __construct(
        public bool $enabled,
        public string $clientId,
        public string $projectRoot,
        public McpToolsConfig $tools,
        public McpSecurityConfig $security,
    ) {}

    /**
     * Build from the raw MCP config array with environment overrides.
     *
     * @param array{
     *     enabled?: bool|int|string,
     *     client_id?: string,
     *     project_root?: string,
     *     tools?: array<string, mixed>,
     *     security?: array<string, mixed>,
     * } $data Raw array from config/mcp.php
     * @param Environment $environment Environment for variable overrides
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $envEnabled = $environment->get('MCP_ENABLED');
        $enabled = $envEnabled !== null
            ? ($envEnabled === '1' || $envEnabled === 'true')
            : (bool) ($data['enabled'] ?? false);

        $clientId = $environment->get('MCP_CLIENT_ID') ?? $data['client_id'] ?? 'default';

        return new self(
            enabled: $enabled,
            clientId: $clientId,
            projectRoot: $data['project_root'] ?? '',
            tools: McpToolsConfig::fromArray($data['tools'] ?? []),
            security: McpSecurityConfig::fromArray($data['security'] ?? []),
        );
    }
}
