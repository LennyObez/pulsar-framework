<?php

declare(strict_types=1);

namespace Pulsar\Notification\Consent;

use Attribute;
use Pulsar\Api\Api;

/**
 * Declares the classification of a notification class.
 *
 * Applied to Notification subclasses to declare whether they are transactional
 * or marketing. Classification is immutable per version; read from attribute
 * at build/boot time.
 *
 * ```php
 * #[NotificationType(classification: NotificationClassification::Transactional)]
 * final class PasswordResetNotification extends Notification { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class NotificationType
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public NotificationClassification $classification,
    ) {}
}
