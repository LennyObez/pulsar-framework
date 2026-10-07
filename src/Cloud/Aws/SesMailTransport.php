<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Aws;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Mail\Address;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Message;
use Pulsar\Mail\TransportInterface;
use Throwable;

use function array_map;
use function explode;
use function hash;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function str_replace;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * AWS SES mail transport using raw HTTP with SigV4 signing.
 *
 * Sends email via the SES v2 API with support for templates,
 * bounce/complaint configuration sets, and email tags.
 */
#[Internal]
final readonly class SesMailTransport implements TransportInterface
{
    public function __construct(
        private AwsConfig $config,
        private CloudHttpClient $httpClient = new CloudHttpClient(),
        private ?string $configurationSetName = null,
    ) {}

    #[Override]
    public function send(Message $message): string
    {
        try {
            $payload = $this->buildPayload($message);
            $url = $this->buildUrl();
            $payloadHash = hash('sha256', $payload);

            $host = str_replace(['https://', 'http://'], '', $url);
            $host = explode('/', $host)[0];

            $headers = [
                'Host' => $host,
                'Content-Type' => 'application/json',
                'Content-Length' => (string) strlen($payload),
            ];

            $signer = $this->getSigner();
            $signedHeaders = $signer->sign('POST', '/v2/email/outbound-emails', '', $headers, $payloadHash);

            $response = $this->httpClient->request('POST', $url . '/v2/email/outbound-emails', $signedHeaders, $payload);

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

    #[Override]
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

        if ($this->configurationSetName !== null) {
            $payload['ConfigurationSetName'] = $this->configurationSetName;
        }

        /** @var list<array{Name: string, Value: string}> $tags */
        $tags = [];

        /** @var mixed $value */
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
        return $this->config->endpoint
            ?? sprintf('https://email.%s.amazonaws.com', $this->config->region);
    }

    private function getSigner(): AwsSigner
    {
        $credentials = $this->config->resolveCredentials();

        if ($credentials['access_key'] === '' || $credentials['secret_key'] === '') {
            throw MailException::driverError('ses', 'AWS credentials not configured for SES');
        }

        return new AwsSigner(
            $credentials['access_key'],
            $credentials['secret_key'],
            $this->config->region,
            'ses',
        );
    }
}
