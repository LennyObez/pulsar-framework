<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\TrustedProxy;
use Pulsar\Security\Session\HijackAction;
use Pulsar\Security\Session\HijackDetector;
use Pulsar\Security\Session\HijackPolicy;
use Pulsar\Security\Session\HijackVerdict;
use Pulsar\Security\Session\SessionMetadata;

use function time;

#[CoversClass(HijackDetector::class)]
#[CoversClass(HijackVerdict::class)]
final class HijackDetectorTest extends TestCase
{
    private function createMeta(string $ip = '10.0.0.1', string $ua = 'Mozilla/5.0'): SessionMetadata
    {
        return new SessionMetadata(
            createdAt: time(),
            lastActivity: time(),
            ipAddress: $ip,
            userAgent: $ua,
        );
    }

    private function createRequest(string $ip = '10.0.0.1', string $ua = 'Mozilla/5.0'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => $ua],
            serverParams: ['REMOTE_ADDR' => $ip],
        );
    }

    #[Test]
    public function allowsWhenNothingChanged(): void
    {
        $detector = new HijackDetector(new NullLogger());
        $verdict = $detector->analyze(
            $this->createRequest('10.0.0.1', 'Mozilla/5.0'),
            $this->createMeta('10.0.0.1', 'Mozilla/5.0'),
        );

        self::assertTrue($verdict->isOk());
        self::assertSame(HijackAction::Allow, $verdict->action);
    }

    #[Test]
    public function trustedProxyResolvesForwardedClientSoNoFalseHijack(): void
    {
        $detector = new HijackDetector(new NullLogger(), trustedProxy: new TrustedProxy(['10.0.0.0/8']));

        // Session captured the real client 203.0.113.5, which reaches us via the
        // trusted proxy 10.0.0.1. Without resolution the detector would compare
        // the proxy IP against the stored client IP and raise a false hijack.
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Mozilla/5.0', 'X-Forwarded-For' => '203.0.113.5'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $verdict = $detector->analyze($request, $this->createMeta('203.0.113.5', 'Mozilla/5.0'));

        self::assertTrue($verdict->isOk());
        self::assertSame(HijackAction::Allow, $verdict->action);
    }

    #[Test]
    public function trustedProxyStillDetectsRealClientIpChange(): void
    {
        $detector = new HijackDetector(
            new NullLogger(),
            ipChangePolicy: HijackPolicy::Invalidate,
            trustedProxy: new TrustedProxy(['10.0.0.0/8']),
        );

        // A different real client behind the same proxy must still be detected —
        // the security value raw REMOTE_ADDR misses (the proxy IP never changes).
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Mozilla/5.0', 'X-Forwarded-For' => '198.51.100.9'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $verdict = $detector->analyze($request, $this->createMeta('203.0.113.5', 'Mozilla/5.0'));

        self::assertTrue($verdict->requiresInvalidation());
    }

    #[Test]
    public function invalidatesOnUserAgentChange(): void
    {
        $detector = new HijackDetector(new NullLogger());
        $verdict = $detector->analyze(
            $this->createRequest('10.0.0.1', 'curl/7.68.0'),
            $this->createMeta('10.0.0.1', 'Mozilla/5.0'),
        );

        self::assertTrue($verdict->requiresInvalidation());
        self::assertStringContainsString('User-agent', $verdict->reason);
    }

    #[Test]
    public function warnsOnIpChangeWithWarnPolicy(): void
    {
        $detector = new HijackDetector(new NullLogger(), ipChangePolicy: HijackPolicy::Warn);
        $verdict = $detector->analyze(
            $this->createRequest('192.168.1.1', 'Mozilla/5.0'),
            $this->createMeta('10.0.0.1', 'Mozilla/5.0'),
        );

        self::assertSame(HijackAction::Warn, $verdict->action);
        self::assertFalse($verdict->requiresInvalidation());
        self::assertFalse($verdict->isOk());
    }

    #[Test]
    public function invalidatesOnIpChangeWithInvalidatePolicy(): void
    {
        $detector = new HijackDetector(new NullLogger(), ipChangePolicy: HijackPolicy::Invalidate);
        $verdict = $detector->analyze(
            $this->createRequest('192.168.1.1', 'Mozilla/5.0'),
            $this->createMeta('10.0.0.1', 'Mozilla/5.0'),
        );

        self::assertTrue($verdict->requiresInvalidation());
    }

    #[Test]
    public function challengesOnIpChangeWithChallengePolicy(): void
    {
        $detector = new HijackDetector(new NullLogger(), ipChangePolicy: HijackPolicy::Challenge);
        $verdict = $detector->analyze(
            $this->createRequest('192.168.1.1', 'Mozilla/5.0'),
            $this->createMeta('10.0.0.1', 'Mozilla/5.0'),
        );

        self::assertSame(HijackAction::Challenge, $verdict->action);
    }

    #[Test]
    public function userAgentChangeTakesPriorityOverIpChange(): void
    {
        $detector = new HijackDetector(new NullLogger(), ipChangePolicy: HijackPolicy::Warn);
        $verdict = $detector->analyze(
            $this->createRequest('192.168.1.1', 'curl/7.68.0'),
            $this->createMeta('10.0.0.1', 'Mozilla/5.0'),
        );

        // UA change always invalidates, even if IP policy is warn
        self::assertTrue($verdict->requiresInvalidation());
    }

    #[Test]
    public function skipsIpCheckWhenSessionIpIsEmpty(): void
    {
        $detector = new HijackDetector(new NullLogger(), ipChangePolicy: HijackPolicy::Invalidate);
        $verdict = $detector->analyze(
            $this->createRequest('192.168.1.1', 'Mozilla/5.0'),
            $this->createMeta('', 'Mozilla/5.0'),
        );

        self::assertTrue($verdict->isOk());
    }

    #[Test]
    public function skipsUaCheckWhenSessionUaIsEmpty(): void
    {
        $detector = new HijackDetector(new NullLogger());
        $verdict = $detector->analyze(
            $this->createRequest('10.0.0.1', 'curl/7.68.0'),
            $this->createMeta('10.0.0.1', ''),
        );

        self::assertTrue($verdict->isOk());
    }
}
