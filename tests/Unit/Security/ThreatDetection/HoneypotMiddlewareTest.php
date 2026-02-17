<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\ThreatDetection\HoneypotConfig;
use Pulsar\Security\ThreatDetection\HoneypotMiddleware;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatEvent;
use Pulsar\Security\ThreatDetection\ThreatEventDispatcherInterface;
use Pulsar\Security\ThreatDetection\ThreatResponse;

#[CoversClass(HoneypotMiddleware::class)]
#[CoversClass(HoneypotConfig::class)]
final class HoneypotMiddlewareTest extends TestCase
{
    private function createHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        return $handler;
    }

    #[Test]
    public function legitimate_request_passes_through(): void
    {
        $config = new HoneypotConfig(paths: ['/wp-login.php']);
        $middleware = new HoneypotMiddleware($config);

        $request = new ServerRequest(method: 'GET', uri: '/dashboard');
        $response = $middleware->process($request, $this->createHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function honeypotPathProvider(): iterable
    {
        yield 'wp-login' => ['/wp-login.php'];
        yield 'env file' => ['/.env'];
        yield 'phpinfo' => ['/phpinfo.php'];
    }

    #[Test]
    #[DataProvider('honeypotPathProvider')]
    public function honeypot_path_returns_403_when_block_enabled(string $path): void
    {
        $config = new HoneypotConfig(
            paths: ['/wp-login.php', '/.env', '/phpinfo.php'],
            blockIp: true,
        );
        $middleware = new HoneypotMiddleware($config);

        $request = new ServerRequest(
            method: 'GET',
            uri: $path,
            serverParams: ['REMOTE_ADDR' => '1.2.3.4'],
        );
        $response = $middleware->process($request, $this->createHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function honeypot_returns_404_when_block_disabled(): void
    {
        $config = new HoneypotConfig(
            paths: ['/wp-login.php'],
            blockIp: false,
        );
        $middleware = new HoneypotMiddleware($config);

        $request = new ServerRequest(method: 'GET', uri: '/wp-login.php');
        $response = $middleware->process($request, $this->createHandler());

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
    }

    #[Test]
    public function honeypot_matches_subpath(): void
    {
        $config = new HoneypotConfig(paths: ['/wp-admin']);
        $middleware = new HoneypotMiddleware($config);

        $request = new ServerRequest(method: 'GET', uri: '/wp-admin/options.php');
        $response = $middleware->process($request, $this->createHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatches_threat_event(): void
    {
        $dispatcher = $this->createMock(ThreatEventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn(ThreatEvent $e): bool => $e->category === ThreatCategory::Reconnaissance
                    && $e->confidence === 1.0
                    && $e->metadata['path'] === '/wp-login.php'));

        $config = new HoneypotConfig(paths: ['/wp-login.php']);
        $middleware = new HoneypotMiddleware($config, eventDispatcher: $dispatcher);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/wp-login.php',
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );
        $middleware->process($request, $this->createHandler());
    }

    #[Test]
    public function logs_to_audit_trail(): void
    {
        $auditEntry = $this->createStub(AuditEntry::class);
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                null,
                'honeypot.triggered',
                '/.env',
            )
            ->willReturn($auditEntry);

        $config = new HoneypotConfig(paths: ['/.env']);
        $middleware = new HoneypotMiddleware($config, auditLogger: $auditLogger);

        $request = new ServerRequest(method: 'GET', uri: '/.env');
        $middleware->process($request, $this->createHandler());
    }

    #[Test]
    public function default_paths_are_populated(): void
    {
        $config = new HoneypotConfig();

        self::assertNotEmpty($config->paths);
        self::assertContains('/wp-login.php', $config->paths);
        self::assertContains('/.env', $config->paths);
    }

    #[Test]
    public function from_array_parses_config(): void
    {
        $config = HoneypotConfig::fromArray([
            'paths' => ['/test'],
            'block_ip' => false,
            'response_action' => 'alert',
        ]);

        self::assertSame(['/test'], $config->paths);
        self::assertFalse($config->blockIp);
        self::assertSame(ThreatResponse::Alert, $config->responseAction);
    }
}
