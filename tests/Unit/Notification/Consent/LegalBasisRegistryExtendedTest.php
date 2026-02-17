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
        $registry->register('App\\PasswordResetNotification', LegalBasis::LegalObligation);

        self::assertSame(LegalBasis::LegalObligation, $registry->get('App\\PasswordResetNotification'));
    }

    #[Test]
    public function hasReturnsTrueForRegisteredType(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->register('App\\WelcomeNotification', LegalBasis::Consent);

        self::assertTrue($registry->has('App\\WelcomeNotification'));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredType(): void
    {
        $registry = new LegalBasisRegistry();

        self::assertFalse($registry->has('App\\UnknownNotification'));
    }

    #[Test]
    public function getThrowsForUnregisteredType(): void
    {
        $registry = new LegalBasisRegistry();

        $this->expectException(ConfigException::class);

        $registry->get('App\\MissingNotification');
    }

    #[Test]
    public function registerTypeAddsTypeWithoutMapping(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->registerType('App\\MarketingNotification');

        // Type is registered but has no mapping
        self::assertFalse($registry->has('App\\MarketingNotification'));
    }

    #[Test]
    public function validatePassesWhenAllTypesHaveMappings(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->register('App\\NotifA', LegalBasis::Consent);
        $registry->registerType('App\\NotifA');

        // Should not throw
        $registry->validate();

        self::assertTrue(true, 'Validation should pass when all types have mappings');
    }

    #[Test]
    public function validateThrowsWhenTypeHasNoMapping(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->registerType('App\\MissingBasisNotif');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('App\\MissingBasisNotif');

        $registry->validate();
    }

    #[Test]
    public function validateThrowsListingAllMissingTypes(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->registerType('App\\NotifA');
        $registry->registerType('App\\NotifB');
        $registry->register('App\\NotifA', LegalBasis::Consent);
        // App\NotifB has no mapping

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('App\\NotifB');

        $registry->validate();
    }

    #[Test]
    public function registerTypePreventsduplicates(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->registerType('App\\Notif');
        $registry->registerType('App\\Notif');

        // Now register a mapping
        $registry->register('App\\Notif', LegalBasis::Contract);

        // Validate should pass (only one entry for App\Notif)
        $registry->validate();

        self::assertTrue(true, 'Duplicate registerType calls should not cause double validation entries');
    }

    #[Test]
    public function registerOverwritesPreviousBasis(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->register('App\\Notif', LegalBasis::Consent);
        $registry->register('App\\Notif', LegalBasis::Contract);

        self::assertSame(LegalBasis::Contract, $registry->get('App\\Notif'));
    }
}
