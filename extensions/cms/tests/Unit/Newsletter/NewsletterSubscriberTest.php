<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Newsletter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriber;
use Pulsar\Extension\Cms\Newsletter\SubscriberStatus;

#[CoversClass(NewsletterSubscriber::class)]
final class NewsletterSubscriberTest extends TestCase
{
    #[Test]
    public function create_returns_pending_subscriber_with_hashed_ip(): void
    {
        $subscriber = NewsletterSubscriber::create(
            id: 'sub-1',
            email: 'test@example.com',
            locale: 'en',
            ipAddress: '127.0.0.1',
            source: 'form',
        );

        self::assertSame('sub-1', $subscriber->id);
        self::assertSame('test@example.com', $subscriber->email);
        self::assertSame('en', $subscriber->locale);
        self::assertSame('form', $subscriber->source);
        self::assertSame(SubscriberStatus::Pending, $subscriber->status);
        self::assertNull($subscriber->userId);
        self::assertNull($subscriber->confirmedAt);
        self::assertNull($subscriber->unsubscribedAt);
        self::assertNotEmpty($subscriber->ipAddressHash);
        // The IP should be hashed (BLAKE2b via sodium_crypto_generichash), not stored raw
        self::assertNotSame('127.0.0.1', $subscriber->ipAddressHash);
    }

    #[Test]
    public function create_with_optional_params(): void
    {
        $subscriber = NewsletterSubscriber::create(
            id: 'sub-2',
            email: 'user@example.com',
            locale: 'de',
            ipAddress: '192.168.1.1',
            source: 'api',
            userId: 'user-1',
            tenantId: 'tenant-1',
            confirmTokenHash: 'tokenhash',
        );

        self::assertSame('user-1', $subscriber->userId);
        self::assertSame('tenant-1', $subscriber->tenantId);
        self::assertSame('tokenhash', $subscriber->confirmTokenHash);
    }

    #[Test]
    public function confirm_transitions_to_confirmed_and_clears_token(): void
    {
        $subscriber = NewsletterSubscriber::create(
            id: 'sub-1',
            email: 'test@example.com',
            locale: 'en',
            ipAddress: '127.0.0.1',
            source: 'form',
            confirmTokenHash: 'token123',
        );

        $confirmed = $subscriber->confirm();

        self::assertSame(SubscriberStatus::Confirmed, $confirmed->status);
        self::assertNotNull($confirmed->confirmedAt);
        self::assertNull($confirmed->confirmTokenHash);
    }

    #[Test]
    public function unsubscribe_transitions_to_unsubscribed(): void
    {
        $subscriber = NewsletterSubscriber::create(
            id: 'sub-1',
            email: 'test@example.com',
            locale: 'en',
            ipAddress: '127.0.0.1',
            source: 'form',
        )->confirm();

        $unsubscribed = $subscriber->unsubscribe();

        self::assertSame(SubscriberStatus::Unsubscribed, $unsubscribed->status);
        self::assertNotNull($unsubscribed->unsubscribedAt);
    }

    #[Test]
    public function resubscribe_resets_to_pending_with_new_token(): void
    {
        $subscriber = NewsletterSubscriber::create(
            id: 'sub-1',
            email: 'test@example.com',
            locale: 'en',
            ipAddress: '127.0.0.1',
            source: 'form',
        )->confirm()->unsubscribe();

        $resubscribed = $subscriber->resubscribe('newtokenhash');

        self::assertSame(SubscriberStatus::Pending, $resubscribed->status);
        self::assertSame('newtokenhash', $resubscribed->confirmTokenHash);
        self::assertNull($resubscribed->confirmedAt);
        self::assertNull($resubscribed->unsubscribedAt);
    }

    #[Test]
    public function status_helper_methods(): void
    {
        $pending = NewsletterSubscriber::create(
            id: 's1',
            email: 'a@b.com',
            locale: 'en',
            ipAddress: '127.0.0.1',
            source: 'form',
        );

        self::assertTrue($pending->isPending());
        self::assertFalse($pending->isConfirmed());
        self::assertFalse($pending->isUnsubscribed());

        $confirmed = $pending->confirm();
        self::assertTrue($confirmed->isConfirmed());
        self::assertFalse($confirmed->isPending());

        $unsubscribed = $confirmed->unsubscribe();
        self::assertTrue($unsubscribed->isUnsubscribed());
        self::assertFalse($unsubscribed->isConfirmed());
    }
}
