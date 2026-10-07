<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;

#[CoversClass(WebAuthnConfig::class)]
final class WebAuthnConfigTest extends TestCase
{
    #[Test]
    public function constructionPreservesAllFields(): void
    {
        $config = new WebAuthnConfig(
            rpName: 'Pulsar App',
            rpId: 'example.com',
            origin: 'https://example.com',
            userVerification: 'required',
            attestation: 'direct',
            allowedFormats: ['none', 'packed', 'tpm'],
            challengeTtlSeconds: 120,
            timeout: 30000,
        );

        self::assertSame('Pulsar App', $config->rpName);
        self::assertSame('example.com', $config->rpId);
        self::assertSame('https://example.com', $config->origin);
        self::assertSame('required', $config->userVerification);
        self::assertSame('direct', $config->attestation);
        self::assertSame(['none', 'packed', 'tpm'], $config->allowedFormats);
        self::assertSame(120, $config->challengeTtlSeconds);
        self::assertSame(30000, $config->timeout);
    }

    #[Test]
    public function defaultValues(): void
    {
        $config = new WebAuthnConfig(
            rpName: 'Test App',
            rpId: 'test.example.com',
            origin: 'https://test.example.com',
        );

        self::assertSame('preferred', $config->userVerification);
        self::assertSame('none', $config->attestation);
        self::assertSame(['none', 'packed'], $config->allowedFormats);
        self::assertSame(300, $config->challengeTtlSeconds);
        self::assertSame(60000, $config->timeout);
    }

    #[Test]
    public function fromArrayCreatesConfig(): void
    {
        $config = WebAuthnConfig::fromArray([
            'rp_name' => 'Pulsar App',
            'rp_id' => 'example.com',
            'origin' => 'https://example.com',
            'user_verification' => 'required',
            'attestation' => 'direct',
            'allowed_formats' => ['none', 'packed'],
            'challenge_ttl_seconds' => 120,
            'timeout' => 30000,
        ]);

        self::assertSame('Pulsar App', $config->rpName);
        self::assertSame('example.com', $config->rpId);
        self::assertSame('https://example.com', $config->origin);
        self::assertSame('required', $config->userVerification);
        self::assertSame('direct', $config->attestation);
        self::assertSame(['none', 'packed'], $config->allowedFormats);
        self::assertSame(120, $config->challengeTtlSeconds);
        self::assertSame(30000, $config->timeout);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = WebAuthnConfig::fromArray([
            'rp_name' => 'App',
            'rp_id' => 'example.com',
            'origin' => 'https://example.com',
        ]);

        self::assertSame('preferred', $config->userVerification);
        self::assertSame('none', $config->attestation);
        self::assertSame(['none', 'packed'], $config->allowedFormats);
    }

    #[Test]
    public function fromArraySupportsCamelCaseKeys(): void
    {
        $config = WebAuthnConfig::fromArray([
            'rpName' => 'Pulsar App',
            'rpId' => 'example.com',
            'origin' => 'https://example.com',
            'userVerification' => 'discouraged',
            'allowedFormats' => ['none'],
            'challengeTtlSeconds' => 60,
        ]);

        self::assertSame('Pulsar App', $config->rpName);
        self::assertSame('example.com', $config->rpId);
        self::assertSame('discouraged', $config->userVerification);
        self::assertSame(['none'], $config->allowedFormats);
        self::assertSame(60, $config->challengeTtlSeconds);
    }
}
