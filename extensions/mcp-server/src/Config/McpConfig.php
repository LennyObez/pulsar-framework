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
     * @param array<string, mixed> $data Raw array from config/mcp.php
     * @param Environment $environment Environment for variable overrides
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = (bool) ($data['enabled'] ?? false);

        $envEnabled = $environment->get('MCP_ENABLED');
        if ($envEnabled !== null) {
            $enabled = $envEnabled === '1' || $envEnabled === 'true';
        }

        $clientId = (string) ($data['client_id'] ?? 'default');

        $envClientId = $environment->get('MCP_CLIENT_ID');
        if ($envClientId !== null) {
            $clientId = $envClientId;
        }

        $projectRoot = (string) ($data['project_root'] ?? '');

        /** @var array<string, mixed> $toolsData */
        $toolsData = (array) ($data['tools'] ?? []);

        /** @var array<string, mixed> $securityData */
        $securityData = (array) ($data['security'] ?? []);

        return new self(
            enabled: $enabled,
            clientId: $clientId,
            projectRoot: $projectRoot,
            tools: McpToolsConfig::fromArray($toolsData),
            security: McpSecurityConfig::fromArray($securityData),
        );
    }
}
