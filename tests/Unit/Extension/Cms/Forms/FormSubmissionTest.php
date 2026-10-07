<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Forms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\FormsConfig;
use Pulsar\Extension\Cms\Forms\Event\FormSubmitted;
use Pulsar\Extension\Cms\Forms\FormSubmission;

#[CoversClass(FormSubmission::class)]
#[CoversClass(FormSubmitted::class)]
#[CoversClass(FormsConfig::class)]
final class FormSubmissionTest extends TestCase
{
    // --- FormSubmission ---

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $now = new DateTimeImmutable();
        $submission = new FormSubmission(
            id: 'sub-1',
            formBlockId: 'block-1',
            contentId: 'page-1',
            tenantId: 'tenant-1',
            data: ['name' => 'Alice'],
            ipHash: 'iphash',
            userAgentHash: 'uahash',
            submittedAt: $now,
            evidenceHash: 'evhash',
            isRead: false,
            isSpam: false,
            spamScore: 0.0,
            spamReason: null,
        );

        self::assertSame('sub-1', $submission->id);
        self::assertSame('block-1', $submission->formBlockId);
        self::assertSame('page-1', $submission->contentId);
        self::assertSame('tenant-1', $submission->tenantId);
        self::assertSame(['name' => 'Alice'], $submission->data);
        self::assertSame('iphash', $submission->ipHash);
        self::assertSame('uahash', $submission->userAgentHash);
        self::assertSame($now, $submission->submittedAt);
        self::assertSame('evhash', $submission->evidenceHash);
        self::assertFalse($submission->isRead);
        self::assertFalse($submission->isSpam);
        self::assertSame(0.0, $submission->spamScore);
        self::assertNull($submission->spamReason);
    }

    #[Test]
    public function createFactoryProducesValidSubmission(): void
    {
        $submission = FormSubmission::create(
            formBlockId: 'block-2',
            contentId: 'page-2',
            data: ['email' => 'alice@example.com'],
            ipHash: 'ip123',
            userAgentHash: 'ua456',
        );

        self::assertSame('block-2', $submission->formBlockId);
        self::assertSame('page-2', $submission->contentId);
        self::assertSame(['email' => 'alice@example.com'], $submission->data);
        self::assertFalse($submission->isRead);
        self::assertFalse($submission->isSpam);
        self::assertSame(0.0, $submission->spamScore);
        self::assertNull($submission->spamReason);
        self::assertNull($submission->tenantId);

        // id is 32 hex chars (16 random bytes)
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $submission->id);

        // evidenceHash is a BLAKE2b hash as hex
        self::assertNotEmpty($submission->evidenceHash);
    }

    #[Test]
    public function createFactoryWithSpamData(): void
    {
        $submission = FormSubmission::create(
            formBlockId: 'block-3',
            contentId: 'page-3',
            data: ['body' => 'spam content'],
            ipHash: 'ip',
            userAgentHash: 'ua',
            spamScore: 8.5,
            spamReason: 'Detected as spam',
            isSpam: true,
            tenantId: 'tenant-x',
        );

        self::assertTrue($submission->isSpam);
        self::assertSame(8.5, $submission->spamScore);
        self::assertSame('Detected as spam', $submission->spamReason);
        self::assertSame('tenant-x', $submission->tenantId);
    }

    #[Test]
    public function createFactoryGeneratesDeterministicEvidenceHash(): void
    {
        $data = ['name' => 'Bob', 'message' => 'Hello'];

        $sub1 = FormSubmission::create('b1', 'c1', $data, 'ip', 'ua');
        $sub2 = FormSubmission::create('b2', 'c2', $data, 'ip2', 'ua2');

        // Same data = same evidence hash
        self::assertSame($sub1->evidenceHash, $sub2->evidenceHash);
    }

    #[Test]
    public function createFactoryProducesDifferentHashForDifferentData(): void
    {
        $sub1 = FormSubmission::create('b1', 'c1', ['a' => '1'], 'ip', 'ua');
        $sub2 = FormSubmission::create('b1', 'c1', ['a' => '2'], 'ip', 'ua');

        self::assertNotSame($sub1->evidenceHash, $sub2->evidenceHash);
    }

    // --- FormSubmitted event ---

    #[Test]
    public function formSubmittedWrapsSubmission(): void
    {
        $submission = FormSubmission::create(
            formBlockId: 'b',
            contentId: 'c',
            data: [],
            ipHash: 'i',
            userAgentHash: 'u',
        );

        $event = new FormSubmitted($submission);

        self::assertSame($submission, $event->formSubmission);
        self::assertSame('b', $event->formSubmission->formBlockId);
    }

    // --- FormsConfig ---

    #[Test]
    public function formsConfigDefaults(): void
    {
        $config = new FormsConfig();

        self::assertSame(5.0, $config->spamThreshold);
        self::assertSame(10, $config->rateLimitPerHour);
        self::assertSame([], $config->notificationRecipients);
        self::assertSame('_hp_field', $config->honeypotFieldName);
        self::assertSame('0000', $config->powDifficulty);
    }

    #[Test]
    public function formsConfigFromArrayDefaults(): void
    {
        $config = FormsConfig::fromArray([]);

        self::assertSame(5.0, $config->spamThreshold);
        self::assertSame(10, $config->rateLimitPerHour);
        self::assertSame('_hp_field', $config->honeypotFieldName);
    }

    #[Test]
    public function formsConfigFromArrayExplicitValues(): void
    {
        $config = FormsConfig::fromArray([
            'spam_threshold' => 3.0,
            'rate_limit_per_hour' => 5,
            'notification_recipients' => ['admin@example.com'],
            'honeypot_field_name' => '_secret',
            'pow_difficulty' => '00000',
        ]);

        self::assertSame(3.0, $config->spamThreshold);
        self::assertSame(5, $config->rateLimitPerHour);
        self::assertSame(['admin@example.com'], $config->notificationRecipients);
        self::assertSame('_secret', $config->honeypotFieldName);
        self::assertSame('00000', $config->powDifficulty);
    }
}
