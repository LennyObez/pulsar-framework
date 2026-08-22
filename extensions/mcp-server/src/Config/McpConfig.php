<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;
use Pulsar\Support\Coerce;

use function is_array;

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

        $clientIdEnv = $environment->get('MCP_CLIENT_ID');
        $clientId = $clientIdEnv ?? Coerce::string($data['client_id'] ?? null, 'default');

        $tools = $data['tools'] ?? null;
        $security = $data['security'] ?? null;

        return new self(
            enabled: $enabled,
            clientId: $clientId,
            projectRoot: Coerce::string($data['project_root'] ?? null),
            tools: McpToolsConfig::fromArray(is_array($tools) ? $tools : []),
            security: McpSecurityConfig::fromArray(is_array($security) ? $security : []),
        );
    }
}
