<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Reminder;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Http\Client\HttpClientInterface;

/**
 * Vonage (Nexmo) REST API SMS provider.
 *
 * Sends SMS via POST to rest.nexmo.com/sms/json using API key/secret.
 */
#[Internal]
final readonly class VonageSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
        private string $apiSecret,
        private string $fromNumber,
    ) {}

    #[Override]
    public function send(string $to, string $body): void
    {
        $response = $this->httpClient->post('https://rest.nexmo.com/sms/json', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'api_key' => $this->apiKey,
                'api_secret' => $this->apiSecret,
                'to' => $to,
                'from' => $this->fromNumber,
                'text' => $body,
            ],
        ]);

        if (!$response->ok()) {
            throw BookingException::smsDeliveryFailed('vonage', "HTTP {$response->status()}: {$response->body()}");
        }

        /** @var array{messages?: list<array{status: string, error-text?: string}>} $decoded */
        $decoded = $response->json();
        $messages = $decoded['messages'] ?? [];

        if ($messages !== [] && ($messages[0]['status'] ?? '0') !== '0') {
            $errorText = $messages[0]['error-text'] ?? 'Unknown error';
            throw BookingException::smsDeliveryFailed('vonage', $errorText);
        }
    }
}
