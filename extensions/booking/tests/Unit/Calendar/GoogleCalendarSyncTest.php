<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Calendar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Booking\Calendar\GoogleCalendarConfig;
use Pulsar\Extension\Booking\Calendar\GoogleCalendarSync;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;

use function base64_decode;
use function explode;
use function file_put_contents;
use function is_string;
use function json_decode;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_verify;
use function parse_str;
use function preg_match;
use function str_contains;
use function strtr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_RSA;

#[CoversClass(GoogleCalendarSync::class)]
final class GoogleCalendarSyncTest extends TestCase
{
    private HttpClientInterface&Stub $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createStub(HttpClientInterface::class);
    }

    #[Test]
    public function accessTokenAssertionIsAValidBase64UrlRs256Jws(): void
    {
        // Generate a real RSA service-account key and capture the JWT assertion
        // the client sends to Google's token endpoint. C2: segments must be
        // base64url (no '+', '/', '='), and the RS256 signature must verify over
        // the base64url-encoded "header.claims" — proving encoding happens before
        // signing, not merely after.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privatePem));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        /** @var string $publicPem */
        $publicPem = $details['key'];

        $keyPath = tempnam(sys_get_temp_dir(), 'gcal_key_');
        self::assertIsString($keyPath);
        file_put_contents($keyPath, (string) json_encode([
            'client_email' => 'svc@project.iam.gserviceaccount.com',
            'private_key' => $privatePem,
        ], JSON_THROW_ON_ERROR));

        $assertion = '';
        $this->httpClient->method('post')->willReturnCallback(
            function (string $url, array $options) use (&$assertion): HttpResponse {
                /** @var mixed $body */
                $body = $options['body'] ?? '';
                if (is_string($body) && str_contains($body, 'assertion=')) {
                    parse_str($body, $parsed);
                    $assertion = is_string($parsed['assertion'] ?? null) ? $parsed['assertion'] : '';

                    return HttpResponse::fromRaw(200, [], (string) json_encode(['access_token' => 'fake-token']));
                }

                return HttpResponse::fromRaw(200, [], (string) json_encode(['id' => 'evt-1']));
            },
        );

        $config = new GoogleCalendarConfig(calendarId: 'primary', serviceAccountKeyPath: $keyPath, enabled: true);
        $sync = new GoogleCalendarSync($this->httpClient, $config, new NullLogger());

        try {
            $sync->createEvent($this->makeAppointment());
        } finally {
            unlink($keyPath);
        }

        self::assertNotSame('', $assertion, 'the token exchange assertion was not captured');

        $segments = explode('.', $assertion);
        self::assertCount(3, $segments);

        foreach ($segments as $segment) {
            self::assertSame(
                1,
                preg_match('/^[A-Za-z0-9_-]+$/', $segment),
                'JWT segment is not base64url (contains +, /, or =)',
            );
        }

        [$header, $claims, $signature] = $segments;

        /** @var array{alg: string, typ: string} $headerData */
        $headerData = json_decode((string) base64_decode(strtr($header, '-_', '+/'), true), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('RS256', $headerData['alg']);
        self::assertSame('JWT', $headerData['typ']);

        /** @var array{iss: string, scope: string, aud: string} $claimsData */
        $claimsData = json_decode((string) base64_decode(strtr($claims, '-_', '+/'), true), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('svc@project.iam.gserviceaccount.com', $claimsData['iss']);
        self::assertStringContainsString('auth/calendar', $claimsData['scope']);

        // The signature must verify over the exact signed input — the base64url
        // "header.claims". A wrong (standard-base64) encoding before signing
        // would make this fail.
        $verified = openssl_verify(
            "{$header}.{$claims}",
            (string) base64_decode(strtr($signature, '-_', '+/'), true),
            $publicPem,
            OPENSSL_ALGO_SHA256,
        );
        self::assertSame(1, $verified, 'RS256 signature does not verify over the base64url header.claims');
    }

    public function testCreateEventThrowsWhenKeyPathEmpty(): void
    {
        $config = new GoogleCalendarConfig(
            calendarId: 'primary',
            serviceAccountKeyPath: '',
            enabled: true,
        );

        $sync = new GoogleCalendarSync(
            $this->httpClient,
            $config,
            new NullLogger(),
        );

        $appointment = $this->makeAppointment();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('not configured');

        $sync->createEvent($appointment);
    }

    public function testCreateEventThrowsWhenKeyFileNotReadable(): void
    {
        $config = new GoogleCalendarConfig(
            calendarId: 'primary',
            serviceAccountKeyPath: '/nonexistent/path/key.json',
            enabled: true,
        );

        $sync = new GoogleCalendarSync(
            $this->httpClient,
            $config,
            new NullLogger(),
        );

        $appointment = $this->makeAppointment();

        $this->expectException(BookingException::class);
        // A nonexistent path fails the is_file/is_readable guard; the "Cannot
        // read" message is only for a readable file whose contents fail to load.
        $this->expectExceptionMessage('missing or not readable');

        $sync->createEvent($appointment);
    }

    public function testGoogleCalendarConfigFromArray(): void
    {
        $config = GoogleCalendarConfig::fromArray([
            'calendar_id' => 'my-calendar',
            'service_account_key_path' => '/path/key.json',
            'enabled' => true,
        ]);

        self::assertSame('my-calendar', $config->calendarId);
        self::assertSame('/path/key.json', $config->serviceAccountKeyPath);
        self::assertTrue($config->enabled);
    }

    public function testGoogleCalendarConfigFromArrayDefaults(): void
    {
        $config = GoogleCalendarConfig::fromArray([]);

        self::assertSame('', $config->calendarId);
        self::assertSame('', $config->serviceAccountKeyPath);
        self::assertFalse($config->enabled);
    }

    private function makeAppointment(): \Pulsar\Extension\Booking\Domain\Appointment
    {
        return new \Pulsar\Extension\Booking\Domain\Appointment(
            id: 'apt-001',
            bookingNumber: 'BKG-2026-000001',
            serviceId: 'svc-001',
            customerId: 'cust-001',
            customerName: 'Jane Doe',
            customerEmail: 'jane@example.com',
            customerPhone: '+1234567890',
            status: \Pulsar\Extension\Booking\Domain\AppointmentStatus::Confirmed,
            scheduledAt: new DateTimeImmutable('2026-04-15 10:00:00'),
            duration: 60,
            depositAmount: null,
            depositPaid: false,
            notes: 'Test',
            reminderSent: false,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }
}
