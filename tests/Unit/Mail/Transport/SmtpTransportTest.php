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
use ReflectionProperty;

use function assert;
use function base64_encode;
use function chunk_split;
use function explode;
use function fclose;
use function fwrite;
use function is_string;
use function quoted_printable_encode;
use function rtrim;
use function str_repeat;
use function str_starts_with;
use function stream_get_contents;
use function stream_socket_pair;
use function strlen;

use const DIRECTORY_SEPARATOR;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_INET;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

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
        $this->expectExceptionMessageIsOrContains('Cannot authenticate over unencrypted connection');

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
        $this->expectExceptionMessageIsOrContains('Cannot authenticate over unencrypted connection');

        $this->callPrivateMethod($transport, 'authenticate');
    }

    /**
     * Regression: the guard must be an allowlist (tls/ssl only). A misspelled or
     * non-standard encryption value must NOT permit AUTH LOGIN over cleartext.
     */
    #[Test]
    #[DataProvider('nonEncryptedValueProvider')]
    public function authenticateThrowsForAnyNonAllowlistedEncryption(string $encryption): void
    {
        $config = new SmtpTransportConfig(
            username: 'user',
            password: 'pass',
            encryption: $encryption,
        );
        $transport = new SmtpTransport($config);

        $this->expectException(MailException::class);
        $this->expectExceptionMessageIsOrContains('Cannot authenticate over unencrypted connection');

        $this->callPrivateMethod($transport, 'authenticate');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonEncryptedValueProvider(): iterable
    {
        yield 'disabled' => ['disabled'];
        yield 'off' => ['off'];
        yield 'plain' => ['plain'];
        yield 'uppercase TLS' => ['TLS'];
        yield 'whitespace' => [' tls'];
    }

    // --- MIME body Content-Transfer-Encoding (RFC 2045 conformance) ---

    /**
     * Regression: the message declares quoted-printable, so an 8-bit body MUST be
     * quoted-printable encoded, not written verbatim.
     */
    #[Test]
    public function buildRawMessageQuotedPrintableEncodesNonAsciiTextBody(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Encoding',
            textBody: 'Café — déjà vu',
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<qp-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $raw);
        self::assertStringContainsString(quoted_printable_encode('Café — déjà vu'), $raw);
        // The raw 8-bit bytes must not appear unencoded in the body.
        self::assertStringNotContainsString('Café — déjà vu', $raw);
    }

    #[Test]
    public function buildRawMessageQuotedPrintableEncodesNonAsciiHtmlBody(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Encoding',
            htmlBody: '<p>Über grün</p>',
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<qp-html-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString(quoted_printable_encode('<p>Über grün</p>'), $raw);
        self::assertStringNotContainsString('<p>Über grün</p>', $raw);
    }

    #[Test]
    public function buildRawMessageQuotedPrintableEncodesMultipartParts(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Encoding',
            htmlBody: '<p>Größe</p>',
            textBody: 'Größe',
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<qp-multi-id@localhost>');
        assert(is_string($raw));

        self::assertStringContainsString(quoted_printable_encode('Größe'), $raw);
        self::assertStringContainsString(quoted_printable_encode('<p>Größe</p>'), $raw);
    }

    /**
     * Regression: RFC 2045 §6.8 requires base64 lines at most 76 chars. A large
     * attachment must be wrapped, not emitted as one unbroken line.
     */
    #[Test]
    public function buildRawMessageChunksBase64Attachment(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());
        $largeContent = str_repeat('A', 1000);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Large Attachment',
            textBody: 'See attached',
            attachments: [
                new Attachment(
                    filename: 'big.bin',
                    content: $largeContent,
                    mimeType: 'application/octet-stream',
                ),
            ],
        );

        $raw = $this->callPrivateMethod($transport, 'buildRawMessage', $message, '<chunk-id@localhost>');
        assert(is_string($raw));

        $expected = chunk_split(base64_encode($largeContent), 76, "\r\n");
        self::assertStringContainsString($expected, $raw);

        // No base64 run should exceed 76 characters between CRLF separators.
        foreach (explode("\r\n", $raw) as $line) {
            self::assertLessThanOrEqual(76, strlen($line));
        }
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

    // --- SMTP envelope command injection (protocol layer) ---

    /**
     * Regression: an Address whose ->email contains CR/LF must NOT be able to
     * inject additional SMTP commands via MAIL FROM / RCPT TO. The envelope
     * commands now pass through sanitizeHeaderValue(), collapsing the injected
     * bytes onto a single line.
     *
     * The transport writes a command then reads a response in lockstep. We use a
     * connected stream socket pair: the "server" end is pre-filled with the 250
     * responses the transport expects, so the synchronous read/write dance
     * completes without a real network or a second thread. After the call, the
     * server end holds exactly the bytes the transport wrote — the wire image.
     */
    #[Test]
    #[DataProvider('envelopeMethodProvider')]
    public function envelopeCommandsAreSanitizedAgainstInjection(
        string $method,
        Message $message,
        string $expectedWireFragment,
    ): void {
        // STREAM_PF_UNIX is unsupported by stream_socket_pair on Windows; INET works on both.
        $domain = DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX;
        $pair = @stream_socket_pair($domain, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair is unavailable on this platform');
        }

        [$transportEnd, $serverEnd] = $pair;

        // Pre-fill every response the method will read (one 250 per command).
        fwrite($serverEnd, "250 OK\r\n");
        fwrite($serverEnd, "250 OK\r\n");
        fwrite($serverEnd, "250 OK\r\n");

        $transport = new SmtpTransport(new SmtpTransportConfig(encryption: 'none'));

        $socketProperty = new ReflectionProperty(SmtpTransport::class, 'socket');
        $socketProperty->setValue($transport, $transportEnd);

        $arg = $method === 'mailFrom' ? $message->from : $message;
        $this->callPrivateMethod($transport, $method, $arg);

        // Read back what the transport wrote to the wire.
        fclose($transportEnd);
        $wire = stream_get_contents($serverEnd);
        fclose($serverEnd);
        assert(is_string($wire));

        // No embedded CRLF before the trailing terminator => no injected command.
        self::assertStringContainsString($expectedWireFragment, $wire);

        foreach (explode("\r\n", rtrim($wire, "\r\n")) as $line) {
            self::assertStringNotContainsString("\n", $line);
            self::assertFalse(
                str_starts_with($line, 'DATA') && $line !== 'DATA',
                'A second command leaked onto the wire: ' . $line,
            );
        }
    }

    /**
     * @return iterable<string, array{string, Message, string}>
     */
    public static function envelopeMethodProvider(): iterable
    {
        yield 'MAIL FROM injection' => [
            'mailFrom',
            new Message(
                from: new Address("attacker@evil.com\r\nRCPT TO:<victim@evil.com>"),
                to: [new Address('target@example.com')],
                subject: 'x',
                textBody: 'b',
            ),
            "MAIL FROM:<attacker@evil.comRCPT TO:<victim@evil.com>>\r\n",
        ];

        yield 'RCPT TO injection' => [
            'rcptTo',
            new Message(
                from: new Address('sender@example.com'),
                to: [new Address("target@example.com\r\nDATA\r\nInjected body")],
                subject: 'x',
                textBody: 'b',
            ),
            "RCPT TO:<target@example.comDATAInjected body>\r\n",
        ];
    }

    // --- name() ---

    #[Test]
    public function nameReturnsSmtp(): void
    {
        $transport = new SmtpTransport(new SmtpTransportConfig());

        self::assertSame('smtp', $transport->name());
    }

    // --- applyTransparency (RFC 5321 §4.5.2, SMTP command injection prevention) ---

    /**
     * The exploit the audit demonstrated: a contact form body ends the DATA phase
     * early, and everything after it is executed as SMTP commands on a connection
     * the framework has already authenticated. The result is an open relay sending
     * DKIM-signed, SPF-aligned mail from the victim's own domain.
     */
    #[Test]
    public function applyTransparencyDefeatsTheDataPhaseEscape(): void
    {
        $body = "hello\r\n.\r\nMAIL FROM:<ceo@victim.com>\r\nRCPT TO:<target@partner.com>\r\n"
            . "DATA\r\nFrom: CEO <ceo@victim.com>\r\nSubject: Wire transfer\r\n\r\npayload";

        $result = $this->callPrivateStaticMethod('applyTransparency', $body);
        assert(is_string($result));

        // The terminator the attacker planted is now a literal period.
        self::assertStringNotContainsString("\r\n.\r\n", $result);
        self::assertStringContainsString("\r\n..\r\n", $result);

        // And the injected commands are still there — as body text, which is the point.
        self::assertStringContainsString('MAIL FROM:<ceo@victim.com>', $result);
    }

    #[Test]
    #[DataProvider('transparencyProvider')]
    public function applyTransparencyEscapesLeadingPeriods(string $input, string $expected): void
    {
        $result = $this->callPrivateStaticMethod('applyTransparency', $input);
        assert(is_string($result));

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function transparencyProvider(): iterable
    {
        yield 'a line that is only a period' => ["a\r\n.\r\nb", "a\r\n..\r\nb"];
        yield 'a period opening a line' => ["a\r\n.hidden", "a\r\n..hidden"];
        yield 'a period opening the message' => ['.leading', '..leading'];
        yield 'consecutive periods' => ["a\r\n...\r\nb", "a\r\n....\r\nb"];

        // Bare LF is why normalisation comes first: a CRLF-only rule would not see
        // this period at the start of a line, and quoted_printable_encode preserves
        // whatever line endings the caller supplied.
        yield 'bare LF is normalised before escaping' => ["a\n.\nb", "a\r\n..\r\nb"];
        yield 'bare CR is normalised before escaping' => ["a\r.\rb", "a\r\n..\r\nb"];

        // A period anywhere but the start of a line is ordinary text.
        yield 'a period inside a line is untouched' => ["visit example.com\r\nnow", "visit example.com\r\nnow"];
        yield 'an empty message' => ['', ''];
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
