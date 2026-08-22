<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for the admin schema builder feature.
 *
 * Disabled by default. When enabled, provides visual schema management
 * with governance-grade audit trails and configurable deny lists.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AdminSchemaConfig
{
    /**
     * @param list<string> $allowedOperations
     * @param list<string> $denyTablePrefixes
     * @param list<string> $requireStepUpFor
     */
    public function __construct(
        public bool $enabled = false,
        public array $allowedOperations = ['create', 'alter', 'drop', 'rename'],
        public array $denyTablePrefixes = ['admin_', 'pulsar_', 'sqlite_', 'studio_'],
        public array $requireStepUpFor = ['drop', 'rename', 'drop_column', 'drop_index'],
    ) {}

    /**
     * @param array{
     *     enabled?: bool,
     *     allowed_operations?: list<string>,
     *     deny_table_prefixes?: list<string>,
     *     require_step_up_for?: list<string>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? false,
            allowedOperations: $data['allowed_operations'] ?? ['create', 'alter', 'drop', 'rename'],
            denyTablePrefixes: $data['deny_table_prefixes'] ?? ['admin_', 'pulsar_', 'sqlite_', 'studio_'],
            requireStepUpFor: $data['require_step_up_for'] ?? ['drop', 'rename', 'drop_column', 'drop_index'],
        );
    }
}
