<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport;

use Pulsar\Api\Internal;
use Pulsar\Mail\Address;
use Pulsar\Mail\Attachment;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\Config\SmtpTransportConfig;
use Pulsar\Mail\TransportInterface;
use Throwable;

use function base64_encode;
use function bin2hex;
use function chunk_split;
use function count;
use function fclose;
use function fgets;
use function fwrite;
use function implode;
use function in_array;
use function preg_replace;
use function quoted_printable_encode;
use function random_bytes;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function stream_context_create;
use function stream_set_timeout;
use function stream_socket_client;
use function substr;

/**
 * SMTP mail transport using PHP socket connections with TLS support.
 */
#[Internal]
final class SmtpTransport implements TransportInterface
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly SmtpTransportConfig $config,
    ) {}

    public function send(Message $message): string
    {
        try {
            $this->connect();
            $this->ehlo();
            $this->startTls();
            $this->authenticate();
            $this->mailFrom($message->from);
            $this->rcptTo($message);
            $messageId = $this->data($message);
            $this->quit();

            return $messageId;
        } catch (MailException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw MailException::driverError('smtp', $e->getMessage(), $e);
        } finally {
            $this->disconnect();
        }
    }

    public function name(): string
    {
        return 'smtp';
    }

    private function connect(): void
    {
        $protocol = $this->config->encryption === 'ssl' ? 'ssl' : 'tcp';
        $address = sprintf('%s://%s:%d', $protocol, $this->config->host, $this->config->port);

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $this->config->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw MailException::driverError('smtp', sprintf('Connection failed: [%d] %s', $errorCode, $errorMessage));
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->config->timeout);
        $this->readResponse('220');
    }

    private function ehlo(): void
    {
        $hostname = $this->config->ehloHostname ?? gethostname();

        $this->sendCommand(sprintf('EHLO %s', $hostname !== false ? $hostname : 'localhost'), '250');
    }

    private function startTls(): void
    {
        if ($this->config->encryption !== 'tls') {
            return;
        }

        $this->sendCommand('STARTTLS', '220');

        if ($this->socket === null) {
            throw MailException::driverError('smtp', 'Socket lost before STARTTLS');
        }

        $result = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);

        if ($result !== true) {
            throw MailException::driverError('smtp', 'STARTTLS handshake failed');
        }

        $this->ehlo();
    }

    private function authenticate(): void
    {
        if ($this->config->username === null || $this->config->password === null) {
            return;
        }

        if (!in_array($this->config->encryption, ['tls', 'ssl'], true)) {
            throw MailException::driverError('smtp', 'Cannot authenticate over unencrypted connection');
        }

        $this->sendCommand('AUTH LOGIN', '334');
        $this->sendCommand(base64_encode($this->config->username), '334');
        $this->sendCommand(base64_encode($this->config->password), '235');
    }

    private function mailFrom(Address $from): void
    {
        $this->sendCommand(sprintf('MAIL FROM:<%s>', self::sanitizeHeaderValue($from->email)), '250');
    }

    private function rcptTo(Message $message): void
    {
        $recipients = [...$message->to, ...$message->cc, ...$message->bcc];

        foreach ($recipients as $recipient) {
            $this->sendCommand(sprintf('RCPT TO:<%s>', self::sanitizeHeaderValue($recipient->email)), '250');
        }
    }

    private function data(Message $message): string
    {
        $this->sendCommand('DATA', '354');

        $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(16)), $this->config->host);
        $rawMessage = $this->buildRawMessage($message, $messageId);

        $this->sendRaw(self::applyTransparency($rawMessage) . "\r\n.\r\n");
        $this->readResponse('250');

        return $messageId;
    }

    /**
     * RFC 5321 §4.5.2: escape a line that begins with a period.
     *
     * The DATA phase ends at a line containing a single period, so any body line
     * starting with one has to be doubled or the message terminates early and the
     * rest of it is read by the server as SMTP commands — on a connection that is
     * already authenticated. Message bodies routinely carry user-supplied text, and
     * `quoted_printable_encode()` leaves both the period and the CRLF pairs intact.
     *
     * Line endings are normalised first. Without that, a body arriving with bare LFs
     * would put a period at the start of a line the CRLF-only rule cannot see.
     */
    private static function applyTransparency(string $rawMessage): string
    {
        $normalised = preg_replace('/\r\n|\r|\n/', "\r\n", $rawMessage);

        if ($normalised === null) {
            throw MailException::driverError('smtp', 'Could not normalise message line endings');
        }

        $stuffed = preg_replace('/^\./m', '..', $normalised);

        if ($stuffed === null) {
            throw MailException::driverError('smtp', 'Could not apply SMTP dot-stuffing to the message body');
        }

        return $stuffed;
    }

    private function quit(): void
    {
        $this->sendCommand('QUIT', '221');
    }

    private function buildRawMessage(Message $message, string $messageId): string
    {
        $boundary = bin2hex(random_bytes(16));
        $hasAttachments = count($message->attachments) > 0;

        $headers = [];
        $headers[] = sprintf('Message-ID: %s', $messageId);
        $headers[] = sprintf('From: %s', $this->formatAddress($message->from));
        $headers[] = sprintf('To: %s', implode(', ', $this->formatAddresses($message->to)));

        if ($message->cc !== []) {
            $headers[] = sprintf('Cc: %s', implode(', ', $this->formatAddresses($message->cc)));
        }

        $headers[] = sprintf('Subject: %s', self::sanitizeHeaderValue($message->subject));
        $headers[] = sprintf('X-Priority: %d', $message->priority);
        $headers[] = 'MIME-Version: 1.0';

        if ($message->replyTo !== null) {
            $headers[] = sprintf('Reply-To: %s', $this->formatAddress($message->replyTo));
        }

        foreach ($message->headers as $key => $value) {
            $headers[] = sprintf('%s: %s', self::sanitizeHeaderValue($key), self::sanitizeHeaderValue($value));
        }

        if ($hasAttachments) {
            $headers[] = sprintf('Content-Type: multipart/mixed; boundary="%s"', $boundary);
        } elseif ($message->htmlBody !== null && $message->textBody !== null) {
            $headers[] = sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundary);
        } elseif ($message->htmlBody !== null) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        }

        $parts = [implode("\r\n", $headers), ''];

        if ($hasAttachments || ($message->htmlBody !== null && $message->textBody !== null)) {
            if ($message->textBody !== null) {
                $parts[] = sprintf('--%s', $boundary);
                $parts[] = 'Content-Type: text/plain; charset=UTF-8';
                $parts[] = 'Content-Transfer-Encoding: quoted-printable';
                $parts[] = '';
                $parts[] = quoted_printable_encode($message->textBody);
            }

            if ($message->htmlBody !== null) {
                $parts[] = sprintf('--%s', $boundary);
                $parts[] = 'Content-Type: text/html; charset=UTF-8';
                $parts[] = 'Content-Transfer-Encoding: quoted-printable';
                $parts[] = '';
                $parts[] = quoted_printable_encode($message->htmlBody);
            }

            foreach ($message->attachments as $attachment) {
                $parts[] = $this->buildAttachmentPart($attachment, $boundary);
            }

            $parts[] = sprintf('--%s--', $boundary);
        } elseif ($message->htmlBody !== null) {
            $parts[] = quoted_printable_encode($message->htmlBody);
        } elseif ($message->textBody !== null) {
            $parts[] = quoted_printable_encode($message->textBody);
        }

        return implode("\r\n", $parts);
    }

    private function buildAttachmentPart(Attachment $attachment, string $boundary): string
    {
        $lines = [];
        $lines[] = sprintf('--%s', $boundary);

        $safeFilename = self::sanitizeHeaderValue(str_replace('"', '', $attachment->filename));
        $safeMimeType = self::sanitizeHeaderValue($attachment->mimeType);

        if ($attachment->inline && $attachment->cid !== null) {
            $safeCid = self::sanitizeHeaderValue($attachment->cid);
            $lines[] = sprintf('Content-Type: %s; name="%s"', $safeMimeType, $safeFilename);
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = sprintf('Content-ID: <%s>', $safeCid);
            $lines[] = 'Content-Disposition: inline';
        } else {
            $lines[] = sprintf('Content-Type: %s; name="%s"', $safeMimeType, $safeFilename);
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = sprintf('Content-Disposition: attachment; filename="%s"', $safeFilename);
        }

        $lines[] = '';
        $lines[] = chunk_split(base64_encode($attachment->content), 76, "\r\n");

        return implode("\r\n", $lines);
    }

    private function formatAddress(Address $address): string
    {
        $safeEmail = self::sanitizeHeaderValue($address->email);

        if ($address->name !== '') {
            $safeName = self::sanitizeHeaderValue(str_replace('"', '', $address->name));

            return sprintf('"%s" <%s>', $safeName, $safeEmail);
        }

        return sprintf('<%s>', $safeEmail);
    }

    /**
     * @param list<Address> $addresses
     * @return list<string>
     */
    private function formatAddresses(array $addresses): array
    {
        $result = [];

        foreach ($addresses as $address) {
            $result[] = $this->formatAddress($address);
        }

        return $result;
    }

    /**
     * Strip CR/LF characters to prevent SMTP header injection.
     */
    private static function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n"], '', $value);
    }

    private function sendCommand(string $command, string $expectedCode): void
    {
        $this->sendRaw($command . "\r\n");

        $this->readResponse($expectedCode);
    }

    private function sendRaw(string $data): void
    {
        if ($this->socket === null) {
            throw MailException::driverError('smtp', 'Not connected');
        }

        $result = @fwrite($this->socket, $data);

        if ($result === false) {
            throw MailException::driverError('smtp', 'Failed to write to socket');
        }
    }

    private function readResponse(string $expectedCode): void
    {
        if ($this->socket === null) {
            throw MailException::driverError('smtp', 'Not connected');
        }

        $response = '';

        while (true) {
            $line = @fgets($this->socket, 512);

            if ($line === false) {
                throw MailException::driverError('smtp', 'Failed to read from socket');
            }

            $response .= $line;

            // Multi-line responses have '-' after code; last line has ' '
            if (isset($line[3]) && $line[3] !== '-') {
                break;
            }
        }

        if (!str_starts_with($response, $expectedCode)) {
            throw MailException::driverError(
                'smtp',
                sprintf('Expected %s, got: %s', $expectedCode, substr($response, 0, 128)),
            );
        }
    }

    private function disconnect(): void
    {
        $socket = $this->socket;
        if ($socket !== null) {
            $this->socket = null;
            @fclose($socket);
        }
    }
}
