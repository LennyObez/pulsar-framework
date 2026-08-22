<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Notification\Consent\LegalBasis;
use Pulsar\Notification\Consent\LegalBasisRegistry;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;

#[CoversClass(LegalBasisRegistry::class)]
final class LegalBasisRegistryTest extends TestCase
{
    #[Test]
    public function it_registers_and_retrieves_legal_basis(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->register(FakeNotificationA::class, LegalBasis::Consent);

        self::assertSame(LegalBasis::Consent, $registry->get(FakeNotificationA::class));
    }

    #[Test]
    public function it_checks_existence(): void
    {
        $registry = new LegalBasisRegistry();

        self::assertFalse($registry->has(FakeNotificationA::class));

        $registry->register(FakeNotificationA::class, LegalBasis::LegalObligation);

        self::assertTrue($registry->has(FakeNotificationA::class));
    }

    #[Test]
    public function it_throws_for_missing_mapping(): void
    {
        $registry = new LegalBasisRegistry();

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains(FakeNotificationA::class);

        $registry->get(FakeNotificationA::class);
    }

    #[Test]
    public function it_validates_all_registered_types_have_mapping(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->registerType(FakeNotificationA::class);
        $registry->register(FakeNotificationA::class, LegalBasis::Consent);

        $registry->validate();

        // Validation passed — mapping is accessible
        self::assertSame(LegalBasis::Consent, $registry->get(FakeNotificationA::class));
    }

    #[Test]
    public function it_throws_on_validate_when_type_missing_mapping(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->registerType(FakeNotificationA::class);
        $registry->registerType(FakeNotificationB::class);
        $registry->register(FakeNotificationA::class, LegalBasis::Consent);
        // FakeNotificationB is not registered

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains(FakeNotificationB::class);

        $registry->validate();
    }

    #[Test]
    public function it_does_not_duplicate_registered_types(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->registerType(FakeNotificationA::class);
        $registry->registerType(FakeNotificationA::class);
        $registry->register(FakeNotificationA::class, LegalBasis::Consent);

        // Duplicate registerType calls must not cause missing-mapping errors
        $registry->validate();

        // Deduplication preserved the single mapping
        self::assertSame(LegalBasis::Consent, $registry->get(FakeNotificationA::class));
    }

    #[Test]
    public function it_registers_legal_basis_and_type_simultaneously(): void
    {
        $registry = new LegalBasisRegistry();
        $registry->register(FakeNotificationA::class, LegalBasis::Contract);

        // register() also registers the type, so validate should pass
        $registry->validate();

        self::assertSame(LegalBasis::Contract, $registry->get(FakeNotificationA::class));
    }
}

/** @internal Test fixture */
final class FakeNotificationA extends Notification
{
    public function via(NotifiableInterface $notifiable): array
    {
        return ['mail'];
    }
}

/** @internal Test fixture */
final class FakeNotificationB extends Notification
{
    public function via(NotifiableInterface $notifiable): array
    {
        return ['sms'];
    }
}
