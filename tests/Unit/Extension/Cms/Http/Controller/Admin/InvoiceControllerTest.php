<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Http\Controller\Admin\InvoiceController;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(InvoiceController::class)]
final class InvoiceControllerTest extends TestCase
{
    #[Test]
    public function show_returns_invoice_html(): void
    {
        $invoiceService = $this->createStub(InvoiceServiceInterface::class);
        $invoiceService->method('getInvoiceHtml')->willReturn('<html><body>Invoice #123</body></html>');

        $controller = new InvoiceController(invoiceService: $invoiceService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'inv-1');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Invoice #123', (string) $response->getBody());
    }

    #[Test]
    public function show_returns_404_when_not_found(): void
    {
        $invoiceService = $this->createStub(InvoiceServiceInterface::class);
        $invoiceService->method('getInvoiceHtml')->willThrowException(new CmsException('Invoice not found'));

        $controller = new InvoiceController(invoiceService: $invoiceService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
    }

    #[Test]
    public function download_returns_html_attachment(): void
    {
        $invoiceService = $this->createStub(InvoiceServiceInterface::class);
        $invoiceService->method('getInvoiceHtml')->willReturn('<html><body>Invoice</body></html>');

        $controller = new InvoiceController(invoiceService: $invoiceService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->download($request, 'inv-1');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('invoice-inv-1', $response->getHeaderLine('Content-Disposition'));
    }

    #[Test]
    public function download_returns_404_when_not_found(): void
    {
        $invoiceService = $this->createStub(InvoiceServiceInterface::class);
        $invoiceService->method('getInvoiceHtml')->willThrowException(new CmsException('Invoice not found'));

        $controller = new InvoiceController(invoiceService: $invoiceService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->download($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function show_throws_when_unauthenticated(): void
    {
        $invoiceService = $this->createStub(InvoiceServiceInterface::class);
        $controller = new InvoiceController(invoiceService: $invoiceService);

        $this->expectException(AuthenticationException::class);
        $controller->show($this->createUnauthenticatedRequest(), 'inv-1');
    }

    #[Test]
    public function show_throws_when_authorization_denied(): void
    {
        $invoiceService = $this->createStub(InvoiceServiceInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new InvoiceController(invoiceService: $invoiceService, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->show($request, 'inv-1');
    }

    private function createAuthenticatedRequest(): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/invoices');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                default => $default,
            },
        );

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/invoices');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
