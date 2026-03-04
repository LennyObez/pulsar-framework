<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\ConsentConfig;

#[CoversClass(ConsentConfig::class)]
final class ConsentConfigTest extends TestCase
{
    #[Test]
    public function defaultsRequireExplicitConsentWithNoPurposes(): void
    {
        $config = new ConsentConfig();

        self::assertTrue($config->requireExplicit);
        self::assertSame([], $config->purposes);
    }

    #[Test]
    public function constructionStoresAllProperties(): void
    {
        $config = new ConsentConfig(
            requireExplicit: false,
            purposes: ['marketing', 'analytics'],
        );

        self::assertFalse($config->requireExplicit);
        self::assertSame(['marketing', 'analytics'], $config->purposes);
    }

    #[Test]
    public function fromArrayPopulatesAllFields(): void
    {
        $config = ConsentConfig::fromArray([
            'require_explicit' => false,
            'purposes' => ['analytics', 'personalization'],
        ]);

        self::assertFalse($config->requireExplicit);
        self::assertSame(['analytics', 'personalization'], $config->purposes);
    }

    #[Test]
    public function fromArrayDefaultsOnMissingKeys(): void
    {
        $config = ConsentConfig::fromArray([]);

        self::assertTrue($config->requireExplicit);
        self::assertSame([], $config->purposes);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $config = ConsentConfig::fromArray([
            'require_explicit' => 'not_a_bool',
            'purposes' => 'not_an_array',
        ]);

        self::assertTrue($config->requireExplicit);
        self::assertSame([], $config->purposes);
    }
}
