<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Domain\BookingConfig;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminBookingSettingsController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

#[CoversClass(AdminBookingSettingsController::class)]
final class AdminBookingSettingsControllerTest extends TestCase
{
    #[Test]
    public function showReturnsConfigAsJson(): void
    {
        $config = BookingConfig::fromArray([
            'deposit_required' => true,
            'deposit_percent' => 25,
            'min_advance_hours' => 12,
            'max_advance_days' => 60,
            'reminder_hours_before' => 48,
            'sms_reminder_enabled' => true,
            'email_reminder_enabled' => false,
            'google_calendar_enabled' => true,
            'google_calendar_id' => 'cal-123',
            'cancellation_policy_hours' => 8,
            'sms_provider' => 'vonage',
        ]);

        $controller = new AdminBookingSettingsController($config);

        $request = new Request(
            method: Method::GET,
            uri: '/admin/booking/settings',
            path: '/admin/booking/settings',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);

        self::assertTrue($body['deposit_required']);
        self::assertSame(25, $body['deposit_percent']);
        self::assertSame(12, $body['min_advance_hours']);
        self::assertSame(60, $body['max_advance_days']);
        self::assertSame(48, $body['reminder_hours_before']);
        self::assertTrue($body['sms_reminder_enabled']);
        self::assertFalse($body['email_reminder_enabled']);
        self::assertTrue($body['google_calendar_enabled']);
        self::assertSame('cal-123', $body['google_calendar_id']);
        self::assertSame(8, $body['cancellation_policy_hours']);
        self::assertSame('vonage', $body['sms_provider']);
    }

    #[Test]
    public function showReturnsDefaultConfigValues(): void
    {
        $config = BookingConfig::fromArray([]);
        $controller = new AdminBookingSettingsController($config);

        $request = new Request(
            method: Method::GET,
            uri: '/admin/booking/settings',
            path: '/admin/booking/settings',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $response = $controller->show($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['deposit_required']);
        self::assertSame(20, $body['deposit_percent']);
        self::assertSame('twilio', $body['sms_provider']);
    }
}
