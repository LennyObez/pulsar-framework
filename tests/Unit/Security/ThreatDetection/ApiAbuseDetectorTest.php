<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Security\ThreatDetection\ApiAbuseDetector;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatResponse;

#[CoversClass(ApiAbuseDetector::class)]
final class ApiAbuseDetectorTest extends TestCase
{
    public function testNoThreatBelowThreshold(): void
    {
        $detector = new ApiAbuseDetector(threshold: 100, windowSeconds: 60);

        for ($i = 0; $i < 5; $i++) {
            $detector->analyze($this->createRequest('10.0.0.1', "/api/resource/item-{$i}"));
        }

        // 6 total requests — well below threshold of 100, non-sequential paths
        self::assertNull($detector->analyze($this->createRequest('10.0.0.1', '/api/other')));
    }

    public function testDetectsVolumeAbuse(): void
    {
        $detector = new ApiAbuseDetector(threshold: 5, windowSeconds: 60);

        for ($i = 0; $i < 4; $i++) {
            $detector->analyze($this->createRequest('10.0.0.1', "/api/data/{$i}"));
        }

        // 5th request triggers volume threshold
        $event = $detector->analyze($this->createRequest('10.0.0.1', '/api/data/extra'));
        self::assertNotNull($event);
        self::assertSame(ThreatCategory::ApiAbuse, $event->category);
        self::assertSame(ThreatResponse::RateLimit, $event->recommendedAction);
        self::assertSame('volume', $event->metadata['type']);
    }

    public function testDetectsIdEnumeration(): void
    {
        $detector = new ApiAbuseDetector(threshold: 100, windowSeconds: 60);

        // Sequential IDs: /users/1, /users/2, ..., /users/6
        for ($i = 1; $i <= 6; $i++) {
            $result = $detector->analyze($this->createRequest('10.0.0.1', "/users/{$i}"));
        }

        self::assertNotNull($result);
        self::assertSame('enumeration', $result->metadata['type']);
    }

    public function testNonSequentialIdsNotFlagged(): void
    {
        $detector = new ApiAbuseDetector(threshold: 100, windowSeconds: 60);

        // Non-sequential IDs
        $ids = [42, 107, 983, 2, 555, 12];

        foreach ($ids as $id) {
            $result = $detector->analyze($this->createRequest('10.0.0.1', "/users/{$id}"));
        }

        // Should not be detected as enumeration (not sequential enough)
        self::assertNull($result);
    }

    public function testDifferentIpsTrackedIndependently(): void
    {
        $detector = new ApiAbuseDetector(threshold: 5, windowSeconds: 60);

        for ($i = 0; $i < 3; $i++) {
            $detector->analyze($this->createRequest('10.0.0.1', "/api/{$i}"));
            $detector->analyze($this->createRequest('10.0.0.2', "/api/{$i}"));
        }

        // Each IP has only 3+1=4 requests (below threshold 5)
        self::assertNull($detector->analyze($this->createRequest('10.0.0.1', '/api/extra')));
    }

    public function testRecordEventIsNoOp(): void
    {
        $detector = new ApiAbuseDetector(threshold: 100, windowSeconds: 60);

        $detector->recordEvent('some.event', ['ip' => '10.0.0.1']);

        // recordEvent doesn't affect analyze count — first analyze only records 1 request
        self::assertNull($detector->analyze($this->createRequest('10.0.0.1', '/api/first')));
    }

    private function createRequest(string $ip, string $path): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }
}
