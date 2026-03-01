<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Address;
use Pulsar\Mail\Attachment;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\Config\SmtpTransportConfig;
use Pulsar\Mail\Transport\SmtpTransport;
use ReflectionMethod;

use function assert;
use function is_string;

#[CoversClass(SmtpTransport::class)]
final class SmtpTransportTest extends TestCase
{
    // --- sanitizeHeaderValue (SMTP header injection prevention) ---

    #[Test]
    #[DataProvider('headerInjectionProvider')]
    public function sanitizeHeaderValueStripsNewlines(string $input, string $expected): void
    {
        $result = $this->callPrivateStaticMethod('sanitizeHeaderValue', $input);
        assert(is_string($result));

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headerInjectionProvider(): iterable
    {
        yield 'clean value' => ['Hello World', 'Hello World'];
        yield 'CRLF injection' => ["Subject\r\nBcc: attacker@evil.com", 'SubjectBcc: attacker@evil.com'];
        yield 'LF only injection' => ["Subject\nBcc: attacker@evil.com", 'SubjectBcc: attacker@evil.com'];
        yield 'CR only injection' => ["Subject\rBcc: attacker@evil.com", 'SubjectBcc: attacker@evil.com'];
        yield 'multiple newlines' => ["a\r\nb\nc\rd", 'abcd'];
        yield 'empty string' => ['', ''];
    }

    // --- formatAddress ---

    #[Test]
    public function formatAddressEmailOnly(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $address = new Address('test@example.com');

        $result = $this->callPrivateMethod($transport, 'formatAddress', $address);
        assert(is_string($result));

        self::assertSame('<test@example.com>', $result);
    }

    #[Test]
    public function formatAddressWithName(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $address = new Address('test@example.com', 'John Doe');

        $result = $this->callPrivateMethod($transport, 'formatAddress', $address);
        assert(is_string($result));

        self::assertSame('"John Doe" <test@example.com>', $result);
    }

    #[Test]
    public function formatAddressStripsQuotesFromName(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $address = new Address('test@example.com', 'John "The Boss" Doe');

        $result = $this->callPrivateMethod($transport, 'formatAddress', $address);
        assert(is_string($result));

        self::assertSame('"John The Boss Doe" <test@example.com>', $result);
    }

    #[Test]
    public function formatAddressesList(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $addresses = [
            new Address('a@example.com', 'Alice'),
            new Address('b@example.com'),
        ];

        /** @var list<string> $result */
        $result = $this->callPrivateMethod($transport, 'formatAddresses', $addresses);

        self::assertCount(2, $result);
        self::assertSame('"Alice" <a@example.com>', $result[0]);
        self::assertSame('<b@example.com>', $result[1]);
    }

    // --- buildRawMessage ---

    #[Test]
    public function buildRawMessagePlainTextOnly(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com', 'Sender'),
            to: [new Address('recipient@example.com')],
            subject: 'Test Subject',
            textBody: 'Hello, world!',
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<test-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString('Message-ID: <test-id@localhost>', $raw);
        self::assertStringContainsString('From: "Sender" <sender@example.com>', $raw);
        self::assertStringContainsString('To: <recipient@example.com>', $raw);
        self::assertStringContainsString('Subject: Test Subject', $raw);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $raw);
        self::assertStringContainsString('Hello, world!', $raw);
        self::assertStringNotContainsString('boundary', $raw);
    }

