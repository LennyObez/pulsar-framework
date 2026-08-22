<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\AssetController;

#[CoversClass(AssetController::class)]
final class AssetControllerTest extends TestCase
{
    #[Test]
    public function serve_returns_400_for_empty_path(): void
    {
        $controller = new AssetController();
        $request = $this->createRequest('');

        $response = $controller->serve($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('invalidPathProvider')]
    public function serve_returns_400_for_path_traversal(string $path): void
    {
        $controller = new AssetController();
        $request = $this->createRequest($path);

        $response = $controller->serve($request);

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPathProvider(): iterable
    {
        yield 'dot-dot traversal' => ['../../../etc/passwd'];
        yield 'starts with dot' => ['.hidden'];
        yield 'contains slash' => ['sub/path.css'];
        yield 'contains backslash' => ['sub\\path.css'];
        yield 'unicode injection' => ["\u{202E}evil.css"];
        yield 'contains space' => ['file name.css'];
    }

    #[Test]
    public function serve_returns_404_for_nonexistent_asset(): void
    {
        $controller = new AssetController();
        $request = $this->createRequest('nonexistent-file-that-does-not-exist.css');

        $response = $controller->serve($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function serve_accepts_valid_filename_pattern(): void
    {
        // A valid filename that won't exist on disk should return 404 (not 400)
        $controller = new AssetController();
        $request = $this->createRequest('app.min.css');

        $response = $controller->serve($request);

        // Valid path format, so 404 (not 400) since file doesn't exist
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function serve_accepts_filenames_with_hyphens_and_dots(): void
    {
        $controller = new AssetController();
        $request = $this->createRequest('schema-table-view.min.js');

        $response = $controller->serve($request);

        self::assertSame(404, $response->getStatusCode());
    }

    private function createRequest(string $path): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'path' => $path,
                default => $default,
            },
        );

        return $request;
    }
}
