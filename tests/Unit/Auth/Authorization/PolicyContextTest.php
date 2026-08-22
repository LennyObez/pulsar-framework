<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\PolicyContext;

#[CoversClass(PolicyContext::class)]
final class PolicyContextTest extends TestCase
{
    #[Test]
    public function constructionWithDefaults(): void
    {
        $context = new PolicyContext(permission: 'read');

        self::assertSame('read', $context->permission);
        self::assertNull($context->resource);
        self::assertSame([], $context->attributes);
    }

    #[Test]
    public function constructionWithAllParameters(): void
    {
        $context = new PolicyContext(
            permission: 'write',
            resource: 'document:42',
            attributes: ['role' => 'admin', 'ip' => '10.0.0.1'],
        );

        self::assertSame('write', $context->permission);
        self::assertSame('document:42', $context->resource);
        self::assertSame('admin', $context->attributes['role']);
        self::assertSame('10.0.0.1', $context->attributes['ip']);
    }
}
