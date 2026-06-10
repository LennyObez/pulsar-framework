<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

/**
 * Traces-specific OTLP configuration.
 * @api
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
        $allowlist = $data['attribute_allowlist'] ?? null;

        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null, true),
            endpoint: Coerce::string($data['endpoint'] ?? null),
            attributeAllowlist: is_array($allowlist) ? $allowlist : [],
            dbStatementExport: DbStatementExport::tryFrom(Coerce::string($data['db_statement_export'] ?? null)) ?? DbStatementExport::None,
        );
    }
}
