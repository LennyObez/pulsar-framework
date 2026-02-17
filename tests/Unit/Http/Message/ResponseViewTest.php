<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\CompiledTemplate;
use Pulsar\View\Engine\TemplateEngineInterface;
use RuntimeException;

#[CoversClass(Response::class)]
final class ResponseViewTest extends TestCase
{
    protected function tearDown(): void
    {
        Response::clearTemplateEngine();
    }

    // ── Explicit engine overload ────────────────────────────────────────

    #[Test]
    public function viewWithExplicitEngineRendersTemplateAndReturnsHtml(): void
    {
        // Arrange
        $engine = $this->createStubEngine('<h1>Hello, Alice!</h1>');

        // Act
        $response = Response::view($engine, 'welcome', ['name' => 'Alice']);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<h1>Hello, Alice!</h1>', (string) $response->getBody());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function viewWithExplicitEngineAcceptsCustomStatusCode(): void
    {
        // Arrange
        $engine = $this->createStubEngine('<p>Not Found</p>');

        // Act
        $response = Response::view($engine, 'errors.404', [], 404);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('<p>Not Found</p>', (string) $response->getBody());
    }

    #[Test]
    public function viewWithExplicitEngineAcceptsCustomHeaders(): void
    {
        // Arrange
        $engine = $this->createStubEngine('<p>cached</p>');

        // Act
        $response = Response::view(
            $engine,
            'page',
            ['key' => 'val'],
            200,
            ['X-Custom-Header' => 'custom-value', 'Cache-Control' => 'public, max-age=3600'],
        );

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('custom-value', $response->getHeaderLine('X-Custom-Header'));
        self::assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function viewWithExplicitEnginePassesDataToRender(): void
    {
        // Arrange
        $spy = new TemplateEngineSpy();

        // Act
        $response = Response::view($spy, 'dashboard.index', ['user' => 'Bob', 'role' => 'admin']);

        // Assert
        self::assertSame('rendered', (string) $response->getBody());
        self::assertSame('dashboard.index', $spy->lastTemplate);
        self::assertSame(['user' => 'Bob', 'role' => 'admin'], $spy->lastData);
    }

    #[Test]
    public function viewWithExplicitEngineDoesNotRequireStaticEngine(): void
    {
        // Arrange -- no static engine set
        Response::clearTemplateEngine();
        $engine = $this->createStubEngine('explicit-only');

        // Act
        $response = Response::view($engine, 'test');

        // Assert -- should work without static engine
        self::assertSame('explicit-only', (string) $response->getBody());
    }

    #[Test]
    public function viewWithExplicitEngineCustomHeadersOverrideContentType(): void
    {
        // Arrange
        $engine = $this->createStubEngine('<xml>data</xml>');

        // Act -- override Content-Type
        $response = Response::view(
            $engine,
            'xml-template',
            [],
            200,
            ['Content-Type' => 'application/xhtml+xml; charset=utf-8'],
        );

        // Assert -- custom Content-Type takes precedence
        self::assertSame('application/xhtml+xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    // ── Static engine fallback (existing behavior) ─────────────────────

    #[Test]
    public function viewWithStaticEngineRendersTemplate(): void
    {
        // Arrange
        $engine = $this->createStubEngine('<h1>Static!</h1>');
        Response::setTemplateEngine($engine);

        // Act
        $response = Response::view('welcome', ['name' => 'Alice']);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<h1>Static!</h1>', (string) $response->getBody());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function viewWithStaticEngineUsesCustomStatusCode(): void
    {
        // Arrange
        $engine = $this->createStubEngine('<p>Not Found</p>');
        Response::setTemplateEngine($engine);

        // Act
        $response = Response::view('errors.404', [], 404);

        // Assert
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function viewWithoutEngineThrowsRuntimeException(): void
    {
        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No TemplateEngineInterface has been configured');

        // Act
        (void) Response::view('welcome');
    }

    #[Test]
    public function clearTemplateEngineRemovesEngine(): void
    {
        // Arrange
        $engine = $this->createStubEngine('content');
        Response::setTemplateEngine($engine);

        // Act -- should work
        $response = Response::view('test');
        self::assertSame('content', (string) $response->getBody());

        // Clear and try again -- should throw
        Response::clearTemplateEngine();

        $this->expectException(RuntimeException::class);
        (void) Response::view('test');
    }

    #[Test]
    public function viewWithStaticEnginePassesDataToRender(): void
    {
        // Arrange
        $spy = new TemplateEngineSpy();
        Response::setTemplateEngine($spy);

        // Act
        $response = Response::view('dashboard.index', ['user' => 'Bob', 'role' => 'admin']);

        // Assert
        self::assertSame('rendered', (string) $response->getBody());
        self::assertSame('dashboard.index', $spy->lastTemplate);
        self::assertSame(['user' => 'Bob', 'role' => 'admin'], $spy->lastData);
    }

    private function createStubEngine(string $output): TemplateEngineInterface
    {
        $engine = $this->createStub(TemplateEngineInterface::class);
        $engine->method('render')->willReturn($output);
        $engine->method('exists')->willReturn(true);

        return $engine;
    }
}

/**
 * Spy implementation of TemplateEngineInterface for verifying render calls.
 */
final class TemplateEngineSpy implements TemplateEngineInterface
{
    public string $lastTemplate = '';

    /** @var array<string, mixed> */
    public array $lastData = [];

    public function render(string $template, array $data = []): string
    {
        $this->lastTemplate = $template;
        $this->lastData = $data;

        return 'rendered';
    }

    public function compile(string $template): CompiledTemplate
    {
        return new CompiledTemplate('', '', 0);
    }

    public function exists(string $template): bool
    {
        return true;
    }
}
