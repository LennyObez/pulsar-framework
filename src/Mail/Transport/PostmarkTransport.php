<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport;

use Pulsar\Api\Internal;
use Pulsar\Mail\Address;
use Pulsar\Mail\Attachment;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\Config\PostmarkTransportConfig;
use Pulsar\Mail\TransportInterface;
use Throwable;

use function array_keys;
use function array_map;
use function array_values;
use function base64_encode;
use function implode;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Postmark mail transport via the Postmark API.
 *
 * Delegates HTTP calls to a MailHttpClientInterface, keeping this class
 * testable without network I/O.
 */
#[Internal]
final readonly class PostmarkTransport implements TransportInterface
{
    public function __construct(
        private PostmarkTransportConfig $config,
        private MailHttpClientInterface $httpClient,
    ) {}

    public function send(Message $message): string
    {
        try {
            $payload = $this->buildPayload($message);
            $url = 'https://api.postmarkapp.com/email';

            $headers = [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Postmark-Server-Token' => $this->config->serverToken,
            ];

            $response = $this->httpClient->request('POST', $url, $headers, $payload);

            if ($response->statusCode >= 400) {
                throw MailException::driverError('postmark', sprintf('HTTP %d: %s', $response->statusCode, $response->body));
            }

            /** @var array{MessageID?: string} $decoded */
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

            return $decoded['MessageID'] ?? '';
        } catch (MailException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw MailException::driverError('postmark', $e->getMessage(), $e);
        }
    }

    public function name(): string
    {
        return 'postmark';
    }

    private function buildPayload(Message $message): string
    {
        $from = $message->from->name !== ''
            ? sprintf('%s <%s>', $message->from->name, $message->from->email)
            : $message->from->email;

        /** @var array<string, mixed> $payload */
        $payload = [
            'From' => $from,
            'To' => $this->formatRecipients($message->to),
            'Subject' => $message->subject,
        ];

        if ($message->cc !== []) {
            $payload['Cc'] = $this->formatRecipients($message->cc);
        }

        if ($message->bcc !== []) {
            $payload['Bcc'] = $this->formatRecipients($message->bcc);
        }

        if ($message->replyTo !== null) {
            $payload['ReplyTo'] = $message->replyTo->email;
        }

        if ($message->htmlBody !== null) {
            $payload['HtmlBody'] = $message->htmlBody;
        }

        if ($message->textBody !== null) {
            $payload['TextBody'] = $message->textBody;
        }

        if ($message->headers !== []) {
            $payload['Headers'] = array_map(
                static fn(string $key, string $value): array => ['Name' => $key, 'Value' => $value],
                array_keys($message->headers),
                array_values($message->headers),
            );
        }

        if ($message->attachments !== []) {
            $payload['Attachments'] = array_map(
                static fn(Attachment $a): array => [
                    'Name' => $a->filename,
                    'Content' => base64_encode($a->content),
                    'ContentType' => $a->mimeType,
                    'ContentID' => $a->cid,
                ],
                $message->attachments,
            );
        }

        if ($message->metadata !== []) {
            $payload['Metadata'] = $message->metadata;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<Address> $addresses
     */
    private function formatRecipients(array $addresses): string
    {
        return implode(', ', array_map(
            static fn(Address $a): string => $a->name !== '' ? sprintf('%s <%s>', $a->name, $a->email) : $a->email,
            $addresses,
        ));
    }
}
