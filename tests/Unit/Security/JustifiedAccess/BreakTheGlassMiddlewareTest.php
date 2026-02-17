<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\JustifiedAccess;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Incident\IncidentInterface;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\JustifiedAccess\BreakTheGlassMiddleware;
use Pulsar\Security\JustifiedAccess\JustificationRecord;
use Pulsar\Security\JustifiedAccess\JustificationStoreInterface;
use Pulsar\Security\JustifiedAccess\JustifiedAccessConfig;

#[CoversClass(BreakTheGlassMiddleware::class)]
final class BreakTheGlassMiddlewareTest extends TestCase
{
    private JustificationStoreInterface&Stub $store;
    private AuditLoggerInterface&Stub $auditLogger;
    private IncidentReporterInterface&Stub $incidentReporter;

    protected function setUp(): void
    {
        $this->store = $this->createStub(JustificationStoreInterface::class);

        $auditEntry = $this->createStub(AuditEntry::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->auditLogger->method('log')->willReturn($auditEntry);

        $incident = $this->createStub(IncidentInterface::class);
        $this->incidentReporter = $this->createStub(IncidentReporterInterface::class);
        $this->incidentReporter->method('report')->willReturn($incident);
    }

    private function createRequest(
        string $breakTheGlassHeader = '',
        string $justification = '',
        string $actorId = 'doctor-1',
    ): ServerRequestInterface {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/patients/42/records');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.1']);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name) => match ($name) {
                'actor_id' => $actorId,
                'actor_name' => 'Dr. Smith',
                'actor_role' => 'physician',
                'resource_type' => 'patient_record',
                'resource_id' => 'patient-42',
                'session_id' => 'sess-abc',
                default => null,
            },
        );
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name) => match ($name) {
                'X-Break-The-Glass' => $breakTheGlassHeader,
                'X-Access-Justification' => $justification,
                default => '',
            },
        );
        // withAttribute returns a new request instance; stub it to return itself
        $request->method('withAttribute')->willReturn($request);

        return $request;
    }

    private function createHandler(): RequestHandlerInterface
    {
        $response = Response::json(['data' => 'patient records'], 200);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }

    public function testDisabledConfigPassesThrough(): void
    {
        $config = new JustifiedAccessConfig(enabled: false);
        $middleware = new BreakTheGlassMiddleware($config, $this->store, $this->auditLogger, $this->incidentReporter);

        $request = $this->createRequest(breakTheGlassHeader: 'true', justification: 'Emergency access needed');
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testNoBreakTheGlassHeaderPassesThrough(): void
    {
        $config = new JustifiedAccessConfig();
        $middleware = new BreakTheGlassMiddleware($config, $this->store, $this->auditLogger, $this->incidentReporter);

        $request = $this->createRequest(breakTheGlassHeader: '', justification: '');
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testNonTrueHeaderPassesThrough(): void
    {
        $config = new JustifiedAccessConfig();
        $middleware = new BreakTheGlassMiddleware($config, $this->store, $this->auditLogger, $this->incidentReporter);

        $request = $this->createRequest(breakTheGlassHeader: 'false', justification: '');
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testEmptyJustificationReturnsForbidden(): void
    {
        $config = new JustifiedAccessConfig();
        $middleware = new BreakTheGlassMiddleware($config, $this->store, $this->auditLogger, $this->incidentReporter);

        $request = $this->createRequest(breakTheGlassHeader: 'true', justification: '');
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('justification', $body);
    }

    public function testShortJustificationReturnsForbidden(): void
    {
        $config = new JustifiedAccessConfig(minJustificationLength: 10);
        $middleware = new BreakTheGlassMiddleware($config, $this->store, $this->auditLogger, $this->incidentReporter);

        $request = $this->createRequest(breakTheGlassHeader: 'true', justification: 'short');
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testValidBreakTheGlassStoresRecordAndProceedsWithRequest(): void
    {
        $store = $this->createMock(JustificationStoreInterface::class);
        $store->expects(self::once())->method('store');

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditEntry = $this->createStub(AuditEntry::class);
        $auditLogger->expects(self::once())->method('log')->willReturn($auditEntry);

        $incident = $this->createStub(IncidentInterface::class);
        $incidentReporter = $this->createMock(IncidentReporterInterface::class);
        $incidentReporter->expects(self::once())->method('report')->willReturn($incident);

        $config = new JustifiedAccessConfig(minJustificationLength: 10);
        $middleware = new BreakTheGlassMiddleware($config, $store, $auditLogger, $incidentReporter);

        $justification = 'Patient is in cardiac arrest, need immediate access to medical history';
        $request = $this->createRequest(breakTheGlassHeader: 'true', justification: $justification);
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testMissingActorIdDefaultsToUnknown(): void
    {
        $store = $this->createMock(JustificationStoreInterface::class);
        $store->expects(self::once())->method('store')
            ->with(self::callback(static fn(JustificationRecord $r) => $r->actorId === 'unknown'));

        $auditEntry = $this->createStub(AuditEntry::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturn($auditEntry);

        $incident = $this->createStub(IncidentInterface::class);
        $incidentReporter = $this->createStub(IncidentReporterInterface::class);
        $incidentReporter->method('report')->willReturn($incident);

        $config = new JustifiedAccessConfig(minJustificationLength: 10);
        $middleware = new BreakTheGlassMiddleware($config, $store, $auditLogger, $incidentReporter);

        // Create a request that returns null for all attributes
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/patients/42');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getServerParams')->willReturn([]);
        $request->method('getAttribute')->willReturn(null);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name) => match ($name) {
                'X-Break-The-Glass' => 'true',
                'X-Access-Justification' => 'Emergency access required for critical patient',
                default => '',
            },
        );
        $request->method('withAttribute')->willReturn($request);

        $handler = $this->createHandler();
        $middleware->process($request, $handler);
    }
}
