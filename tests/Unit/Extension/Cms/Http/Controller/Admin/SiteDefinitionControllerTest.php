<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Http\Controller\Admin\SiteDefinitionController;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SiteDefinitionController::class)]
final class SiteDefinitionControllerTest extends TestCase
{
    #[Test]
    public function form_returns_import_options(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest();

        $response = $controller->form($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('N.3', $body['schema']);
        self::assertTrue($body['supports_dry_run']);
    }

    #[Test]
    public function dry_run_returns_preview(): void
    {
        $result = new ImportResult(
            created: ['content' => 10, 'menus' => 2],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $importExport = $this->createStub(ImportExportServiceInterface::class);
        $importExport->method('importUnifiedFile')->willReturn($result);

        $controller = $this->createController(importExport: $importExport);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'json_content' => '{"site":{}}',
        ]);

        $response = $controller->dryRun($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['dry_run']);

        /** @var array<string, int> $created */
        $created = $body['created'];
        self::assertSame(10, $created['content']);
    }

    #[Test]
    public function dry_run_returns_400_when_no_content(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [], emptyBody: true);

        $response = $controller->dryRun($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function dry_run_returns_422_on_service_exception(): void
    {
        $importExport = $this->createStub(ImportExportServiceInterface::class);
        $importExport->method('importUnifiedFile')->willThrowException(new CmsException('Invalid schema'));

        $controller = $this->createController(importExport: $importExport);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'json_content' => '{"invalid":true}',
        ]);

        $response = $controller->dryRun($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function execute_returns_import_result(): void
    {
        $result = new ImportResult(
            created: ['content' => 5],
            updated: ['menus' => 1],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        $importExport = $this->createStub(ImportExportServiceInterface::class);
        $importExport->method('importUnifiedFile')->willReturn($result);

        $controller = $this->createController(importExport: $importExport);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['json_content' => '{"site":{}}'],
        );

        $response = $controller->execute($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('imported', $body['status']);
    }

    #[Test]
    public function execute_returns_400_when_no_content(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(stepUp: true, parsedBody: [], emptyBody: true);

        $response = $controller->execute($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function execute_returns_422_on_service_exception(): void
    {
        $importExport = $this->createStub(ImportExportServiceInterface::class);
        $importExport->method('importUnifiedFile')->willThrowException(new CmsException('Import failed'));

        $controller = $this->createController(importExport: $importExport);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['json_content' => '{"site":{}}'],
        );

        $response = $controller->execute($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function form_throws_when_unauthenticated(): void
    {
        $controller = $this->createController();

        $this->expectException(AuthenticationException::class);
        $controller->form($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function form_throws_when_authorization_denied(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->createController(gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->form($request);
    }

    private function createController(
        ?ImportExportServiceInterface $importExport = null,
        ?GateInterface $gate = null,
    ): SiteDefinitionController {
        return new SiteDefinitionController(
            importExport: $importExport ?? $this->createStub(ImportExportServiceInterface::class),
            gate: $gate,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(
        bool $stepUp = false,
        ?array $parsedBody = null,
        bool $emptyBody = false,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/site-definition');

        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn('');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getBody')->willReturn($stream);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => $stepUp,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/site-definition');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
