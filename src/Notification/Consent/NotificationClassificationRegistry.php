<?php

declare(strict_types=1);

namespace Pulsar\Notification\Consent;

use Pulsar\Api\Internal;
use ReflectionClass;

use function count;

/**
 * Compiled map of notification class => classification, built at boot from attributes.
 *
 * Reads #[NotificationType] attributes via reflection and caches the result.
 * Classification is immutable per version.
 */
#[Internal(reason: 'Boot-time infrastructure for notification classification')]
final class NotificationClassificationRegistry
{
    /** @var array<class-string, NotificationClassification> */
    private array $classifications = [];

    /**
     * Register a notification class with its classification.
     *
     * @param class-string $notificationClass
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function register(string $notificationClass, NotificationClassification $classification): void
    {
        $this->classifications[$notificationClass] = $classification;
    }

    /**
     * Resolve the classification for a notification class.
     *
     * Checks the cached registry first, then falls back to reading the
     * #[NotificationType] attribute via reflection.
     *
     * @param class-string $notificationClass
     */
    public function classify(string $notificationClass): ?NotificationClassification
    {
        if (isset($this->classifications[$notificationClass])) {
            return $this->classifications[$notificationClass];
        }

        $classification = $this->readFromAttribute($notificationClass);

        if ($classification !== null) {
            $this->classifications[$notificationClass] = $classification;
        }

        return $classification;
    }

    /**
     * Check if a notification class has a registered or attribute-declared classification.
     *
     * @param class-string $notificationClass
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function has(string $notificationClass): bool
    {
        return $this->classify($notificationClass) !== null;
    }

    /**
     * Get all registered classifications.
     *
     * @return array<class-string, NotificationClassification>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function all(): array
    {
        return $this->classifications;
    }

    /**
     * @param class-string $notificationClass
     */
    private function readFromAttribute(string $notificationClass): ?NotificationClassification
    {
        $reflection = new ReflectionClass($notificationClass);
        $attributes = $reflection->getAttributes(NotificationType::class);

        if (count($attributes) === 0) {
            return null;
        }

        /** @var NotificationType $instance */
        $instance = $attributes[0]->newInstance();

        return $instance->classification;
    }
}
