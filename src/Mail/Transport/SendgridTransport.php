<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport;

use Pulsar\Api\Internal;
use Pulsar\Mail\Address;
use Pulsar\Mail\Attachment;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\Config\SendgridTransportConfig;
use Pulsar\Mail\TransportInterface;
use Throwable;

use function array_map;
use function base64_encode;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Sendgrid mail transport via the Sendgrid v3 API.
 *
 * Delegates HTTP calls to a MailHttpClientInterface, keeping this class
 * testable without network I/O.
 */
#[Internal]
final class SendgridTransport implements TransportInterface
{
    public function __construct(
        private readonly SendgridTransportConfig $config,
        private readonly MailHttpClientInterface $httpClient,
    ) {}

    public function send(Message $message): string
    {
        try {
            $payload = $this->buildPayload($message);
            $url = 'https://api.sendgrid.com/v3/mail/send';

            $headers = [
                'Authorization' => sprintf('Bearer %s', $this->config->apiKey),
                'Content-Type' => 'application/json',
            ];

            $response = $this->httpClient->request('POST', $url, $headers, $payload);

            if ($response->statusCode >= 400) {
                throw MailException::driverError('sendgrid', sprintf('HTTP %d: %s', $response->statusCode, $response->body));
            }

            // Sendgrid returns the message ID in the X-Message-Id header,
            // but since we only have the body, we extract from response if available.
            if ($response->body !== '') {
                /** @var array{x-message-id?: string} $decoded */
                $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

                return $decoded['x-message-id'] ?? '';
            }

            return '';
        } catch (MailException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw MailException::driverError('sendgrid', $e->getMessage(), $e);
        }
    }

    public function name(): string
    {
        return 'sendgrid';
    }

    private function buildPayload(Message $message): string
    {
        $to = array_map(
            static fn(Address $a): array => $a->name !== '' ? ['email' => $a->email, 'name' => $a->name] : ['email' => $a->email],
            $message->to,
        );

        /** @var array<string, mixed> $personalization */
        $personalization = ['to' => $to];

        if ($message->cc !== []) {
            $personalization['cc'] = array_map(
                static fn(Address $a): array => $a->name !== '' ? ['email' => $a->email, 'name' => $a->name] : ['email' => $a->email],
                $message->cc,
            );
        }

        if ($message->bcc !== []) {
            $personalization['bcc'] = array_map(
                static fn(Address $a): array => $a->name !== '' ? ['email' => $a->email, 'name' => $a->name] : ['email' => $a->email],
                $message->bcc,
            );
        }

        $from = ['email' => $message->from->email];
        if ($message->from->name !== '') {
            $from['name'] = $message->from->name;
        }

        /** @var list<array<string, string>> $content */
        $content = [];

        if ($message->textBody !== null) {
            $content[] = ['type' => 'text/plain', 'value' => $message->textBody];
        }

        if ($message->htmlBody !== null) {
            $content[] = ['type' => 'text/html', 'value' => $message->htmlBody];
        }

        /** @var array<string, mixed> $payload */
        $payload = [
            'personalizations' => [$personalization],
            'from' => $from,
            'subject' => $message->subject,
            'content' => $content,
        ];

        if ($message->replyTo !== null) {
            $payload['reply_to'] = ['email' => $message->replyTo->email];
        }

        if ($message->headers !== []) {
            $payload['headers'] = $message->headers;
        }

        if ($message->attachments !== []) {
            $payload['attachments'] = array_map(
                static fn(Attachment $a): array => [
                    'content' => base64_encode($a->content),
                    'filename' => $a->filename,
                    'type' => $a->mimeType,
                    'disposition' => $a->inline ? 'inline' : 'attachment',
                    'content_id' => $a->cid,
                ],
                $message->attachments,
            );
        }

        if ($message->metadata !== []) {
            $payload['custom_args'] = $message->metadata;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
