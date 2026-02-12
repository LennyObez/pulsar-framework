<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\TenantScoped;

final class TenantScopedTest extends TestCase
{
    #[Test]
    public function defaultColumn(): void
    {
        $attr = new TenantScoped();

        self::assertNull($attr->column);
    }

    #[Test]
    public function customColumn(): void
    {
        $attr = new TenantScoped(column: 'org_id');

        self::assertSame('org_id', $attr->column);
    }
}
