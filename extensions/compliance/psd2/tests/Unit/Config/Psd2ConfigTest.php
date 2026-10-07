<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Config\Psd2Config;

final class Psd2ConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = Psd2Config::fromArray([]);

        self::assertSame(300, $config->sca->challengeTimeoutSeconds);
        self::assertSame('memory', $config->sca->challengeStore);
        self::assertSame(8, $config->sca->codeLength);
        self::assertSame(0.3, $config->risk->lowThreshold);
        self::assertSame(0.7, $config->risk->highThreshold);
        self::assertSame(3600, $config->risk->velocityWindowSeconds);
        self::assertTrue($config->certificate->requireQualified);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = Psd2Config::fromArray([
            'sca' => [
                'challenge_timeout_seconds' => 600,
                'challenge_store' => 'redis',
                'code_length' => 6,
            ],
            'risk' => [
                'low_threshold' => 0.2,
                'high_threshold' => 0.8,
                'velocity_window_seconds' => 7200,
                'velocity_max_count' => 20,
                'velocity_max_amount_minor_units' => 100000,
                'low_value_threshold_minor_units' => 5000,
                'velocity_tracker' => 'redis',
            ],
            'certificate' => [
                'require_qualified' => false,
                'check_revocation' => false,
                'trusted_issuers' => ['CN=Test CA'],
                'validator' => 'custom',
            ],
        ]);

        self::assertSame(600, $config->sca->challengeTimeoutSeconds);
        self::assertSame('redis', $config->sca->challengeStore);
        self::assertSame(6, $config->sca->codeLength);
        self::assertSame(0.2, $config->risk->lowThreshold);
        self::assertSame(0.8, $config->risk->highThreshold);
        self::assertSame(7200, $config->risk->velocityWindowSeconds);
        self::assertSame(20, $config->risk->velocityMaxCount);
        self::assertSame(100000, $config->risk->velocityMaxAmountMinorUnits);
        self::assertSame(5000, $config->risk->lowValueThresholdMinorUnits);
        self::assertSame('redis', $config->risk->velocityTracker);
        self::assertFalse($config->certificate->requireQualified);
        self::assertFalse($config->certificate->checkRevocation);
        self::assertSame(['CN=Test CA'], $config->certificate->trustedIssuers);
        self::assertSame('custom', $config->certificate->validator);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $config = Psd2Config::fromArray([
            'sca' => 'not_an_array',
            'risk' => 42,
            'certificate' => null,
        ]);

        // Should fall back to defaults
        self::assertSame(300, $config->sca->challengeTimeoutSeconds);
        self::assertSame(0.3, $config->risk->lowThreshold);
        self::assertTrue($config->certificate->requireQualified);
    }
}
