<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Config\PrivacyConfig;

#[CoversClass(PrivacyConfig::class)]
final class PrivacyConfigTest extends TestCase
{
    #[Test]
    public function defaultConstruction(): void
    {
        $config = new PrivacyConfig();

        self::assertTrue($config->respectDnt);
        self::assertTrue($config->anonymizeReferrer);
    }

    #[Test]
    public function customConstruction(): void
    {
        $config = new PrivacyConfig(respectDnt: true, anonymizeReferrer: true);

        self::assertTrue($config->respectDnt);
        self::assertTrue($config->anonymizeReferrer);
    }

    #[Test]
    public function fromArrayWithValues(): void
    {
        $config = PrivacyConfig::fromArray([
            'respect_dnt' => true,
            'anonymize_referrer' => true,
        ]);

        self::assertTrue($config->respectDnt);
        self::assertTrue($config->anonymizeReferrer);
    }

    #[Test]
    public function fromArrayWithEmptyData(): void
    {
        $config = PrivacyConfig::fromArray([]);

        self::assertTrue($config->respectDnt);
        self::assertTrue($config->anonymizeReferrer);
    }

    #[Test]
    public function fromArrayCastsTruthyValues(): void
    {
        $config = PrivacyConfig::fromArray([
            'respect_dnt' => 1,
            'anonymize_referrer' => 'yes',
        ]);

        self::assertTrue($config->respectDnt);
        self::assertTrue($config->anonymizeReferrer);
    }
}
