<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Newsletter;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriber;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriptionServiceInterface;
use Pulsar\Extension\Cms\Newsletter\SubscriberStatus;
use Pulsar\Extension\Cms\Support\SubscriberConfirmationMailable;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function bin2hex;
use function random_bytes;
use function sodium_crypto_generichash;

/**
 * Newsletter subscription service handling the full subscriber lifecycle.
 *
 * Implements double opt-in: subscribe generates a confirmation token,
 * hashes it with BLAKE2b, stores the hash, and sends a confirmation email
 * containing the raw token. The confirm method re-hashes the received token
 * and matches it against the stored hash.
 */
#[Internal(reason: 'Use NewsletterSubscriptionServiceInterface for public API')]
final readonly class NewsletterSubscriptionService implements NewsletterSubscriptionServiceInterface
{
    public function __construct(
        private NewsletterSubscriberRepositoryInterface $subscriberRepository,
        private MailManagerInterface $mailManager,
        private AuditLoggerInterface $auditLogger,
    ) {}

    public function subscribe(
        string $email,
        string $locale,
        string $source,
        string $ipAddress,
        ?string $tenantId = null,
    ): NewsletterSubscriber {
        $existing = $this->subscriberRepository->findByEmail($email, $tenantId);

        if ($existing !== null && $existing->isConfirmed()) {
            throw CmsException::subscriberAlreadyConfirmed($email);
        }

        // If there is an existing unsubscribed subscriber, resubscribe
        if ($existing !== null && $existing->isUnsubscribed()) {
            return $this->resubscribe($email, $tenantId);
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = bin2hex(sodium_crypto_generichash($token));

        // If there is an existing pending subscriber, resend confirmation
        if ($existing !== null && $existing->isPending()) {
            $updated = $existing->resubscribe($tokenHash);
            $this->subscriberRepository->save($updated);
            $this->sendConfirmationEmail($updated, $token);

            return $updated;
        }

        // New subscriber

        $subscriber = NewsletterSubscriber::create(
            id: UuidGenerator::v7(),
            email: $email,
            locale: $locale,
            ipAddress: $ipAddress,
            source: $source,
            tenantId: $tenantId,
            confirmTokenHash: $tokenHash,
        );

        $this->subscriberRepository->save($subscriber);
        $this->sendConfirmationEmail($subscriber, $token);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            null,
            'cms.newsletter.subscribed',
            "subscriber:$subscriber->id",
            ['email' => $email, 'source' => $source, 'locale' => $locale],
        );

        return $subscriber;
    }

    public function confirm(string $token): bool
    {
        $tokenHash = bin2hex(sodium_crypto_generichash($token));

        // Search across all pending subscribers for the matching token hash.
        // This is intentionally a sequential scan filtered by status to avoid
        // storing the token in a way that allows enumeration.
        $pending = $this->subscriberRepository->findByStatus(SubscriberStatus::Pending);

        foreach ($pending->items as $subscriber) {
            if ($subscriber->confirmTokenHash === $tokenHash) {
                $confirmed = $subscriber->confirm();
                $this->subscriberRepository->save($confirmed);

                $this->auditLogger->log(
                    AuditEvent::DataModification,
                    AuditOutcome::Success,
                    null,
                    'cms.newsletter.confirmed',
                    "subscriber:$subscriber->id",
                    ['email' => $subscriber->email],
                );

                return true;
            }
        }

        return false;
    }

    public function unsubscribe(string $subscriberId): bool
    {
        $subscriber = $this->subscriberRepository->findById($subscriberId);

        if ($subscriber === null) {
            throw CmsException::subscriberNotFound($subscriberId);
        }

        $unsubscribed = $subscriber->unsubscribe();
        $this->subscriberRepository->save($unsubscribed);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            null,
            'cms.newsletter.unsubscribed',
            "subscriber:$subscriberId",
            ['email' => $subscriber->email],
        );

        return true;
    }

    public function resubscribe(string $email, ?string $tenantId = null): NewsletterSubscriber
    {
        $subscriber = $this->subscriberRepository->findByEmail($email, $tenantId);

        if ($subscriber === null) {
            throw CmsException::subscriberNotFoundByEmail($email);
        }

        if (!$subscriber->isUnsubscribed()) {
            throw CmsException::subscriberNotUnsubscribed($email);
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = bin2hex(sodium_crypto_generichash($token));

        $resubscribed = $subscriber->resubscribe($tokenHash);
        $this->subscriberRepository->save($resubscribed);
        $this->sendConfirmationEmail($resubscribed, $token);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            null,
            'cms.newsletter.resubscribed',
            "subscriber:$subscriber->id",
            ['email' => $email],
        );

        return $resubscribed;
    }

    private function sendConfirmationEmail(NewsletterSubscriber $subscriber, string $token): void
    {
        $mailable = new SubscriberConfirmationMailable($subscriber, $token);
        $this->mailManager->send($mailable);
    }
}
