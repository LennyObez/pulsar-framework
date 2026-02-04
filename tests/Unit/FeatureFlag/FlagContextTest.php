<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\FlagContext;

#[CoversClass(FlagContext::class)]
final class FlagContextTest extends TestCase
{
    #[Test]
    public function constructionWithAllFields(): void
    {
        $context = new FlagContext(
            tenantId: 'acme',
            userId: 'user-1',
            environment: 'production',
            attributes: ['region' => 'us-east', 'tier' => 'premium'],
        );

        self::assertSame('acme', $context->tenantId);
        self::assertSame('user-1', $context->userId);
        self::assertSame('production', $context->environment);
        self::assertSame(['region' => 'us-east', 'tier' => 'premium'], $context->attributes);
    }

    #[Test]
    public function constructionWithDefaultsProducesNulls(): void
    {
        $context = new FlagContext();

        self::assertNull($context->tenantId);
        self::assertNull($context->userId);
        self::assertNull($context->environment);
        self::assertSame([], $context->attributes);
    }
}
