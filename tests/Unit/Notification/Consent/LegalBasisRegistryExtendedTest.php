<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Notification\Consent\LegalBasis;
use Pulsar\Notification\Consent\LegalBasisRegistry;

#[CoversClass(LegalBasisRegistry::class)]
final class LegalBasisRegistryExtendedTest extends TestCase
{
    #[Test]
    public function registerAndGetReturnsCorrectBasis(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->register(FakePasswordResetNotification::class, LegalBasis::LegalObligation);

        self::assertSame(LegalBasis::LegalObligation, $registry->get(FakePasswordResetNotification::class));
    }

    #[Test]
    public function hasReturnsTrueForRegisteredType(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->register(FakeWelcomeNotification::class, LegalBasis::Consent);

        self::assertTrue($registry->has(FakeWelcomeNotification::class));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredType(): void
    {
        $registry = new LegalBasisRegistry();

        self::assertFalse($registry->has(FakeUnknownNotification::class));
    }

    #[Test]
    public function getThrowsForUnregisteredType(): void
    {
        $registry = new LegalBasisRegistry();

        $this->expectException(ConfigException::class);

        $registry->get(FakeMissingNotification::class);
    }

    #[Test]
    public function registerTypeAddsTypeWithoutMapping(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->registerType(FakeMarketingNotification::class);

        // Type is registered but has no mapping
        self::assertFalse($registry->has(FakeMarketingNotification::class));
    }

    #[Test]
    public function validatePassesWhenAllTypesHaveMappings(): void
    {
        $this->expectNotToPerformAssertions();

        $registry = new LegalBasisRegistry();
        $registry->register(FakeNotifA::class, LegalBasis::Consent);
        $registry->registerType(FakeNotifA::class);

        // Should not throw
        $registry->validate();
    }

    #[Test]
    public function validateThrowsWhenTypeHasNoMapping(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->registerType(FakeMissingBasisNotif::class);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(FakeMissingBasisNotif::class);

        $registry->validate();
    }

    #[Test]
    public function validateThrowsListingAllMissingTypes(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->registerType(FakeNotifA::class);
        $registry->registerType(FakeNotifB::class);
        $registry->register(FakeNotifA::class, LegalBasis::Consent);
        // FakeNotifB has no mapping

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('FakeNotifB');

        $registry->validate();
    }

    #[Test]
    public function registerTypePreventsduplicates(): void
    {
        $this->expectNotToPerformAssertions();

        $registry = new LegalBasisRegistry();
        $registry->registerType(FakeNotif::class);
        $registry->registerType(FakeNotif::class);

        // Now register a mapping
        $registry->register(FakeNotif::class, LegalBasis::Contract);

        // Validate should pass (only one entry for FakeNotif)
        $registry->validate();
    }

    #[Test]
    public function registerOverwritesPreviousBasis(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->register(FakeNotif::class, LegalBasis::Consent);
        $registry->register(FakeNotif::class, LegalBasis::Contract);

        self::assertSame(LegalBasis::Contract, $registry->get(FakeNotif::class));
    }
}

// Test fixture classes for class-string parameter satisfaction. Each
// represents a hypothetical notification type the registry would map.
final class FakePasswordResetNotification {}
final class FakeWelcomeNotification {}
final class FakeUnknownNotification {}
final class FakeMissingNotification {}
final class FakeMarketingNotification {}
final class FakeNotifA {}
final class FakeNotifB {}
final class FakeMissingBasisNotif {}
final class FakeNotif {}
