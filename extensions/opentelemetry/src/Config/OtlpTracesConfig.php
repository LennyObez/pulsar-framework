<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_string;

/**
 * Traces-specific OTLP configuration.
 */
#[Api(since: '1.0.0')]
final readonly class OtlpTracesConfig
{
    /**
     * @param bool                         $enabled            Whether trace export is enabled
     * @param string                       $endpoint           Endpoint override (empty = use root endpoint)
     * @param array<string, list<string>>  $attributeAllowlist Scope-to-allowed-keys mapping
     * @param DbStatementExport            $dbStatementExport  How to export db.statement attribute
     */
    public function __construct(
        public bool $enabled = true,
        public string $endpoint = '',
        public array $attributeAllowlist = [],
        public DbStatementExport $dbStatementExport = DbStatementExport::None,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawEnabled = $data['enabled'] ?? true;
        $rawEndpoint = $data['endpoint'] ?? '';
        $rawAllowlist = $data['attribute_allowlist'] ?? [];
        $rawDbStatement = $data['db_statement_export'] ?? 'none';

        $dbStatementExport = is_string($rawDbStatement)
            ? (DbStatementExport::tryFrom($rawDbStatement) ?? DbStatementExport::None)
            : DbStatementExport::None;

        /** @var array<string, list<string>> $allowlist */
        $allowlist = is_array($rawAllowlist) ? $rawAllowlist : [];

        return new self(
            enabled: is_bool($rawEnabled) ? $rawEnabled : true,
            endpoint: is_string($rawEndpoint) ? $rawEndpoint : '',
            attributeAllowlist: $allowlist,
            dbStatementExport: $dbStatementExport,
        );
    }
}
