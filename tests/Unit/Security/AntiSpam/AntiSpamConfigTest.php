<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamConfig;

#[CoversClass(AntiSpamConfig::class)]
final class AntiSpamConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreReasonable(): void
    {
        $config = new AntiSpamConfig();

        self::assertTrue($config->honeypotEnabled);
        self::assertSame('website_url', $config->honeypotFieldName);
        self::assertTrue($config->duplicateDetectionEnabled);
        self::assertSame(300, $config->duplicateWindowSeconds);
        self::assertSame(85.0, $config->duplicateSimilarityThreshold);
        self::assertTrue($config->linkDensityEnabled);
        self::assertSame(0.3, $config->maxLinkDensity);
        self::assertTrue($config->contentQualityEnabled);
        self::assertSame(10, $config->minContentLength);
        self::assertSame(0.8, $config->maxUppercaseRatio);
        self::assertSame(0.5, $config->maxRepeatedCharRatio);
        self::assertFalse($config->captchaEnabled);
        self::assertSame('hcaptcha', $config->captchaProvider);
        self::assertFalse($config->accountAgeGateEnabled);
        self::assertSame(300, $config->minAccountAgeSeconds);
        self::assertTrue($config->reputationCooldownEnabled);
        self::assertSame(['new' => 60, 'established' => 10, 'moderator' => 0], $config->cooldownTiers);
        self::assertFalse($config->shortCircuit);
        // Time-trap is opt-in: defaults must preserve existing behaviour.
        self::assertFalse($config->timeTrapEnabled);
        self::assertSame(3, $config->timeTrapMinSeconds);
        self::assertSame('pulsar-form-ts', $config->timeTrapFieldName);
    }

    #[Test]
    public function fromArrayMapsTimeTrapKeys(): void
    {
        $config = AntiSpamConfig::fromArray([
            'time_trap_enabled' => true,
            'time_trap_min_seconds' => 5,
            'time_trap_field_name' => 'ts',
        ]);

        self::assertTrue($config->timeTrapEnabled);
        self::assertSame(5, $config->timeTrapMinSeconds);
        self::assertSame('ts', $config->timeTrapFieldName);
    }

    #[Test]
    public function timeTrapStaysDisabledByDefaultViaFromArray(): void
    {
        self::assertFalse(AntiSpamConfig::fromArray([])->timeTrapEnabled);
    }

    #[Test]
    public function behaviorDefaultsAreOptInOff(): void
    {
        $config = new AntiSpamConfig();

        self::assertFalse($config->behaviorEnabled);
        self::assertSame('pulsar-bx', $config->behaviorFieldName);
        self::assertSame([], $config->behaviorWeights);
        self::assertFalse(AntiSpamConfig::fromArray([])->behaviorEnabled);
    }

    #[Test]
    public function fromArrayMapsBehaviorKeys(): void
    {
        $config = AntiSpamConfig::fromArray([
            'behavior_enabled' => true,
            'behavior_field_name' => 'bx',
            'behavior_weights' => ['webdriver' => 50],
        ]);

        self::assertTrue($config->behaviorEnabled);
        self::assertSame('bx', $config->behaviorFieldName);
        self::assertSame(['webdriver' => 50], $config->behaviorWeights);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = AntiSpamConfig::fromArray([]);

        self::assertTrue($config->honeypotEnabled);
        self::assertSame('website_url', $config->honeypotFieldName);
        self::assertSame(0.3, $config->maxLinkDensity);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = AntiSpamConfig::fromArray([
            'honeypot_enabled' => false,
            'honeypot_field_name' => 'fax',
            'max_link_density' => 0.5,
            'min_content_length' => 20,
            'captcha_enabled' => true,
            'captcha_provider' => 'turnstile',
            'captcha_site_key' => 'site123',
            'captcha_secret_key' => 'secret456',
            'account_age_gate_enabled' => true,
            'min_account_age_seconds' => 600,
            'short_circuit' => true,
            'cooldown_tiers' => ['new' => 120, 'established' => 30, 'moderator' => 0],
        ]);

        self::assertFalse($config->honeypotEnabled);
        self::assertSame('fax', $config->honeypotFieldName);
        self::assertSame(0.5, $config->maxLinkDensity);
        self::assertSame(20, $config->minContentLength);
        self::assertTrue($config->captchaEnabled);
        self::assertSame('turnstile', $config->captchaProvider);
        self::assertSame('site123', $config->captchaSiteKey);
        self::assertSame('secret456', $config->captchaSecretKey);
        self::assertTrue($config->accountAgeGateEnabled);
        self::assertSame(600, $config->minAccountAgeSeconds);
        self::assertTrue($config->shortCircuit);
        self::assertSame(120, $config->cooldownTiers['new']);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $config = AntiSpamConfig::fromArray([
            'honeypot_enabled' => 'not-a-bool',
            'min_content_length' => 'not-an-int',
            'max_link_density' => 'not-a-float',
            'honeypot_field_name' => 42,
        ]);

        // Should fall back to defaults
        self::assertTrue($config->honeypotEnabled);
        self::assertSame(10, $config->minContentLength);
        self::assertSame(0.3, $config->maxLinkDensity);
        self::assertSame('website_url', $config->honeypotFieldName);
    }
}
