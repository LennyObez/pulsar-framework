<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Reminder;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Http\Client\HttpClientInterface;

use function base64_encode;
use function http_build_query;

/**
 * Twilio REST API SMS provider.
 *
 * Sends SMS via POST to api.twilio.com/2010-04-01/Accounts/{sid}/Messages.json
 * using HTTP Basic authentication.
 */
#[Internal]
final readonly class TwilioSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $accountSid,
        private string $authToken,
        private string $fromNumber,
    ) {}

    #[Override]
    public function send(string $to, string $body): void
    {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";

        $credentials = base64_encode("{$this->accountSid}:{$this->authToken}");

        $response = $this->httpClient->post($url, [
            'headers' => [
                'Authorization' => "Basic {$credentials}",
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'To' => $to,
                'From' => $this->fromNumber,
                'Body' => $body,
            ]),
        ]);

        if (!$response->ok()) {
            $errorBody = $response->body();
            throw BookingException::smsDeliveryFailed('twilio', "HTTP {$response->status()}: {$errorBody}");
        }
    }
}
