<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivacyPassConfig;
use Pulsar\Security\AntiSpam\PrivacyPass\TokenChallenge;

#[CoversClass(PrivacyPassConfig::class)]
final class PrivacyPassConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabledAndEmpty(): void
    {
        $config = new PrivacyPassConfig();

        self::assertFalse($config->enabled);
        self::assertSame('', $config->issuerName);
        self::assertSame('', $config->originInfo);
        self::assertSame('', $config->tokenKey);
        self::assertSame(0x0002, $config->tokenType);
        self::assertFalse($config->isUsable());
    }

    #[Test]
    public function fromArrayParsesEveryField(): void
    {
        $config = PrivacyPassConfig::fromArray([
            'enabled' => true,
            'issuer_name' => 'issuer.example',
            'origin_info' => 'origin.example',
            'token_key' => 'BASE64URLKEY',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('issuer.example', $config->issuerName);
        self::assertSame('origin.example', $config->originInfo);
        self::assertSame('BASE64URLKEY', $config->tokenKey);
    }

    #[Test]
    public function isNotUsableWithoutIssuerNameOrKey(): void
    {
        self::assertFalse(PrivacyPassConfig::fromArray(['enabled' => true])->isUsable());
        self::assertFalse(PrivacyPassConfig::fromArray(['enabled' => true, 'issuer_name' => 'i'])->isUsable());
        self::assertFalse(PrivacyPassConfig::fromArray(['enabled' => true, 'token_key' => 'k'])->isUsable());
        self::assertFalse(
            PrivacyPassConfig::fromArray(['issuer_name' => 'i', 'token_key' => 'k'])->isUsable(),
            'disabled is never usable',
        );
    }

    #[Test]
    public function isUsableWhenEnabledWithIssuerAndKey(): void
    {
        $config = PrivacyPassConfig::fromArray([
            'enabled' => true,
            'issuer_name' => 'issuer.example',
            'token_key' => 'k',
        ]);

        self::assertTrue($config->isUsable());
    }

    #[Test]
    public function challengeUsesConfiguredIssuerOriginAndEmptyContext(): void
    {
        $config = PrivacyPassConfig::fromArray([
            'enabled' => true,
            'issuer_name' => 'issuer.example',
            'origin_info' => 'origin.example',
            'token_key' => 'k',
        ]);

        $challenge = $config->challenge();

        self::assertInstanceOf(TokenChallenge::class, $challenge);
        self::assertSame('issuer.example', $challenge->issuerName);
        self::assertSame('origin.example', $challenge->originInfo);
        self::assertSame('', $challenge->redemptionContext);
    }
}
