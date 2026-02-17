<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Security\ThreatDetection\BruteForceDetector;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatResponse;

#[CoversClass(BruteForceDetector::class)]
final class BruteForceDetectorTest extends TestCase
{
    public function testNoThreatWhenBelowThreshold(): void
    {
        $detector = new BruteForceDetector(threshold: 5, windowSeconds: 600);
        $request = $this->createRequest('10.0.0.1');

        // Record 4 failures (below threshold of 5)
        for ($i = 0; $i < 4; $i++) {
            $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'user@example.com']);
        }

        self::assertNull($detector->analyze($request));
    }

    public function testDetectsThresholdExceeded(): void
    {
        $detector = new BruteForceDetector(threshold: 3, windowSeconds: 600);
        $request = $this->createRequest('10.0.0.1');

        for ($i = 0; $i < 3; $i++) {
            $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'user@example.com']);
        }

        $event = $detector->analyze($request);
        self::assertNotNull($event);
        self::assertSame(ThreatCategory::BruteForce, $event->category);
        self::assertSame(ThreatResponse::RateLimit, $event->recommendedAction);
        self::assertSame('10.0.0.1', $event->sourceIp);
    }

    public function testEscalatesToChallengeAt2xThreshold(): void
    {
        $detector = new BruteForceDetector(threshold: 3, windowSeconds: 600);
        $request = $this->createRequest('10.0.0.1');

        for ($i = 0; $i < 6; $i++) {
            $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'user@example.com']);
        }

        $event = $detector->analyze($request);
        self::assertNotNull($event);
        self::assertSame(ThreatResponse::Challenge, $event->recommendedAction);
    }

    public function testEscalatesToBlockAt3xThreshold(): void
    {
        $detector = new BruteForceDetector(threshold: 3, windowSeconds: 600);
        $request = $this->createRequest('10.0.0.1');

        for ($i = 0; $i < 9; $i++) {
            $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'user@example.com']);
        }

        $event = $detector->analyze($request);
        self::assertNotNull($event);
        self::assertSame(ThreatResponse::Block, $event->recommendedAction);
    }

    public function testIgnoresNonAuthEvents(): void
    {
        $detector = new BruteForceDetector(threshold: 1, windowSeconds: 600);

        $detector->recordEvent('page.view', ['ip' => '10.0.0.1']);
        $detector->recordEvent('api.call', ['ip' => '10.0.0.1']);

        $event = $detector->analyze($this->createRequest('10.0.0.1'));
        self::assertNull($event);
    }

    public function testTracksDifferentIpsIndependently(): void
    {
        $detector = new BruteForceDetector(threshold: 3, windowSeconds: 600);

        // 2 failures from each IP (below threshold)
        for ($i = 0; $i < 2; $i++) {
            $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'a']);
            $detector->recordEvent('auth.failure', ['ip' => '10.0.0.2', 'account' => 'b']);
        }

        self::assertNull($detector->analyze($this->createRequest('10.0.0.1')));
        self::assertNull($detector->analyze($this->createRequest('10.0.0.2')));
    }

    public function testAccountFailureCount(): void
    {
        $detector = new BruteForceDetector(threshold: 10, windowSeconds: 600);

        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'admin']);
        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.2', 'account' => 'admin']);
        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.3', 'account' => 'admin']);

        self::assertSame(3, $detector->accountFailureCount('admin'));
        self::assertSame(0, $detector->accountFailureCount('nonexistent'));
    }

    public function testConfidenceClampedToOne(): void
    {
        $detector = new BruteForceDetector(threshold: 1, windowSeconds: 600);

        for ($i = 0; $i < 100; $i++) {
            $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'user']);
        }

        $event = $detector->analyze($this->createRequest('10.0.0.1'));
        self::assertNotNull($event);
        self::assertLessThanOrEqual(1.0, $event->confidence);
    }

    public function testAccountFailuresPrunedOutsideWindow(): void
    {
        // Use a very short window so we can test pruning
        $detector = new BruteForceDetector(threshold: 10, windowSeconds: 1);

        // Record failures
        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'admin']);
        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'admin']);

        // Immediately, the account should have 2 failures
        self::assertSame(2, $detector->accountFailureCount('admin'));

        // Wait for the window to expire
        sleep(2);

        // Record a new event to trigger pruning
        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'admin']);

        // Only the new event should remain (old ones pruned)
        self::assertSame(1, $detector->accountFailureCount('admin'));
    }

    public function testAccountPruningDoesNotAffectOtherAccounts(): void
    {
        $detector = new BruteForceDetector(threshold: 10, windowSeconds: 600);

        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'alice']);
        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'alice']);
        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'bob']);

        self::assertSame(2, $detector->accountFailureCount('alice'));
        self::assertSame(1, $detector->accountFailureCount('bob'));
    }

    public function testEmptyAccountStringNotTracked(): void
    {
        $detector = new BruteForceDetector(threshold: 10, windowSeconds: 600);

        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => '']);

        self::assertSame(0, $detector->accountFailureCount(''));
    }

    private function createRequest(string $ip): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/login');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }
}
