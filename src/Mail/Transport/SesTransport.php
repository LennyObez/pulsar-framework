<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport;

use Pulsar\Api\Internal;
use Pulsar\Mail\Address;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\Config\SesTransportConfig;
use Pulsar\Mail\TransportInterface;
use Throwable;

use function array_map;
use function array_merge;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * AWS SES mail transport via the SES v2 API.
 *
 * Delegates HTTP calls to a MailHttpClientInterface, keeping this class
 * testable without network I/O.
 */
#[Internal]
final readonly class SesTransport implements TransportInterface
{
    public function __construct(
        private SesTransportConfig $config,
        private MailHttpClientInterface $httpClient,
    ) {}

    public function send(Message $message): string
    {
        try {
            $payload = $this->buildPayload($message);
            $url = $this->buildUrl();
            $headers = $this->buildHeaders();

            $response = $this->httpClient->request('POST', $url, $headers, $payload);

            if ($response->statusCode >= 400) {
                throw MailException::driverError('ses', sprintf('HTTP %d: %s', $response->statusCode, $response->body));
            }

            /** @var array{MessageId?: string} $decoded */
            $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

            return $decoded['MessageId'] ?? '';
        } catch (MailException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw MailException::driverError('ses', $e->getMessage(), $e);
        }
    }

    public function name(): string
    {
        return 'ses';
    }

    private function buildPayload(Message $message): string
    {
        $toAddresses = array_map(
            static fn(Address $a): string => $a->name !== '' ? sprintf('"%s" <%s>', $a->name, $a->email) : $a->email,
            $message->to,
        );

        $ccAddresses = array_map(
            static fn(Address $a): string => $a->name !== '' ? sprintf('"%s" <%s>', $a->name, $a->email) : $a->email,
            $message->cc,
        );

        $bccAddresses = array_map(
            static fn(Address $a): string => $a->name !== '' ? sprintf('"%s" <%s>', $a->name, $a->email) : $a->email,
            $message->bcc,
        );

        /** @var array<string, mixed> $destination */
        $destination = ['ToAddresses' => $toAddresses];

        if ($ccAddresses !== []) {
            $destination['CcAddresses'] = $ccAddresses;
        }

        if ($bccAddresses !== []) {
            $destination['BccAddresses'] = $bccAddresses;
        }

        $fromAddress = $message->from->name !== ''
            ? sprintf('"%s" <%s>', $message->from->name, $message->from->email)
            : $message->from->email;

        /** @var array<string, mixed> $body */
        $body = [];

        if ($message->htmlBody !== null) {
            $body['Html'] = ['Charset' => 'UTF-8', 'Data' => $message->htmlBody];
        }

        if ($message->textBody !== null) {
            $body['Text'] = ['Charset' => 'UTF-8', 'Data' => $message->textBody];
        }

        /** @var array<string, mixed> $payload */
        $payload = [
            'Content' => [
                'Simple' => [
                    'Body' => $body,
                    'Subject' => ['Charset' => 'UTF-8', 'Data' => $message->subject],
                ],
            ],
            'Destination' => $destination,
            'FromEmailAddress' => $fromAddress,
        ];

        if ($message->replyTo !== null) {
            $payload['ReplyToAddresses'] = [$message->replyTo->email];
        }

        /** @var array<string, string> $tags */
        $tags = [];

        foreach ($message->metadata as $key => $value) {
            $tags[] = ['Name' => $key, 'Value' => is_string($value) ? $value : (string) json_encode($value)];
        }

        if ($tags !== []) {
            $payload['EmailTags'] = $tags;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    private function buildUrl(): string
    {
        $endpoint = $this->config->endpoint
            ?? sprintf('https://email.%s.amazonaws.com', $this->config->region);

        return $endpoint . '/v2/email/outbound-emails';
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return array_merge(
            [
                'Content-Type' => 'application/json',
                'X-Amz-Target' => 'SimpleEmailService_v2.SendEmail',
            ],
            $this->buildAuthHeaders(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function buildAuthHeaders(): array
    {
        // AWS Signature V4 would be computed here by the HTTP client or a signing middleware.
        // We provide the access key and region so the HTTP client layer can sign the request.
        // The secret key is NOT passed in headers; it must be resolved by the signing middleware
        // via the config object or a credential provider to avoid leaking secrets in transit.
        return [
            'X-Pulsar-Ses-Region' => $this->config->region,
            'X-Pulsar-Ses-Access-Key' => $this->config->accessKey,
        ];
    }
}
