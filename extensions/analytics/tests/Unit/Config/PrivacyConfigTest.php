<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Config\PrivacyConfig;

final class PrivacyConfigTest extends TestCase
{
    #[Test]
    public function defaultsEnableDntAndAnonymizeReferrer(): void
    {
        $config = new PrivacyConfig();

        self::assertTrue($config->respectDnt);
        self::assertTrue($config->anonymizeReferrer);
    }

    #[Test]
    public function fromArrayEnablesDntAndAnonymizeReferrer(): void
    {
        $config = PrivacyConfig::fromArray([
            'respect_dnt' => true,
            'anonymize_referrer' => true,
        ]);

        self::assertTrue($config->respectDnt);
        self::assertTrue($config->anonymizeReferrer);
    }

    #[Test]
    public function fromArrayWithEmptyDataUsesDefaults(): void
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
