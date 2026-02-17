<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;

#[CoversClass(AdminSchemaConfig::class)]
final class AdminSchemaConfigTest extends TestCase
{
    #[Test]
    public function from_array_with_defaults(): void
    {
        $config = AdminSchemaConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(['create', 'alter', 'drop', 'rename'], $config->allowedOperations);
        self::assertSame(['admin_', 'pulsar_', 'sqlite_', 'studio_'], $config->denyTablePrefixes);
        self::assertSame(['drop', 'rename', 'drop_column', 'drop_index'], $config->requireStepUpFor);
    }

    #[Test]
    public function from_array_with_custom_values(): void
    {
        $config = AdminSchemaConfig::fromArray([
            'enabled' => true,
            'allowed_operations' => ['create', 'alter'],
            'deny_table_prefixes' => ['secret_'],
            'require_step_up_for' => ['drop'],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(['create', 'alter'], $config->allowedOperations);
        self::assertSame(['secret_'], $config->denyTablePrefixes);
        self::assertSame(['drop'], $config->requireStepUpFor);
    }

    #[Test]
    public function constructor_defaults(): void
    {
        $config = new AdminSchemaConfig();

        self::assertFalse($config->enabled);
        self::assertSame(['create', 'alter', 'drop', 'rename'], $config->allowedOperations);
        self::assertSame(['admin_', 'pulsar_', 'sqlite_', 'studio_'], $config->denyTablePrefixes);
        self::assertSame(['drop', 'rename', 'drop_column', 'drop_index'], $config->requireStepUpFor);
    }

    #[Test]
    public function from_array_with_empty_lists(): void
    {
        $config = AdminSchemaConfig::fromArray([
            'enabled' => true,
            'allowed_operations' => [],
            'deny_table_prefixes' => [],
            'require_step_up_for' => [],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame([], $config->allowedOperations);
        self::assertSame([], $config->denyTablePrefixes);
        self::assertSame([], $config->requireStepUpFor);
    }
}
