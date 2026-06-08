<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\SwaggerUiController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

#[CoversClass(SwaggerUiController::class)]
final class SwaggerUiControllerTest extends TestCase
{
    /**
     * Fixed temp file path scoped to this test class -- not derived from user input.
     */
    private const string TEMP_SPEC_FILE = 'pulsar_swagger_ui_test_spec.json';

    private string $tempSpecPath;

    protected function setUp(): void
    {
        $this->tempSpecPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::TEMP_SPEC_FILE;
    }

    protected function tearDown(): void
    {
        // Hardcoded known-safe path — not derived from any external input.
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_swagger_ui_test_spec.json';

        if (file_exists($path)) {
            // @phpcs:ignore -- path is a test-owned constant, not user input
            unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }
    }

    private function createRequest(): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/api/docs',
            path: '/api/docs',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    private function writeSpecFile(string $content): void
    {
        file_put_contents($this->tempSpecPath, $content);
    }

    #[Test]
    public function uiReturnsHtmlResponse(): void
    {
        $controller = new SwaggerUiController(
            specPath: '/tmp/nonexistent-spec.json',
            specRoute: '/api/docs/openapi.json',
        );

        $response = $controller->ui();

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('swagger-ui', $body);
        self::assertStringContainsString('<!DOCTYPE html>', $body);
    }

    #[Test]
    public function uiIncludesSpecRouteInHtml(): void
    {
        $controller = new SwaggerUiController(
            specPath: '/tmp/nonexistent-spec.json',
            specRoute: '/custom/path/openapi.json',
        );

        $response = $controller->ui();

        self::assertStringContainsString('/custom/path/openapi.json', (string) $response->getBody());
    }

    #[Test]
    public function uiEscapesSpecRouteAgainstXss(): void
    {
        $controller = new SwaggerUiController(
            specPath: '/tmp/spec.json',
            specRoute: '/api/docs"><script>alert(1)</script><"',
        );

        $response = $controller->ui();
        $body = (string) $response->getBody();

        // The XSS payload should be escaped, not present as raw HTML
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    #[Test]
    public function specReturns404WhenFileDoesNotExist(): void
    {
        $controller = new SwaggerUiController(
            specPath: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nonexistent_pulsar_spec_' . bin2hex(random_bytes(8)) . '.json',
        );

        $response = $controller->spec();

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('not found', (string) $response->getBody());
    }

    #[Test]
    public function specReturnsContentWhenFileExists(): void
    {
        $this->writeSpecFile('{"openapi":"3.1.0"}');

        $controller = new SwaggerUiController(specPath: $this->tempSpecPath);
        $response = $controller->spec();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"openapi":"3.1.0"}', (string) $response->getBody());
    }

    #[Test]
    public function specResponseIncludesJsonContentType(): void
    {
        $this->writeSpecFile('{}');

        $controller = new SwaggerUiController(specPath: $this->tempSpecPath);
        $response = $controller->spec();

        self::assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type'),
        );
    }

    #[Test]
    public function specResponseIncludesCacheControlHeader(): void
    {
        $this->writeSpecFile('{}');

        $controller = new SwaggerUiController(specPath: $this->tempSpecPath);
        $response = $controller->spec();

        self::assertStringContainsString(
            'max-age=3600',
            $response->getHeaderLine('Cache-Control'),
        );
    }

    #[Test]
    public function defaultSpecRouteIsUsed(): void
    {
        $controller = new SwaggerUiController(specPath: '/tmp/spec.json');

        $response = $controller->ui();

        self::assertStringContainsString('/api/docs/openapi.json', (string) $response->getBody());
    }
}
