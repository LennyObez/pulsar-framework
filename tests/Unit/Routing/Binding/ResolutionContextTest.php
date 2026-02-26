<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\Binding\ResolutionContext;
use ReflectionClass;

#[CoversClass(ResolutionContext::class)]
final class ResolutionContextTest extends TestCase
{
    #[Test]
    public function defaultValuesAreSet(): void
    {
        $context = new ResolutionContext();

        self::assertNull($context->tenantId);
        self::assertNull($context->subjectId);
        self::assertFalse($context->includeTrashed);
        self::assertSame([], $context->attributes);
    }

    #[Test]
    public function allValuesCanBeSet(): void
    {
        $context = new ResolutionContext(
            tenantId: 'tenant-1',
            subjectId: 'user-42',
            includeTrashed: true,
            attributes: ['scope' => 'admin'],
        );

        self::assertSame('tenant-1', $context->tenantId);
        self::assertSame('user-42', $context->subjectId);
        self::assertTrue($context->includeTrashed);
        self::assertSame(['scope' => 'admin'], $context->attributes);
    }

    #[Test]
    public function propertiesAreReadonly(): void
    {
        $context = new ResolutionContext(tenantId: 'tenant-1');

        $reflection = new ReflectionClass($context);

        self::assertTrue($reflection->isReadonly());
    }
}
