<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Security\ThreatDetection\InjectionAttemptDetector;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatResponse;

#[CoversClass(InjectionAttemptDetector::class)]
final class InjectionAttemptDetectorTest extends TestCase
{
    private InjectionAttemptDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new InjectionAttemptDetector();
    }

    #[DataProvider('sqlInjectionProvider')]
    public function testDetectsSqlInjection(string $input): void
    {
        $request = $this->createRequestWithQuery(['q' => $input]);
        $event = $this->detector->analyze($request);

        self::assertNotNull($event);
        self::assertSame(ThreatCategory::InjectionAttempt, $event->category);
        self::assertSame(ThreatResponse::Block, $event->recommendedAction);
        self::assertSame('sqli', $event->metadata['type']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sqlInjectionProvider(): iterable
    {
        yield 'union select' => ["' UNION SELECT * FROM users --"];
        yield 'drop table' => ["'; DROP TABLE users; --"];
        yield 'or 1=1' => ["' OR 1=1 --"];
        yield 'sleep injection' => ["'; SELECT SLEEP(5) --"];
        yield 'benchmark' => ["' AND BENCHMARK(1000000,SHA1('test'))"];
        yield 'waitfor delay' => ["'; WAITFOR DELAY '0:0:5' --"];
        yield 'delete from' => ["'; DELETE FROM sessions WHERE 1=1"];
        yield 'insert into' => ["'; INSERT INTO admin VALUES('hack','pass')"];
    }

    #[DataProvider('xssProvider')]
    public function testDetectsXss(string $input): void
    {
        $request = $this->createRequestWithQuery(['name' => $input]);
        $event = $this->detector->analyze($request);

        self::assertNotNull($event);
        self::assertSame('xss', $event->metadata['type']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function xssProvider(): iterable
    {
        yield 'script tag' => ['<script>alert("xss")</script>'];
        yield 'event handler' => ['<img onerror=alert(1) src=x>'];
        yield 'javascript uri' => ['<a href="javascript:alert(1)">'];
        yield 'iframe' => ['<iframe src="evil.com">'];
        yield 'svg onload' => ['<svg onload="alert(1)">'];
    }

    #[DataProvider('traversalProvider')]
    public function testDetectsPathTraversal(string $path): void
    {
        $request = $this->createRequestWithPath($path);
        $event = $this->detector->analyze($request);

        self::assertNotNull($event);
        self::assertSame('traversal', $event->metadata['type']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversalProvider(): iterable
    {
        yield 'dot-dot-slash' => ['/files/../../etc/passwd'];
        yield 'encoded traversal' => ['/files/%2e%2e/etc/passwd'];
        yield 'double-encoded' => ['/files/%252e%252e/etc/passwd'];
    }

    public function testCleanRequestReturnsNull(): void
    {
        $request = $this->createRequestWithQuery(['q' => 'hello world', 'page' => '1']);
        self::assertNull($this->detector->analyze($request));
    }

    public function testScansParsedBody(): void
    {
        $request = $this->createRequestWithBody(['comment' => '<script>alert(1)</script>']);
        $event = $this->detector->analyze($request);

        self::assertNotNull($event);
        self::assertSame('xss', $event->metadata['type']);
    }

    public function testRecordEventIsNoOp(): void
    {
        // Injection detector is stateless
        $this->detector->recordEvent('auth.failure', ['ip' => '10.0.0.1']);
        self::assertNull($this->detector->analyze($this->createRequestWithQuery([])));
    }

    public function testScansNestedArrayValues(): void
    {
        $request = $this->createRequestWithQuery([
            'data' => [
                'nested' => "' UNION SELECT * FROM users",
            ],
        ]);

        $event = $this->detector->analyze($request);
        self::assertNotNull($event);
    }

    /** @param array<string, mixed> $params */
    private function createRequestWithQuery(array $params): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/search');
        $uri->method('getQuery')->willReturn('');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.1']);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($params);
        $request->method('getParsedBody')->willReturn(null);

        return $request;
    }

    private function createRequestWithPath(string $path): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getQuery')->willReturn('');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.1']);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getParsedBody')->willReturn(null);

        return $request;
    }

    /** @param array<string, string> $body */
    private function createRequestWithBody(array $body): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/comments');
        $uri->method('getQuery')->willReturn('');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.1']);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }
}
