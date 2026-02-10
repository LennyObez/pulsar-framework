<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Mail\Address;
use Pulsar\Mail\Attachment;
use Pulsar\Mail\Audit\DeliveryStatus;
use Pulsar\Mail\Audit\MailAuditor;
use Pulsar\Mail\Audit\MailAuditRecord;
use Pulsar\Mail\Message;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\MasterKey;

use function random_bytes;
use function sodium_bin2hex;
use function strlen;

#[CoversClass(MailAuditor::class)]
#[CoversClass(MailAuditRecord::class)]
final class MailAuditorTest extends TestCase
{
    #[Test]
    public function it_creates_record_without_hmac(): void
    {
        $auditor = new MailAuditor();

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Test',
            htmlBody: '<p>Body</p>',
        );

        $record = $auditor->record($message, 'msg-001', DeliveryStatus::Sent);

        self::assertSame('msg-001', $record->messageId);
        self::assertSame('anon', $record->recipientId);
        self::assertNull($record->bodyHash);
        self::assertSame([], $record->attachmentHashes);
        self::assertSame('email', $record->channel);
        self::assertSame(DeliveryStatus::Sent, $record->deliveryStatus);
    }

    #[Test]
    public function it_creates_record_with_hmac_hashing(): void
    {
        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('computeHex')
            ->willReturnCallback(fn(string $msg, string $key): string => 'hmac_' . strlen($msg));

        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));

        $auditor = new MailAuditor($hmac, $masterKey);

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Test',
            htmlBody: '<p>Body</p>',
        );

        $record = $auditor->record($message, 'msg-002', DeliveryStatus::Delivered);

        self::assertNotSame('anon', $record->recipientId);
        self::assertNotNull($record->bodyHash);
        self::assertSame(DeliveryStatus::Delivered, $record->deliveryStatus);
        self::assertNotNull($record->deliveredAt);
    }

    #[Test]
    public function it_pseudonymizes_recipients(): void
    {
        $hmac = $this->createMock(HmacInterface::class);
        $hmac->expects(self::atLeastOnce())
            ->method('computeHex')
            ->willReturn('pseudonymized_hash');

        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $auditor = new MailAuditor($hmac, $masterKey);

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Test',
        );

        $record = $auditor->record($message, 'msg-003', DeliveryStatus::Sent);

        self::assertSame('pseudonymized_hash', $record->recipientId);
        self::assertStringNotContainsString('user@test.com', $record->recipientId);
    }

    #[Test]
    public function it_hashes_attachments(): void
    {
        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('computeHex')
            ->willReturnCallback(fn(string $msg): string => 'hash_' . strlen($msg));

        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $auditor = new MailAuditor($hmac, $masterKey);

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Test',
            attachments: [
                new Attachment('file1.txt', 'content1', 'text/plain'),
                new Attachment('file2.txt', 'content2', 'text/plain'),
            ],
        );

        $record = $auditor->record($message, 'msg-004', DeliveryStatus::Sent);

        self::assertCount(2, $record->attachmentHashes);
    }

    #[Test]
    public function it_does_not_store_raw_content(): void
    {
        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('computeHex')->willReturn('hash_value');

        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $auditor = new MailAuditor($hmac, $masterKey);

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Sensitive Subject',
            htmlBody: '<p>Secret body content</p>',
        );

        $record = $auditor->record($message, 'msg-005', DeliveryStatus::Sent);
        $metadata = $record->toMetadata();

        $serialized = json_encode($metadata, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Secret body content', $serialized);
        self::assertStringNotContainsString('user@test.com', $serialized);
    }

    #[Test]
    public function it_returns_null_body_hash_for_empty_body(): void
    {
        $hmac = $this->createStub(HmacInterface::class);
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $auditor = new MailAuditor($hmac, $masterKey);

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'No Body',
        );

        $record = $auditor->record($message, 'msg-006', DeliveryStatus::Sent);

        self::assertNull($record->bodyHash);
    }

    #[Test]
    public function it_logs_to_audit_logger(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log');

        $auditor = new MailAuditor(auditLogger: $auditLogger);

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Audit Test',
        );

        $auditor->record($message, 'msg-007', DeliveryStatus::Sent);
    }

    #[Test]
    public function it_sets_optional_template_and_correlation_ids(): void
    {
        $auditor = new MailAuditor();

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Test',
        );

        $record = $auditor->record(
            $message,
            'msg-008',
            DeliveryStatus::Pending,
            templateId: 'welcome-email',
            correlationId: 'corr-123',
        );

        self::assertSame('welcome-email', $record->templateId);
        self::assertSame('corr-123', $record->correlationId);
    }
}