    #[Test]
    public function buildRawMessageHtmlOnly(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'HTML Email',
            htmlBody: '<h1>Hello</h1>',
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<html-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $raw);
        self::assertStringContainsString('<h1>Hello</h1>', $raw);
    }

    #[Test]
    public function buildRawMessageMultipartAlternative(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Multipart',
            htmlBody: '<p>HTML</p>',
            textBody: 'Plain text',
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<multi-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString('multipart/alternative', $raw);
        self::assertStringContainsString('Plain text', $raw);
        self::assertStringContainsString('<p>HTML</p>', $raw);
    }

    #[Test]
    public function buildRawMessageWithAttachment(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'With Attachment',
            textBody: 'See attached',
            attachments: [
                new Attachment(
                    filename: 'report.pdf',
                    content: 'fake-pdf-content',
                    mimeType: 'application/pdf',
                ),
            ],
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<attach-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString('multipart/mixed', $raw);
        self::assertStringContainsString('Content-Disposition: attachment; filename="report.pdf"', $raw);
        self::assertStringContainsString('Content-Type: application/pdf; name="report.pdf"', $raw);
        self::assertStringContainsString('Content-Transfer-Encoding: base64', $raw);
        self::assertStringContainsString(base64_encode('fake-pdf-content'), $raw);
    }

    #[Test]
    public function buildRawMessageWithInlineAttachment(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Inline Image',
            htmlBody: '<img src="cid:logo-cid">',
            attachments: [
                new Attachment(
                    filename: 'logo.png',
                    content: 'fake-png',
                    mimeType: 'image/png',
                    inline: true,
                    cid: 'logo-cid',
                ),
            ],
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<inline-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString('Content-ID: <logo-cid>', $raw);
        self::assertStringContainsString('Content-Disposition: inline', $raw);
    }

    #[Test]
    public function buildRawMessageWithCcRecipients(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('to@example.com')],
            subject: 'CC Test',
            textBody: 'Content',
            cc: [new Address('cc1@example.com', 'CC One'), new Address('cc2@example.com')],
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<cc-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString('Cc: "CC One" <cc1@example.com>, <cc2@example.com>', $raw);
    }

    #[Test]
    public function buildRawMessageWithReplyTo(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('to@example.com')],
            subject: 'Reply-To Test',
            textBody: 'Content',
            replyTo: new Address('reply@example.com', 'Support'),
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<reply-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString('Reply-To: "Support" <reply@example.com>', $raw);
    }

    #[Test]
    public function buildRawMessageWithCustomHeaders(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('to@example.com')],
            subject: 'Headers Test',
            textBody: 'Content',
            headers: ['X-Custom' => 'custom-value', 'X-Campaign' => 'test-campaign'],
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<hdr-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString('X-Custom: custom-value', $raw);
        self::assertStringContainsString('X-Campaign: test-campaign', $raw);
    }

    #[Test]
    public function buildRawMessageWithPriority(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('to@example.com')],
            subject: 'Urgent',
            textBody: 'Content',
            priority: 1,
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<prio-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString('X-Priority: 1', $raw);
    }

    #[Test]
    public function buildRawMessageIncludesMimeVersion(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('to@example.com')],
            subject: 'MIME',
            textBody: 'Content',
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<mime-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString('MIME-Version: 1.0', $raw);
    }

    #[Test]
    public function buildRawMessageSanitizesSubjectAgainstInjection(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('to@example.com')],
            subject: "Injected\r\nBcc: evil@attacker.com",
            textBody: 'Content',
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<safe-id@localhost>');
        assert(is_string($raw));

        // CRLF is stripped so the injected Bcc never becomes its own header line
        self::assertStringNotContainsString("\r\nBcc:", $raw);
        self::assertStringNotContainsString("\nBcc:", $raw);
        // The subject line has the injection collapsed into a single line
        self::assertStringContainsString('Subject: InjectedBcc: evil@attacker.com', $raw);
    }

    // --- ehlo() hostname ---

    #[Test]
    public function ehloHostnameCanBeConfigured(): void
    {
        $config = new SmtpTransportConfig(ehloHostname: 'mail.example.com');

        self::assertSame('mail.example.com', $config->ehloHostname);
    }

    #[Test]
    public function ehloHostnameDefaultsToNull(): void
    {
        $config = new SmtpTransportConfig();

        self::assertNull($config->ehloHostname);
    }

    #[Test]
    public function ehloHostnameFromArray(): void
    {
        $config = SmtpTransportConfig::fromArray(['ehlo_hostname' => 'custom.host.com']);

        self::assertSame('custom.host.com', $config->ehloHostname);
    }

    #[Test]
    public function ehloHostnameFromArrayDefaultsToNull(): void
    {
        $config = SmtpTransportConfig::fromArray([]);

        self::assertNull($config->ehloHostname);
    }

    // --- authenticate() security ---

    #[Test]
    public function authenticateThrowsWhenEncryptionIsEmpty(): void
    {
        $config = new SmtpTransportConfig(
            username: 'user',
            password: 'pass',
            encryption: '',
        );
        $transport = new SmtpTransport($config);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Cannot authenticate over unencrypted connection');

        $this->callPrivateMethod($transport, 'authenticate');
    }

    #[Test]
    public function authenticateThrowsWhenEncryptionIsNone(): void
    {
        $config = new SmtpTransportConfig(
            username: 'user',
            password: 'pass',
            encryption: 'none',
        );
        $transport = new SmtpTransport($config);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Cannot authenticate over unencrypted connection');

        $this->callPrivateMethod($transport, 'authenticate');
    }

    #[Test]
    public function authenticateSkipsWhenNoCredentials(): void
    {
        $config = new SmtpTransportConfig(encryption: '');
        $transport = new SmtpTransport($config);

        // No credentials => should not throw, returns without action
        $this->callPrivateMethod($transport, 'authenticate');

        $this->addToAssertionCount(1); // authenticate() completes without exception when no credentials set
    }

    // --- name() ---

    #[Test]
    public function nameReturnsSmtp(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());

        self::assertSame('smtp', $transport->name());
    }

    private function callPrivateMethod(object $object, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($object, $method);

        return $ref->invoke($object, ...$args);
    }

    private function callPrivateStaticMethod(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(SmtpTransport::class, $method);

        return $ref->invoke(null, ...$args);
    }
}
