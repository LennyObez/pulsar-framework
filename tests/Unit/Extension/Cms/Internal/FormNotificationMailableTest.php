<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Forms\FormSubmission;
use Pulsar\Extension\Cms\Internal\Forms\FormNotificationMailable;

use function assert;
use function is_string;

#[CoversClass(FormNotificationMailable::class)]
final class FormNotificationMailableTest extends TestCase
{
    #[Test]
    public function envelopeHasCorrectSubjectAndRecipients(): void
    {
        $submission = $this->buildSubmission(['name' => 'Alice']);
        $mailable = new FormNotificationMailable($submission, ['admin@example.com', 'support@example.com']);

        $envelope = $mailable->envelope();

        self::assertSame('New form submission', $envelope->subject);
        self::assertCount(2, $envelope->to);
        self::assertSame('admin@example.com', $envelope->to[0]->email);
        self::assertSame('support@example.com', $envelope->to[1]->email);
    }

    #[Test]
    public function contentHtmlContainsFormFields(): void
    {
        $submission = $this->buildSubmission([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'message' => 'Hello!',
        ]);

        $mailable = new FormNotificationMailable($submission, ['admin@example.com']);
        $content = $mailable->content();

        $html = $content->html;
        assert(is_string($html));
        self::assertStringContainsString('name', $html);
        self::assertStringContainsString('Alice', $html);
        self::assertStringContainsString('email', $html);
        self::assertStringContainsString('alice@example.com', $html);
        self::assertStringContainsString('message', $html);
        self::assertStringContainsString('Hello!', $html);
    }

    #[Test]
    public function contentTextContainsFormFields(): void
    {
        $submission = $this->buildSubmission(['name' => 'Bob']);
        $mailable = new FormNotificationMailable($submission, ['admin@example.com']);
        $content = $mailable->content();

        $text = $content->text;
        assert(is_string($text));
        self::assertStringContainsString('name: Bob', $text);
        self::assertStringContainsString('Submission ID:', $text);
    }

    #[Test]
    public function contentHtmlEscapesSpecialCharacters(): void
    {
        $submission = $this->buildSubmission([
            'comment' => '<script>alert("xss")</script>',
        ]);

        $mailable = new FormNotificationMailable($submission, ['admin@example.com']);
        $content = $mailable->content();

        $html = $content->html;
        assert(is_string($html));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function contentSkipsInternalFieldsPrefixedWithUnderscore(): void
    {
        $submission = $this->buildSubmission([
            'name' => 'Alice',
            '_token' => 'csrf-token-value',
            '_honeypot' => '',
        ]);

        $mailable = new FormNotificationMailable($submission, ['admin@example.com']);
        $content = $mailable->content();

        $html = $content->html;
        assert(is_string($html));
        $text = $content->text;
        assert(is_string($text));
        self::assertStringContainsString('Alice', $html);
        self::assertStringNotContainsString('_token', $html);
        self::assertStringNotContainsString('csrf-token-value', $html);
        self::assertStringNotContainsString('_honeypot', $html);
        self::assertStringNotContainsString('_token', $text);
    }

    #[Test]
    public function contentIncludesSubmissionId(): void
    {
        $submission = $this->buildSubmission(['name' => 'Test']);
        $mailable = new FormNotificationMailable($submission, ['admin@example.com']);
        $content = $mailable->content();

        $html = $content->html;
        assert(is_string($html));
        $text = $content->text;
        assert(is_string($text));
        self::assertStringContainsString($submission->id, $html);
        self::assertStringContainsString($submission->id, $text);
    }

    #[Test]
    public function contentIncludesSubmissionTimestamp(): void
    {
        $submission = $this->buildSubmission(['name' => 'Test']);
        $mailable = new FormNotificationMailable($submission, ['admin@example.com']);
        $content = $mailable->content();

        $html = $content->html;
        assert(is_string($html));
        $text = $content->text;
        assert(is_string($text));
        $formatted = $submission->submittedAt->format('Y-m-d H:i:s T');
        self::assertStringContainsString($formatted, $html);
        self::assertStringContainsString($formatted, $text);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildSubmission(array $data): FormSubmission
    {
        return new FormSubmission(
            id: 'sub-001',
            formBlockId: 'block-1',
            contentId: 'content-1',
            tenantId: null,
            data: $data,
            ipHash: 'iphash',
            userAgentHash: 'uahash',
            submittedAt: new DateTimeImmutable('2025-06-15T10:30:00+00:00'),
            evidenceHash: 'evidence-hash',
            isRead: false,
            isSpam: false,
            spamScore: 0.0,
            spamReason: null,
        );
    }
}
