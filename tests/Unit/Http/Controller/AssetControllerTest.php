<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\Controller\AssetController;

#[CoversClass(AssetController::class)]
final class AssetControllerTest extends TestCase
{
    private function requestFor(string $path): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($path);

        return $request;
    }

    #[Test]
    public function servesUiAssetWithCorrectMimeType(): void
    {
        $response = new AssetController()->ui($this->requestFor('css/base.css'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/css', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    #[Test]
    public function servesCmsAsset(): void
    {
        $response = new AssetController()->cms($this->requestFor('cms-admin.css'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/css', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function rejectsDirectoryTraversal(): void
    {
        $response = new AssetController()->ui($this->requestFor('../../composer.json'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function rejectsDotPrefixedPath(): void
    {
        $response = new AssetController()->ui($this->requestFor('.env'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returnsNotFoundForMissingFile(): void
    {
        $response = new AssetController()->ui($this->requestFor('does-not-exist.css'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function assetRootsResolveToFrameworkDirectories(): void
    {
        self::assertStringEndsWith('/resources/ui', AssetController::uiAssetRoot());
        self::assertStringEndsWith('/extensions/cms/frontend/styles', AssetController::cmsAssetRoot());
    }
}
