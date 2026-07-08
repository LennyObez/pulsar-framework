<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminPaginationConfig;

#[CoversClass(AdminPaginationConfig::class)]
final class AdminPaginationConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = AdminPaginationConfig::fromArray([]);

        self::assertSame(25, $config->defaultPerPage);
        self::assertSame(100, $config->maxPerPage);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = AdminPaginationConfig::fromArray([
            'default_per_page' => 10,
            'max_per_page' => 500,
        ]);

        self::assertSame(10, $config->defaultPerPage);
        self::assertSame(500, $config->maxPerPage);
    }

    #[Test]
    public function constructorPropertiesAreAccessible(): void
    {
        $config = new AdminPaginationConfig(
            defaultPerPage: 15,
            maxPerPage: 75,
        );

        self::assertSame(15, $config->defaultPerPage);
        self::assertSame(75, $config->maxPerPage);
    }
}
