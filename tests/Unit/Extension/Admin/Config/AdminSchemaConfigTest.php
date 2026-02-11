<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use ReflectionClass;

#[CoversClass(AdminSchemaConfig::class)]
final class AdminSchemaConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = AdminSchemaConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(['create', 'alter', 'drop', 'rename'], $config->allowedOperations);
        self::assertSame(['admin_', 'pulsar_', 'sqlite_', 'studio_'], $config->denyTablePrefixes);
        self::assertSame(['drop', 'rename', 'drop_column', 'drop_index'], $config->requireStepUpFor);
    }

    #[Test]
    public function fromArrayWithExplicitValues(): void
    {
        $config = AdminSchemaConfig::fromArray([
            'enabled' => true,
            'allowed_operations' => ['create', 'alter'],
            'deny_table_prefixes' => ['system_'],
            'require_step_up_for' => ['drop'],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(['create', 'alter'], $config->allowedOperations);
        self::assertSame(['system_'], $config->denyTablePrefixes);
        self::assertSame(['drop'], $config->requireStepUpFor);
    }

    #[Test]
    public function directConstructionWithDefaults(): void
    {
        $config = new AdminSchemaConfig();

        self::assertFalse($config->enabled);
        self::assertCount(4, $config->allowedOperations);
        self::assertCount(4, $config->denyTablePrefixes);
        self::assertCount(4, $config->requireStepUpFor);
    }

    #[Test]
    public function isReadonly(): void
    {
        $config = AdminSchemaConfig::fromArray([]);

        $reflection = new ReflectionClass($config);
        self::assertTrue($reflection->isReadOnly());
    }
}
