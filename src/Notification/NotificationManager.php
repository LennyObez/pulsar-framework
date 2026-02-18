<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\NotificationChannelType;
use Pulsar\Config\NotificationConfig;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Notification\Consent\LegalBasisRegistry;
use Pulsar\Notification\Consent\NotificationClassification;
use Pulsar\Notification\Consent\NotificationClassificationRegistry;
use Pulsar\Notification\Consent\PreferenceStoreInterface;
use Pulsar\Notification\Event\NotificationFailed;
use Pulsar\Notification\Event\NotificationSent;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Throwable;

use function array_key_exists;
use function array_map;
use function bin2hex;
use function random_bytes;
use function sprintf;
use function time;

/**
 * Central notification dispatcher — resolves channels, dispatches delivery, emits events.
 *
 * When a PreferenceStoreInterface and NotificationClassificationRegistry are provided,
 * marketing notifications are blocked for notifiables who have not opted in.
 * Transactional notifications always bypass opt-out checks.
 */
#[Api(since: '1.0.0')]
final readonly class NotificationManager implements NotificationManagerInterface
{
    /** @var array<string, NotificationChannelInterface> */
    private array $channels;

    /**
     * @param array<string, NotificationChannelInterface> $channels Channel instances keyed by name
     */
    public function __construct(
        private NotificationConfig $config,
        array $channels,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?AuditLoggerInterface $auditLogger = null,
        private ?LoggerInterface $logger = null,
        private ?NotificationClassificationRegistry $classificationRegistry = null,
        private ?PreferenceStoreInterface $preferenceStore = null,
        private ?LegalBasisRegistry $legalBasisRegistry = null,
    ) {
        $this->channels = $channels;
    }

    public function send(NotifiableInterface $notifiable, Notification $notification): void
    {
        $this->sendNow($notifiable, $notification);
    }

    public function sendNow(NotifiableInterface $notifiable, Notification $notification, ?array $channels = null): void
    {
        $channelNames = $channels ?? $notification->via($notifiable);

        if ($channelNames === []) {
            $channelNames = array_map(
                static fn(NotificationChannelType $type): string => $type->value,
                $this->config->defaultChannels,
            );
        }

        if ($channelNames === []) {
            $this->logger?->warning('Notification has no channels configured', [
                'notification' => $notification::class,
                'notifiable_id' => $notifiable->getNotifiableId(),
            ]);

            return;
        }

        $notificationId = bin2hex(random_bytes(16));
        $classification = $this->resolveClassification($notification);

        if ($this->config->regulated && !$this->checkLegalBasis($notification, $notificationId, $notifiable->getNotifiableId())) {
            return;
        }

        foreach ($channelNames as $channelName) {
            if (!array_key_exists($channelName, $this->channels)) {
                $this->logger?->error('Notification channel not registered', [
                    'channel' => $channelName,
                    'notification' => $notification::class,
                ]);

                $this->emitFailed(
                    $notificationId,
                    $notifiable->getNotifiableId(),
                    $channelName,
                    sprintf('Channel "%s" is not registered', $channelName),
                );

                continue;
            }

            if (!$this->checkConsent($notifiable, $channelName, $notification, $classification, $notificationId)) {
                continue;
            }

            $channel = $this->channels[$channelName];

            try {
                $channel->send($notifiable, $notification);

                $this->emitSent($notificationId, $notifiable->getNotifiableId(), $channelName);

                $this->auditLog(
                    AuditOutcome::Success,
                    'notification.sent',
                    $notification::class,
                    [
                        'notification_id' => $notificationId,
                        'notifiable_id' => $notifiable->getNotifiableId(),
                        'channel' => $channelName,
                        'classification' => $classification?->value,
                    ],
                );
            } catch (NotificationException $e) {
                $this->logger?->error('Notification delivery failed', [
                    'channel' => $channelName,
                    'notification' => $notification::class,
                    'notifiable_id' => $notifiable->getNotifiableId(),
                    'error' => $e->getMessage(),
                ]);

                $this->emitFailed(
                    $notificationId,
                    $notifiable->getNotifiableId(),
                    $channelName,
                    $e->getMessage(),
                );

                $this->auditLog(
                    AuditOutcome::Failure,
                    'notification.failed',
                    $notification::class,
                    [
                        'notification_id' => $notificationId,
                        'notifiable_id' => $notifiable->getNotifiableId(),
                        'channel' => $channelName,
                        'reason' => $e->getMessage(),
                    ],
                );
            } catch (Throwable $e) {
                $this->logger?->error('Unexpected notification error', [
                    'channel' => $channelName,
                    'notification' => $notification::class,
                    'notifiable_id' => $notifiable->getNotifiableId(),
                    'error' => $e->getMessage(),
                ]);

                $this->emitFailed(
                    $notificationId,
                    $notifiable->getNotifiableId(),
                    $channelName,
                    $e->getMessage(),
                );
            }
        }
    }

    /**
     * Check consent/preference for marketing notifications.
     *
     * Transactional notifications always pass. Marketing notifications are blocked
     * when the notifiable has not opted in to the channel.
     *
     * @return bool True if the notification should proceed, false if blocked by preferences
     */
    private function checkConsent(
        NotifiableInterface $notifiable,
        string $channelName,
        Notification $notification,
        ?NotificationClassification $classification,
        string $notificationId,
    ): bool {
        if ($classification !== NotificationClassification::Marketing) {
            return true;
        }

        if ($this->preferenceStore === null) {
            return true;
        }

        $preferences = $this->preferenceStore->getPreferences($notifiable->getNotifiableId());

        if ($preferences->isOptedIn($channelName)) {
            $this->auditLog(
                AuditOutcome::Success,
                'notification.consent_check',
                $notification::class,
                [
                    'notification_id' => $notificationId,
                    'notifiable_id' => $notifiable->getNotifiableId(),
                    'channel' => $channelName,
                    'result' => 'opted_in',
                ],
            );

            return true;
        }

        $this->logger?->info('Marketing notification blocked by preference', [
            'notification' => $notification::class,
            'notifiable_id' => $notifiable->getNotifiableId(),
            'channel' => $channelName,
        ]);

        $this->auditLog(
            AuditOutcome::Denied,
            'notification.consent_check',
            $notification::class,
            [
                'notification_id' => $notificationId,
                'notifiable_id' => $notifiable->getNotifiableId(),
                'channel' => $channelName,
                'result' => 'blocked_no_opt_in',
            ],
        );

        $this->emitFailed(
            $notificationId,
            $notifiable->getNotifiableId(),
            $channelName,
            sprintf(
                'Marketing notification blocked: notifiable "%s" has not opted in to channel "%s"',
                $notifiable->getNotifiableId(),
                $channelName,
            ),
        );

        return false;
    }

    /**
     * Verify that a legal basis mapping exists for the notification type in regulated mode.
     *
     * @return bool True if the notification may proceed, false if blocked
     */
    private function checkLegalBasis(Notification $notification, string $notificationId, string $notifiableId): bool
    {
        if ($this->legalBasisRegistry === null) {
            return true;
        }

        if ($this->legalBasisRegistry->has($notification::class)) {
            $legalBasis = $this->legalBasisRegistry->get($notification::class);

            $this->auditLog(
                AuditOutcome::Success,
                'notification.legal_basis_check',
                $notification::class,
                [
                    'notification_id' => $notificationId,
                    'notifiable_id' => $notifiableId,
                    'legal_basis' => $legalBasis->value,
                ],
            );

            return true;
        }

        $this->logger?->error('Notification blocked: no legal basis registered in regulated mode', [
            'notification' => $notification::class,
            'notifiable_id' => $notifiableId,
        ]);

        $this->auditLog(
            AuditOutcome::Denied,
            'notification.legal_basis_check',
            $notification::class,
            [
                'notification_id' => $notificationId,
                'notifiable_id' => $notifiableId,
                'result' => 'no_legal_basis',
            ],
        );

        return false;
    }

    private function resolveClassification(Notification $notification): ?NotificationClassification
    {
        return $this->classificationRegistry?->classify($notification::class);
    }

    private function emitSent(string $notificationId, string $notifiableId, string $channel): void
    {
        $this->eventDispatcher?->dispatch(new NotificationSent(
            $notificationId,
            $notifiableId,
            time(),
            $channel,
        ));
    }

    private function emitFailed(string $notificationId, string $notifiableId, string $channel, string $reason): void
    {
        $this->eventDispatcher?->dispatch(new NotificationFailed(
            $notificationId,
            $notifiableId,
            time(),
            $channel,
            $reason,
        ));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function auditLog(AuditOutcome $outcome, string $action, string $resource, array $metadata): void
    {
        $this->auditLogger?->log(
            AuditEvent::Communication,
            $outcome,
            null,
            $action,
            $resource,
            $metadata,
        );
    }
}
