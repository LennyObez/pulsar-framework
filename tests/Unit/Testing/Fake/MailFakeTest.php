<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Fake;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Address;
use Pulsar\Mail\Content;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Mailable;
use Pulsar\Mail\Message;
use Pulsar\Testing\Fake\MailFake;

#[CoversClass(MailFake::class)]
final class MailFakeTest extends TestCase
{
    private MailFake $fake;

    protected function setUp(): void
    {
        $this->fake = new MailFake();
    }

    #[Test]
    public function send_records_mailable(): void
    {
        $mailable = $this->createTestMailable('user@example.com', 'Welcome');

        $messageId = $this->fake->send($mailable);

        self::assertNotEmpty($messageId);
        self::assertCount(1, $this->fake->sentMailables());
        self::assertCount(1, $this->fake->sentMessages());
    }

    #[Test]
    public function raw_records_message(): void
    {
        $message = new Message(
            from: new Address('noreply@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Raw test',
        );

        $messageId = $this->fake->raw($message);

        self::assertNotEmpty($messageId);
        self::assertCount(1, $this->fake->sentMessages());
    }

    #[Test]
    public function driver_returns_noop_transport(): void
    {
        $transport = $this->fake->driver();

        self::assertSame('fake', $transport->name());
    }

    #[Test]
    public function assert_sent_passes_when_mailable_exists(): void
    {
        $this->fake->send($this->createTestMailable('user@test.com', 'Hello'));

        $this->fake->assertSent(TestMailable::class);
    }

    #[Test]
    public function assert_sent_fails_when_mailable_missing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected mailable');

        $this->fake->assertSent(TestMailable::class);
    }

    #[Test]
    public function assert_sent_with_exact_count(): void
    {
        $this->fake->send($this->createTestMailable('a@test.com', 'A'));
        $this->fake->send($this->createTestMailable('b@test.com', 'B'));

        $this->fake->assertSent(TestMailable::class, 2);
    }

    #[Test]
    public function assert_sent_to_specific_recipient(): void
    {
        $this->fake->send($this->createTestMailable('alice@example.com', 'Hello Alice'));

        $this->fake->assertSentTo('alice@example.com', TestMailable::class);
    }

    #[Test]
    public function assert_sent_to_fails_when_wrong_recipient(): void
    {
        $this->fake->send($this->createTestMailable('alice@example.com', 'Hello'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('bob@example.com');

        $this->fake->assertSentTo('bob@example.com', TestMailable::class);
    }

    #[Test]
    public function assert_sent_with_callback(): void
    {
        $this->fake->send($this->createTestMailable('user@test.com', 'Important'));

        $this->fake->assertSentWith(
            TestMailable::class,
            static fn(Mailable $m): bool => $m instanceof TestMailable,
        );
    }

    #[Test]
    public function assert_not_sent_passes_when_absent(): void
    {
        $this->fake->assertNotSent(TestMailable::class);
    }

    #[Test]
    public function assert_nothing_sent_passes_when_empty(): void
    {
        $this->fake->assertNothingSent();
    }

    #[Test]
    public function assert_nothing_sent_fails_when_not_empty(): void
    {
        $this->fake->send($this->createTestMailable('test@test.com', 'Test'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected no mail to be sent');

        $this->fake->assertNothingSent();
    }

    #[Test]
    public function mailables_of_type_returns_filtered_list(): void
    {
        $this->fake->send($this->createTestMailable('a@test.com', 'A'));

        self::assertCount(1, $this->fake->mailablesOfType(TestMailable::class));
    }

    #[Test]
    public function reset_clears_all_state(): void
    {
        $this->fake->send($this->createTestMailable('a@test.com', 'A'));

        $this->fake->reset();

        self::assertCount(0, $this->fake->sentMailables());
        self::assertCount(0, $this->fake->sentMessages());
    }

    private function createTestMailable(string $to, string $subject): TestMailable
    {
        return new TestMailable($to, $subject);
    }
}

/**
 * @internal Test-only mailable
 */
final class TestMailable extends Mailable
{
    public function __construct(
        private readonly string $toEmail,
        private readonly string $subjectText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectText,
            to: [new Address($this->toEmail)],
        );
    }

    public function content(): Content
    {
        return new Content(
            html: '<p>Test content</p>',
            text: 'Test content',
        );
    }
}
