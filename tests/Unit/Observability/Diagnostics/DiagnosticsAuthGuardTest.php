<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Observability\Diagnostics\DiagnosticsAuthGuard;

#[CoversClass(DiagnosticsAuthGuard::class)]
final class DiagnosticsAuthGuardTest extends TestCase
{
    #[Test]
    public function nullExpectedTokenRefusesEverything(): void
    {
        $guard = new DiagnosticsAuthGuard(null);
        $request = new ServerRequest(method: 'GET', uri: '/_pulsar/diagnostics');

        self::assertFalse($guard->isAuthorized($request));
    }

    #[Test]
    public function emptyExpectedTokenRefusesEverything(): void
    {
        $guard = new DiagnosticsAuthGuard('');
        $request = new ServerRequest(
            method: 'GET',
            uri: '/_pulsar/diagnostics',
            headers: ['Authorization' => 'Bearer '],
        );

        self::assertFalse($guard->isAuthorized($request));
    }

    #[Test]
    public function rejectsRequestWithoutAuthorizationHeader(): void
    {
        $guard = new DiagnosticsAuthGuard('s3cret-token');
        $request = new ServerRequest(method: 'GET', uri: '/_pulsar/diagnostics');

        self::assertFalse($guard->isAuthorized($request));
    }

    #[Test]
    public function rejectsBasicAuthHeader(): void
    {
        $guard = new DiagnosticsAuthGuard('s3cret-token');
        $request = new ServerRequest(
            method: 'GET',
            uri: '/_pulsar/diagnostics',
            headers: ['Authorization' => 'Basic dXNlcjpwYXNz'],
        );

        self::assertFalse($guard->isAuthorized($request));
    }

    #[Test]
    public function rejectsWrongToken(): void
    {
        $guard = new DiagnosticsAuthGuard('s3cret-token');
        $request = new ServerRequest(
            method: 'GET',
            uri: '/_pulsar/diagnostics',
            headers: ['Authorization' => 'Bearer wrong-token'],
        );

        self::assertFalse($guard->isAuthorized($request));
    }

    #[Test]
    public function acceptsCorrectBearerToken(): void
    {
        $guard = new DiagnosticsAuthGuard('s3cret-token');
        $request = new ServerRequest(
            method: 'GET',
            uri: '/_pulsar/diagnostics',
            headers: ['Authorization' => 'Bearer s3cret-token'],
        );

        self::assertTrue($guard->isAuthorized($request));
    }

    #[Test]
    public function acceptsCaseInsensitiveBearerKeyword(): void
    {
        // RFC 6750 § 2.1: the scheme name is case-insensitive.
        $guard = new DiagnosticsAuthGuard('s3cret-token');
        $request = new ServerRequest(
            method: 'GET',
            uri: '/_pulsar/diagnostics',
            headers: ['Authorization' => 'bearer s3cret-token'],
        );

        self::assertTrue($guard->isAuthorized($request));
    }

    #[Test]
    public function tokenComparisonIsExact(): void
    {
        // Prefix of the configured token must not be accepted as the
        // configured token — the guard uses hash_equals which compares
        // every byte (and length-mismatches return false).
        $guard = new DiagnosticsAuthGuard('s3cret-token');
        $request = new ServerRequest(
            method: 'GET',
            uri: '/_pulsar/diagnostics',
            headers: ['Authorization' => 'Bearer s3cret-toke'],
        );

        self::assertFalse($guard->isAuthorized($request));
    }
}
