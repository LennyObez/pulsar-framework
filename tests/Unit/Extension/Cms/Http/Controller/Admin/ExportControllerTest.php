<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\ExportController;
use Pulsar\Extension\Cms\Tools\ExportBundle;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ExportController::class)]
final class ExportControllerTest extends TestCase
{
    #[Test]
    public function form_returns_export_options(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest();

        $response = $controller->form($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<string> $scopes */
        $scopes = $body['scopes'];
        self::assertContains('content', $scopes);
        self::assertContains('taxonomies', $scopes);
        self::assertContains('menus', $scopes);
        self::assertFalse($body['supports_zip']);
    }

    #[Test]
    public function selective_form_includes_selective_flag(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest();

        $response = $controller->selectiveForm($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['selective']);
    }

    #[Test]
    public function download_returns_json_with_evidence_hash(): void
    {
        $bundle = new ExportBundle(
            data: ['content' => [['id' => 'c-1', 'title' => 'Test']]],
            evidenceHash: 'blake2b_evidence_hash',
            createdAt: new DateTimeImmutable('2026-03-10T12:00:00+00:00'),
            scope: ['content'],
            piiIncluded: false,
        );

        $importExport = $this->createStub(ImportExportServiceInterface::class);
        $importExport->method('exportBundle')->willReturn($bundle);

        $controller = $this->createController(importExport: $importExport);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'scope' => ['content'],
        ]);

        $response = $controller->download($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('blake2b_evidence_hash', $response->getHeaderLine('X-Evidence-Hash'));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $content */
        $content = $data['content'];
        self::assertCount(1, $content);
    }

    #[Test]
    public function download_returns_400_when_scope_empty(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'scope' => [],
        ]);

        $response = $controller->download($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('scope', $body['error']);
    }

    #[Test]
    public function download_returns_400_when_scope_not_provided(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: []);

        $response = $controller->download($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function download_returns_400_for_invalid_scope(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'scope' => ['invalid_type'],
        ]);

        $response = $controller->download($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function zip_download_returns_501_when_exporter_unavailable(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'scope' => ['content'],
        ]);

        $response = $controller->zipDownload($request);

        self::assertSame(501, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('not available', $body['error']);
    }

    #[Test]
    public function markdown_export_returns_markdown_content_type(): void
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findPublished')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 10000,
        ));

        $controller = $this->createController(contentRepo: $contentRepo);
        $request = $this->createAuthenticatedRequest(queryParams: ['locale' => 'en']);

        $response = $controller->markdownExport($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/markdown', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
    }

    #[Test]
    public function csv_export_returns_csv_content_type(): void
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findPublished')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 10000,
        ));

        $controller = $this->createController(contentRepo: $contentRepo);
        $request = $this->createAuthenticatedRequest(queryParams: ['locale' => 'en']);

        $response = $controller->csvExport($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
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
        ?ContentRepositoryInterface $contentRepo = null,
        ?GateInterface $gate = null,
    ): ExportController {
        return new ExportController(
            importExport: $importExport ?? $this->createStub(ImportExportServiceInterface::class),
            contentRepository: $contentRepo ?? $this->createStub(ContentRepositoryInterface::class),
            translationRepository: $this->createStub(ContentTranslationRepositoryInterface::class),
            blockRepository: $this->createStub(ContentBlockRepositoryInterface::class),
            gate: $gate,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, string> $queryParams
     */
    private function createAuthenticatedRequest(
        ?array $parsedBody = null,
        array $queryParams = [],
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/export');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => false,
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
        $uri->method('getPath')->willReturn('/admin/cms/export');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
