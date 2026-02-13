<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;

#[CoversClass(CapabilityDeniedException::class)]
final class CapabilityDeniedExceptionTest extends TestCase
{
    #[Test]
    public function extendsExtensionException(): void
    {
        $exception = CapabilityDeniedException::forService(
            'Some\Service',
            TrustTier::Community,
            ExtensionCapability::CryptoKeyAccess,
        );

        self::assertInstanceOf(ExtensionException::class, $exception);
    }

    #[Test]
    public function forServiceIncludesServiceIdInMessage(): void
    {
        $exception = CapabilityDeniedException::forService(
            'Pulsar\Security\Crypto\MasterKey',
            TrustTier::Community,
            ExtensionCapability::CryptoKeyAccess,
        );

        self::assertStringContainsString('Pulsar\Security\Crypto\MasterKey', $exception->getMessage());
    }

    #[Test]
    public function forServiceIncludesTierInMessage(): void
    {
        $exception = CapabilityDeniedException::forService(
            'Some\Service',
            TrustTier::Community,
            ExtensionCapability::CryptoKeyAccess,
        );

        self::assertStringContainsString('Community', $exception->getMessage());
    }

    #[Test]
    public function forServiceIncludesRequiredCapabilityInMessage(): void
    {
        $exception = CapabilityDeniedException::forService(
            'Some\Service',
            TrustTier::Community,
            ExtensionCapability::CryptoKeyAccess,
        );

        self::assertStringContainsString('CryptoKeyAccess', $exception->getMessage());
    }

    #[Test]
    public function forServiceIncludesRemediationGuidance(): void
    {
        $exception = CapabilityDeniedException::forService(
            'Some\Service',
            TrustTier::Community,
            ExtensionCapability::CryptoKeyAccess,
        );

        self::assertStringContainsString('config/extensions.php', $exception->getMessage());
    }

    #[Test]
    public function forCapabilityIncludesTierAndCapability(): void
    {
        $exception = CapabilityDeniedException::forCapability(
            TrustTier::Untrusted,
            ExtensionCapability::ContainerWrite,
        );

        self::assertStringContainsString('Untrusted', $exception->getMessage());
        self::assertStringContainsString('ContainerWrite', $exception->getMessage());
    }

    #[Test]
    public function forUnknownServiceIncludesServiceId(): void
    {
        $exception = CapabilityDeniedException::forUnknownService(
            'Unknown\Service',
            TrustTier::Community,
        );

        self::assertStringContainsString('Unknown\Service', $exception->getMessage());
        self::assertStringContainsString('Community', $exception->getMessage());
    }

    #[Test]
    public function forUnknownServiceMentionsDenyByDefault(): void
    {
        $exception = CapabilityDeniedException::forUnknownService(
            'Unknown\Service',
            TrustTier::Community,
        );

        self::assertStringContainsString('not classified', $exception->getMessage());
    }
}
