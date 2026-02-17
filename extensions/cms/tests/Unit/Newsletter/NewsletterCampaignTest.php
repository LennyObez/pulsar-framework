<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Newsletter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Newsletter\CampaignStatus;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaign;

#[CoversClass(NewsletterCampaign::class)]
final class NewsletterCampaignTest extends TestCase
{
    #[Test]
    public function create_returns_draft_campaign(): void
    {
        $campaign = NewsletterCampaign::create(
            id: 'camp-1',
            subject: 'Test Subject',
            bodyHtml: '<h1>Hello</h1>',
            locale: 'en',
        );

        self::assertSame('camp-1', $campaign->id);
        self::assertSame('Test Subject', $campaign->subject);
        self::assertSame('<h1>Hello</h1>', $campaign->bodyHtml);
        self::assertSame('en', $campaign->locale);
        self::assertSame(CampaignStatus::Draft, $campaign->status);
        self::assertNull($campaign->bodyText);
        self::assertNull($campaign->scheduledAt);
        self::assertNull($campaign->sentAt);
        self::assertSame(0, $campaign->recipientCount);
        self::assertSame(0, $campaign->openedCount);
        self::assertSame(0, $campaign->clickedCount);
        self::assertSame(0, $campaign->bouncedCount);
    }

    #[Test]
    public function create_with_all_optional_params(): void
    {
        $campaign = NewsletterCampaign::create(
            id: 'camp-2',
            subject: 'Subject',
            bodyHtml: '<p>Body</p>',
            locale: 'de',
            bodyText: 'Plain text body',
            tenantId: 'tenant-1',
            createdBy: 'user-1',
        );

        self::assertSame('Plain text body', $campaign->bodyText);
        self::assertSame('tenant-1', $campaign->tenantId);
        self::assertSame('user-1', $campaign->createdBy);
    }

    #[Test]
    public function update_changes_content_fields(): void
    {
        $campaign = NewsletterCampaign::create('c1', 'Old Subject', '<p>Old</p>', 'en');
        $updated = $campaign->update('New Subject', '<p>New</p>', 'Plain new', 'fr');

        self::assertSame('New Subject', $updated->subject);
        self::assertSame('<p>New</p>', $updated->bodyHtml);
        self::assertSame('Plain new', $updated->bodyText);
        self::assertSame('fr', $updated->locale);
        self::assertSame($campaign->id, $updated->id);
        self::assertSame($campaign->status, $updated->status);
    }

    #[Test]
    public function schedule_transitions_to_scheduled(): void
    {
        $campaign = NewsletterCampaign::create('c1', 'Subject', '<p>Body</p>', 'en');
        $sendAt = new DateTimeImmutable('+1 day');
        $scheduled = $campaign->schedule($sendAt);

        self::assertSame(CampaignStatus::Scheduled, $scheduled->status);
        self::assertSame($sendAt, $scheduled->scheduledAt);
    }

    #[Test]
    public function markSending_transitions_and_sets_recipient_count(): void
    {
        $campaign = NewsletterCampaign::create('c1', 'Subject', '<p>Body</p>', 'en');
        $sending = $campaign->markSending(500);

        self::assertSame(CampaignStatus::Sending, $sending->status);
        self::assertSame(500, $sending->recipientCount);
        self::assertNotNull($sending->sentAt);
    }

    #[Test]
    public function markSent_transitions_to_sent(): void
    {
        $campaign = NewsletterCampaign::create('c1', 'Subject', '<p>Body</p>', 'en')
            ->markSending(100);
        $sent = $campaign->markSent();

        self::assertSame(CampaignStatus::Sent, $sent->status);
    }

    #[Test]
    public function cancel_transitions_to_cancelled(): void
    {
        $campaign = NewsletterCampaign::create('c1', 'Subject', '<p>Body</p>', 'en');
        $cancelled = $campaign->cancel();

        self::assertSame(CampaignStatus::Cancelled, $cancelled->status);
    }

    #[Test]
    public function status_helper_methods(): void
    {
        $draft = NewsletterCampaign::create('c1', 'S', '<p>B</p>', 'en');
        self::assertTrue($draft->isDraft());
        self::assertFalse($draft->isScheduled());
        self::assertFalse($draft->isSent());
        self::assertFalse($draft->isCancelled());

        $scheduled = $draft->schedule(new DateTimeImmutable('+1 day'));
        self::assertTrue($scheduled->isScheduled());

        $sent = $draft->markSending(10)->markSent();
        self::assertTrue($sent->isSent());

        $cancelled = $draft->cancel();
        self::assertTrue($cancelled->isCancelled());
    }
}
