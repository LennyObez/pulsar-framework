<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Trace/span configuration for the observability extension.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TracingConfig
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
     * @param array{
     *     enabled?: bool,
     *     endpoint?: string,
     *     attribute_allowlist?: array<string, list<string>>,
     *     db_statement_export?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? true,
            endpoint: $data['endpoint'] ?? '',
            attributeAllowlist: $data['attribute_allowlist'] ?? [],
            dbStatementExport: DbStatementExport::tryFrom($data['db_statement_export'] ?? '') ?? DbStatementExport::None,
        );
    }
}
