<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Consent\NotificationClassification;
use Pulsar\Notification\Consent\NotificationClassificationRegistry;
use Pulsar\Notification\Consent\NotificationType;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;

#[CoversClass(NotificationClassificationRegistry::class)]
final class NotificationClassificationRegistryTest extends TestCase
{
    #[Test]
    public function it_registers_and_classifies(): void
    {
        $registry = new NotificationClassificationRegistry();
        $registry->register(ClassifiedNotificationA::class, NotificationClassification::Transactional);

        $result = $registry->classify(ClassifiedNotificationA::class);

        self::assertSame(NotificationClassification::Transactional, $result);
    }

    #[Test]
    public function it_returns_null_for_unregistered_class_without_attribute(): void
    {
        $registry = new NotificationClassificationRegistry();

        $result = $registry->classify(ClassifiedNotificationA::class);

        self::assertNull($result);
    }

    #[Test]
    public function it_reads_classification_from_attribute(): void
    {
        $registry = new NotificationClassificationRegistry();

        $result = $registry->classify(AttributeClassifiedNotification::class);

        self::assertSame(NotificationClassification::Marketing, $result);
    }

    #[Test]
    public function it_caches_attribute_classification(): void
    {
        $registry = new NotificationClassificationRegistry();

        // First call reads via reflection
        $first = $registry->classify(AttributeClassifiedNotification::class);
        // Second call should use cache
        $second = $registry->classify(AttributeClassifiedNotification::class);

        self::assertSame($first, $second);
        self::assertSame(NotificationClassification::Marketing, $first);
    }

    #[Test]
    public function it_checks_has(): void
    {
        $registry = new NotificationClassificationRegistry();

        self::assertFalse($registry->has(ClassifiedNotificationA::class));

        $registry->register(ClassifiedNotificationA::class, NotificationClassification::Transactional);

        self::assertTrue($registry->has(ClassifiedNotificationA::class));
    }

    #[Test]
    public function it_returns_all_registered_classifications(): void
    {
        $registry = new NotificationClassificationRegistry();
        $registry->register(ClassifiedNotificationA::class, NotificationClassification::Transactional);

        $all = $registry->all();

        self::assertArrayHasKey(ClassifiedNotificationA::class, $all);
        self::assertSame(NotificationClassification::Transactional, $all[ClassifiedNotificationA::class]);
    }

    #[Test]
    public function it_prefers_explicit_registration_over_attribute(): void
    {
        $registry = new NotificationClassificationRegistry();
        $registry->register(
            AttributeClassifiedNotification::class,
            NotificationClassification::Transactional,
        );

        $result = $registry->classify(AttributeClassifiedNotification::class);

        // Explicit registration overrides attribute
        self::assertSame(NotificationClassification::Transactional, $result);
    }
}

/** @internal Test fixture — no attribute */
final class ClassifiedNotificationA extends Notification
{
    public function via(NotifiableInterface $notifiable): array
    {
        return ['mail'];
    }
}

/** @internal Test fixture — with attribute */
#[NotificationType(classification: NotificationClassification::Marketing)]
final class AttributeClassifiedNotification extends Notification
{
    public function via(NotifiableInterface $notifiable): array
    {
        return ['mail'];
    }
}
