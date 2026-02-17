<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Calendar;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Http\Client\HttpClientException;
use Pulsar\Http\Client\HttpClientInterface;

use function base64_encode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;

/**
 * Syncs appointments to Google Calendar via Google Calendar API v3.
 *
 * Uses a service account for server-to-server authentication via JWT.
 */
#[Internal]
final readonly class GoogleCalendarSync implements GoogleCalendarSyncInterface
{
    private const string API_BASE = 'https://www.googleapis.com/calendar/v3';
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private HttpClientInterface $httpClient,
        private GoogleCalendarConfig $config,
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function createEvent(Appointment $appointment): string
    {
        $accessToken = $this->getAccessToken();
        $url = self::API_BASE . "/calendars/{$this->config->calendarId}/events";

        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $this->buildEventPayload($appointment),
            ]);

            $response->throw();

            /** @var array{id: string} $data */
            $data = $response->json();

            $this->logger->info('Google Calendar event created', [
                'event_id' => $data['id'],
                'booking_number' => $appointment->bookingNumber,
            ]);

            return $data['id'];
        } catch (HttpClientException $e) {
            throw BookingException::calendarSyncFailed($e->getMessage());
        }
    }

    #[Override]
    public function updateEvent(string $eventId, Appointment $appointment): void
    {
        $accessToken = $this->getAccessToken();
        $url = self::API_BASE . "/calendars/{$this->config->calendarId}/events/{$eventId}";

        try {
            $response = $this->httpClient->put($url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $this->buildEventPayload($appointment),
            ]);

            $response->throw();

            $this->logger->info('Google Calendar event updated', [
                'event_id' => $eventId,
                'booking_number' => $appointment->bookingNumber,
            ]);
        } catch (HttpClientException $e) {
            throw BookingException::calendarSyncFailed($e->getMessage());
        }
    }

    #[Override]
    public function deleteEvent(string $eventId): void
    {
        $accessToken = $this->getAccessToken();
        $url = self::API_BASE . "/calendars/{$this->config->calendarId}/events/{$eventId}";

        try {
            $response = $this->httpClient->delete($url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                ],
            ]);

            $response->throw();

            $this->logger->info('Google Calendar event deleted', [
                'event_id' => $eventId,
            ]);
        } catch (HttpClientException $e) {
            throw BookingException::calendarSyncFailed($e->getMessage());
        }
    }

    /**
     * Build the Google Calendar event payload from an appointment.
     *
     * @return array<string, mixed>
     */
    private function buildEventPayload(Appointment $appointment): array
    {
        return [
            'summary' => "Booking {$appointment->bookingNumber} - {$appointment->customerName}",
            'description' => "Customer: {$appointment->customerName}\n"
                . "Email: {$appointment->customerEmail}\n"
                . "Phone: {$appointment->customerPhone}\n"
                . ($appointment->notes !== '' ? "Notes: {$appointment->notes}\n" : ''),
            'start' => [
                'dateTime' => $appointment->scheduledAt->format('c'),
                'timeZone' => $appointment->scheduledAt->getTimezone()->getName(),
            ],
            'end' => [
                'dateTime' => $appointment->endTime()->format('c'),
                'timeZone' => $appointment->scheduledAt->getTimezone()->getName(),
            ],
            'attendees' => [
                ['email' => $appointment->customerEmail],
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 60],
                    ['method' => 'popup', 'minutes' => 30],
                ],
            ],
        ];
    }

    /**
     * Obtain an access token using the service account JWT flow.
     *
     * @throws BookingException If authentication fails
     */
    private function getAccessToken(): string
    {
        if ($this->config->serviceAccountKeyPath === '') {
            throw BookingException::calendarSyncFailed('Service account key path not configured');
        }

        $keyFileContents = @file_get_contents($this->config->serviceAccountKeyPath);

        if ($keyFileContents === false) {
            throw BookingException::calendarSyncFailed('Cannot read service account key file');
        }

        /** @var array{client_email: string, private_key: string} $keyData */
        $keyData = json_decode($keyFileContents, true, 512, JSON_THROW_ON_ERROR);

        $now = time();
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = base64_encode(json_encode([
            'iss' => $keyData['client_email'],
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $signatureInput = "{$header}.{$claims}";
        $signature = '';
        $privateKey = openssl_pkey_get_private($keyData['private_key']);

        if ($privateKey === false) {
            throw BookingException::calendarSyncFailed('Invalid private key in service account file');
        }

        openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $jwt = "{$signatureInput}." . base64_encode($signature);

        try {
            $response = $this->httpClient->post(self::TOKEN_URL, [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]),
            ]);

            $response->throw();

            /** @var array{access_token: string} $tokenData */
            $tokenData = $response->json();

            return $tokenData['access_token'];
        } catch (HttpClientException $e) {
            throw BookingException::calendarSyncFailed("Token exchange failed: {$e->getMessage()}");
        }
    }
}
