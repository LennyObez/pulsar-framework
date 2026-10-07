<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\BancontactConfig;

final class BancontactConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = BancontactConfig::fromArray([
            'enabled' => true,
            'preferred_language' => 'fr',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('fr', $config->preferredLanguage);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = BancontactConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('nl', $config->preferredLanguage);
    }

    #[Test]
    public function fromArrayHandlesNonStringLanguage(): void
    {
        $config = BancontactConfig::fromArray([
            'preferred_language' => 42,
        ]);

        self::assertSame('nl', $config->preferredLanguage);
    }
}
