<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport;

use Pulsar\Api\Internal;
use Pulsar\Mail\Address;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\Config\MailgunTransportConfig;
use Pulsar\Mail\TransportInterface;
use Throwable;

use function array_map;
use function base64_encode;
use function http_build_query;
use function implode;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Mailgun mail transport via the Mailgun API.
 *
 * Delegates HTTP calls to a MailHttpClientInterface, keeping this class
 * testable without network I/O.
 */
#[Internal]
final readonly class MailgunTransport implements TransportInterface
{
    public function __construct(
        private MailgunTransportConfig $config,
        private MailHttpClientInterface $httpClient,
    ) {}

    public function send(Message $message): string
    {
        try {
            $payload = $this->buildPayload($message);
            $url = sprintf('%s/v3/%s/messages', $this->config->endpoint, $this->config->domain);

            $headers = [
                'Authorization' => sprintf('Basic %s', base64_encode('api:' . $this->config->apiKey)),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ];

            $response = $this->httpClient->request('POST', $url, $headers, $payload);

            if ($response->statusCode >= 400) {
                throw MailException::driverError('mailgun', sprintf('HTTP %d: %s', $response->statusCode, $response->body));
            }

            /** @var array{id?: string} $decoded */
            $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

            return $decoded['id'] ?? '';
        } catch (MailException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw MailException::driverError('mailgun', $e->getMessage(), $e);
        }
    }

    public function name(): string
    {
        return 'mailgun';
    }

    private function buildPayload(Message $message): string
    {
        $from = $message->from->name !== ''
            ? sprintf('%s <%s>', $message->from->name, $message->from->email)
            : $message->from->email;

        /** @var array<string, string> $params */
        $params = [
            'from' => $from,
            'to' => implode(', ', array_map(
                static fn(Address $a): string => $a->name !== '' ? sprintf('%s <%s>', $a->name, $a->email) : $a->email,
                $message->to,
            )),
            'subject' => $message->subject,
        ];

        if ($message->cc !== []) {
            $params['cc'] = implode(', ', array_map(
                static fn(Address $a): string => $a->name !== '' ? sprintf('%s <%s>', $a->name, $a->email) : $a->email,
                $message->cc,
            ));
        }

        if ($message->bcc !== []) {
            $params['bcc'] = implode(', ', array_map(
                static fn(Address $a): string => $a->name !== '' ? sprintf('%s <%s>', $a->name, $a->email) : $a->email,
                $message->bcc,
            ));
        }

        if ($message->replyTo !== null) {
            $params['h:Reply-To'] = $message->replyTo->email;
        }

        if ($message->htmlBody !== null) {
            $params['html'] = $message->htmlBody;
        }

        if ($message->textBody !== null) {
            $params['text'] = $message->textBody;
        }

        foreach ($message->headers as $key => $value) {
            $params['h:' . $key] = $value;
        }

        /** @var mixed $value */
        foreach ($message->metadata as $key => $value) {
            $params['v:' . $key] = is_string($value) ? $value : (string) json_encode($value);
        }

        return http_build_query($params);
    }
}
