<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Audit\DeliveryStatus;
use Pulsar\Mail\Audit\MailAuditRecord;

#[CoversClass(MailAuditRecord::class)]
final class MailAuditRecordTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $record = new MailAuditRecord(
            messageId: 'msg-a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            templateId: 'welcome-email-v2',
            recipientId: 'hmac:sha256:recipient@hospital.org',
            channel: 'smtp',
            sentAt: 1709827200,
            deliveredAt: 1709827205,
            deliveryStatus: DeliveryStatus::Delivered,
            correlationId: 'corr-98765432-fedc-ba09-8765-432109876543',
            bodyHash: 'hmac:sha256:body-content-hash',
            attachmentHashes: ['hmac:sha256:attachment-1'],
        );

        self::assertSame('msg-a1b2c3d4-e5f6-7890-abcd-ef1234567890', $record->messageId);
        self::assertSame('welcome-email-v2', $record->templateId);
        self::assertSame(DeliveryStatus::Delivered, $record->deliveryStatus);
        self::assertSame(1709827205, $record->deliveredAt);
    }

    #[Test]
    public function toMetadataReturnsCorrectStructure(): void
    {
        $record = new MailAuditRecord(
            messageId: 'msg-11111111-2222-3333-4444-555555555555',
            templateId: null,
            recipientId: 'hmac:patient-001',
            channel: 'ses',
            sentAt: 1709827200,
            deliveredAt: null,
            deliveryStatus: DeliveryStatus::Pending,
            correlationId: null,
            bodyHash: null,
            attachmentHashes: [],
        );

        $metadata = $record->toMetadata();

        self::assertSame('msg-11111111-2222-3333-4444-555555555555', $metadata['message_id']);
        self::assertNull($metadata['template_id']);
        self::assertSame('hmac:patient-001', $metadata['recipient_id']);
        self::assertSame('ses', $metadata['channel']);
        self::assertSame(1709827200, $metadata['sent_at']);
        self::assertNull($metadata['delivered_at']);
        self::assertSame(DeliveryStatus::Pending->value, $metadata['delivery_status']);
        self::assertNull($metadata['correlation_id']);
        self::assertNull($metadata['body_hash']);
        self::assertSame([], $metadata['attachment_hashes']);
    }
}
