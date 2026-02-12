<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Config\WebAuthnConfig;

final class WebAuthnConfigTest extends TestCase
{
    #[Test]
    public function construction_with_defaults(): void
    {
        $config = new WebAuthnConfig(
            rpName: 'Test App',
            rpId: 'example.com',
            origin: 'https://example.com',
        );

        self::assertSame('Test App', $config->rpName);
        self::assertSame('example.com', $config->rpId);
        self::assertSame('https://example.com', $config->origin);
        self::assertSame('preferred', $config->userVerification);
        self::assertSame('none', $config->attestation);
        self::assertSame(['none', 'packed'], $config->allowedFormats);
        self::assertSame(300, $config->challengeTtlSeconds);
        self::assertSame(60000, $config->timeout);
    }

    #[Test]
    public function construction_with_custom_values(): void
    {
        $config = new WebAuthnConfig(
            rpName: 'Secure App',
            rpId: 'secure.example.com',
            origin: 'https://secure.example.com',
            userVerification: 'required',
            attestation: 'direct',
            allowedFormats: ['packed'],
            challengeTtlSeconds: 120,
            timeout: 30000,
        );

        self::assertSame('required', $config->userVerification);
        self::assertSame('direct', $config->attestation);
        self::assertSame(['packed'], $config->allowedFormats);
        self::assertSame(120, $config->challengeTtlSeconds);
        self::assertSame(30000, $config->timeout);
    }

    #[Test]
    public function from_array_with_snake_case_keys(): void
    {
        $config = WebAuthnConfig::fromArray([
            'rp_name' => 'From Array',
            'rp_id' => 'arr.example.com',
            'origin' => 'https://arr.example.com',
            'user_verification' => 'discouraged',
            'attestation' => 'indirect',
            'allowed_formats' => ['none'],
            'challenge_ttl_seconds' => 600,
            'timeout' => 90000,
        ]);

        self::assertSame('From Array', $config->rpName);
        self::assertSame('arr.example.com', $config->rpId);
        self::assertSame('discouraged', $config->userVerification);
        self::assertSame('indirect', $config->attestation);
        self::assertSame(['none'], $config->allowedFormats);
        self::assertSame(600, $config->challengeTtlSeconds);
        self::assertSame(90000, $config->timeout);
    }

    #[Test]
    public function from_array_with_camel_case_keys(): void
    {
        $config = WebAuthnConfig::fromArray([
            'rpName' => 'Camel',
            'rpId' => 'camel.example.com',
            'origin' => 'https://camel.example.com',
            'userVerification' => 'required',
        ]);

        self::assertSame('Camel', $config->rpName);
        self::assertSame('camel.example.com', $config->rpId);
        self::assertSame('required', $config->userVerification);
    }

    #[Test]
    public function from_array_with_defaults(): void
    {
        $config = WebAuthnConfig::fromArray([]);

        self::assertSame('', $config->rpName);
        self::assertSame('', $config->rpId);
        self::assertSame('', $config->origin);
        self::assertSame('preferred', $config->userVerification);
        self::assertSame('none', $config->attestation);
    }
}
