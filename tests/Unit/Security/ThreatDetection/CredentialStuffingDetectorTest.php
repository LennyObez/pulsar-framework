<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Security\ThreatDetection\CredentialStuffingDetector;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatResponse;

#[CoversClass(CredentialStuffingDetector::class)]
final class CredentialStuffingDetectorTest extends TestCase
{
    public function testNoThreatBelowThreshold(): void
    {
        $detector = new CredentialStuffingDetector(threshold: 5, windowSeconds: 300);
        $request = $this->createRequest('10.0.0.1');

        // 4 unique usernames from same IP
        for ($i = 0; $i < 4; $i++) {
            $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => "user{$i}"]);
        }

        self::assertNull($detector->analyze($request));
    }

    public function testDetectsStuffingAtThreshold(): void
    {
        $detector = new CredentialStuffingDetector(threshold: 3, windowSeconds: 300);
        $request = $this->createRequest('10.0.0.1');

        for ($i = 0; $i < 3; $i++) {
            $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => "user{$i}"]);
        }

        $event = $detector->analyze($request);
        self::assertNotNull($event);
        self::assertSame(ThreatCategory::CredentialStuffing, $event->category);
        self::assertSame(ThreatResponse::Block, $event->recommendedAction);
    }

    public function testDuplicateUsernamesCountAsOne(): void
    {
        $detector = new CredentialStuffingDetector(threshold: 5, windowSeconds: 300);
        $request = $this->createRequest('10.0.0.1');

        // Same username repeated — should not trigger (only 1 unique)
        for ($i = 0; $i < 10; $i++) {
            $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'same_user']);
        }

        self::assertNull($detector->analyze($request));
    }

    public function testIgnoresNonAuthEvents(): void
    {
        $detector = new CredentialStuffingDetector(threshold: 1, windowSeconds: 300);

        $detector->recordEvent('page.view', ['ip' => '10.0.0.1', 'account' => 'user1']);

        self::assertNull($detector->analyze($this->createRequest('10.0.0.1')));
    }

    public function testIgnoresEmptyContext(): void
    {
        $detector = new CredentialStuffingDetector(threshold: 1, windowSeconds: 300);

        $detector->recordEvent('auth.failure', ['ip' => '', 'account' => '']);
        $detector->recordEvent('auth.failure', []);

        self::assertNull($detector->analyze($this->createRequest('10.0.0.1')));
    }

    public function testDifferentIpsTrackedIndependently(): void
    {
        $detector = new CredentialStuffingDetector(threshold: 3, windowSeconds: 300);

        // 2 unique usernames from each IP (below threshold)
        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'a']);
        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.1', 'account' => 'b']);
        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.2', 'account' => 'c']);
        $detector->recordEvent('auth.failure', ['ip' => '10.0.0.2', 'account' => 'd']);

        self::assertNull($detector->analyze($this->createRequest('10.0.0.1')));
        self::assertNull($detector->analyze($this->createRequest('10.0.0.2')));
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
