<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\ErrorHandling\ErrorPageRenderer;
use Pulsar\ErrorHandling\ExceptionRendererInterface;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\View\Engine\CompiledTemplate;
use Pulsar\View\Engine\TemplateEngineInterface;
use RuntimeException;
use Throwable;

use function in_array;
use function time;

#[CoversClass(ErrorPageRenderer::class)]
final class ErrorPageRendererTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    private function createRequest(string $path = '/test-path'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: $path,
        );
    }

    /**
     * Create a stub template engine that tracks which templates were rendered.
     *
     * @param list<string> $existingTemplates Templates that should report as existing
     *
     * @return StubTemplateEngine
     */
    private function createTemplateEngine(
        array $existingTemplates = [],
        string $renderOutput = '<html>rendered</html>',
    ): StubTemplateEngine {
        return new StubTemplateEngine($existingTemplates, $renderOutput);
    }

    private function createDevRenderer(): ExceptionRendererInterface
    {
        return new class implements ExceptionRendererInterface {
            #[Override]
            public function render(Throwable $exception, ServerRequestInterface $request, ResponseStatus $status): string
            {
                return '<html>DEV_ERROR_PAGE:' . $exception->getMessage() . '</html>';
            }
        };
    }

    // =========================================================================
    // Template Resolution
    // =========================================================================

    #[Test]
    public function resolveTemplateReturnsSpecificTemplateWhenExists(): void
    {
        // Arrange
        $engine = $this->createTemplateEngine(['errors.404', 'errors.4xx']);
        $renderer = new ErrorPageRenderer(templateEngine: $engine);

        // Act
        $result = $renderer->resolveTemplate(404);

        // Assert
        self::assertSame('errors.404', $result);
    }

    #[Test]
    public function resolveTemplateFallsToCategoryWhenSpecificMissing(): void
    {
        // Arrange: 418 has no specific template, but 4xx exists
        $engine = $this->createTemplateEngine(['errors.4xx']);
        $renderer = new ErrorPageRenderer(templateEngine: $engine);

        // Act
        $result = $renderer->resolveTemplate(418);

        // Assert
        self::assertSame('errors.4xx', $result);
    }

    #[Test]
    public function resolveTemplateFallsTo5xxForServerErrors(): void
    {
        // Arrange
        $engine = $this->createTemplateEngine(['errors.5xx']);
        $renderer = new ErrorPageRenderer(templateEngine: $engine);

        // Act
        $result = $renderer->resolveTemplate(501);

        // Assert
        self::assertSame('errors.5xx', $result);
    }

    #[Test]
    public function resolveTemplateReturnsNullWhenNoTemplateExists(): void
    {
        // Arrange
        $engine = $this->createTemplateEngine([]); // no templates
        $renderer = new ErrorPageRenderer(templateEngine: $engine);

        // Act
        $result = $renderer->resolveTemplate(404);

        // Assert
        self::assertNull($result);
    }

    #[Test]
    public function resolveTemplateReturnsNullWithoutTemplateEngine(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $result = $renderer->resolveTemplate(500);

        // Assert
        self::assertNull($result);
    }

    // =========================================================================
    // Context Building
    // =========================================================================

    #[Test]
    public function buildContextContainsStatusAndRequestData(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new RuntimeException('test error');
        $request = $this->createRequest('/some/page');

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::NotFound);

        // Assert
        self::assertSame(404, $context['status']);
        self::assertSame('Not Found', $context['statusPhrase']);
        self::assertSame('/some/page', $context['requestUrl']);
        self::assertSame('GET', $context['requestMethod']);
        self::assertFalse($context['isServerError']);
        self::assertTrue($context['isClientError']);
    }

    #[Test]
    public function buildContextDoesNotExposeExceptionInProduction(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer(debug: false);
        $exception = new RuntimeException('Sensitive database credentials leaked');
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::InternalServerError);

        // Assert: message is the HTTP reason phrase, not the exception message
        self::assertSame('Internal Server Error', $context['message']);
        self::assertArrayNotHasKey('exception', $context);
        self::assertArrayNotHasKey('exceptionClass', $context);
        self::assertArrayNotHasKey('exceptionMessage', $context);
        self::assertArrayNotHasKey('trace', $context);
    }

    #[Test]
    public function buildContextExposesExceptionInDebugMode(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer(debug: true);
        $exception = new RuntimeException('Debug error message');
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::InternalServerError);

        // Assert
        self::assertSame('Debug error message', $context['message']);
        self::assertSame($exception, $context['exception']);
        self::assertSame(RuntimeException::class, $context['exceptionClass']);
        self::assertSame('Debug error message', $context['exceptionMessage']);
        self::assertArrayHasKey('trace', $context);
    }

    #[Test]
    public function buildContextExtractsRetryAfterFromHttpException(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new HttpException(
            ResponseStatus::TooManyRequests,
            'Rate limited',
            ['Retry-After' => '60'],
        );
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::TooManyRequests);

        // Assert
        self::assertSame(60, $context['retryAfter']);
    }

    #[Test]
    public function buildContextRetryAfterNullForNonHttpException(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new RuntimeException('generic');
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::InternalServerError);

        // Assert
        self::assertNull($context['retryAfter']);
    }

    #[Test]
    public function buildContextRetryAfterNullForZeroValue(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new HttpException(
            ResponseStatus::TooManyRequests,
            'Rate limited',
            ['Retry-After' => '0'],
        );
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::TooManyRequests);

        // Assert
        self::assertNull($context['retryAfter']);
    }

    #[Test]
    public function buildContextExtractsMaintenanceMessage(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new HttpException(
            ResponseStatus::ServiceUnavailable,
            'Maintenance',
            ['X-Maintenance-Message' => 'Database migration in progress'],
        );
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::ServiceUnavailable);

        // Assert
        self::assertSame('Database migration in progress', $context['maintenanceMessage']);
    }

    #[Test]
    public function buildContextExtractsEstimatedReturn(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new HttpException(
            ResponseStatus::ServiceUnavailable,
            'Maintenance',
            ['X-Estimated-Return' => '2026-03-20T15:00:00Z'],
        );
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::ServiceUnavailable);

        // Assert
        self::assertSame('2026-03-20T15:00:00Z', $context['estimatedReturn']);
    }

    #[Test]
    public function buildContextEscapesRequestUrl(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new RuntimeException('test');
        $request = $this->createRequest('/path?q=<script>alert(1)</script>');

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::NotFound);

        // Assert: XSS characters are escaped
        $requestUrl = $context['requestUrl'];
        self::assertIsString($requestUrl);
        self::assertStringNotContainsString('<script>', $requestUrl);
    }

    #[Test]
    public function buildContextServerErrorFlags(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new RuntimeException('test');
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::InternalServerError);

        // Assert
        self::assertTrue($context['isServerError']);
        self::assertFalse($context['isClientError']);
    }

    // =========================================================================
    // Rendering — Template Engine Path
    // =========================================================================

    #[Test]
    public function renderUsesSpecificTemplateWhenAvailable(): void
    {
        // Arrange
        $engine = $this->createTemplateEngine(
            existingTemplates: ['errors.404', 'errors.4xx'],
            renderOutput: '<html>custom 404</html>',
        );
        $renderer = new ErrorPageRenderer(templateEngine: $engine);

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::NotFound,
        );

        // Assert
        self::assertSame('<html>custom 404</html>', $html);
        self::assertSame(['errors.404'], $engine->renderedTemplates);
    }

    #[Test]
    public function renderFallsToCategoryTemplateWhenSpecificMissing(): void
    {
        // Arrange
        $engine = $this->createTemplateEngine(
            existingTemplates: ['errors.4xx'],
            renderOutput: '<html>generic 4xx</html>',
        );
        $renderer = new ErrorPageRenderer(templateEngine: $engine);

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::RequestTimeout, // 408 — no specific template
        );

        // Assert
        self::assertSame('<html>generic 4xx</html>', $html);
        self::assertSame(['errors.4xx'], $engine->renderedTemplates);
    }

    #[Test]
    public function renderPassesContextToTemplate(): void
    {
        // Arrange
        $engine = $this->createTemplateEngine(existingTemplates: ['errors.500']);
        $renderer = new ErrorPageRenderer(templateEngine: $engine);

        // Act
        $renderer->render(
            new RuntimeException('server error'),
            $this->createRequest('/broken'),
            ResponseStatus::InternalServerError,
        );

        // Assert
        self::assertCount(1, $engine->renderedData);
        $data = $engine->renderedData[0];
        self::assertSame(500, $data['status']);
        self::assertSame('Internal Server Error', $data['statusPhrase']);
        self::assertSame('/broken', $data['requestUrl']);
    }

    #[Test]
    public function renderFallsToInlineHtmlWhenTemplateEngineFails(): void
    {
        // Arrange: engine exists but throws on render
        $engine = new BrokenTemplateEngine();
        $renderer = new ErrorPageRenderer(templateEngine: $engine);

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::InternalServerError,
        );

        // Assert: falls back to inline HTML
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('500', $html);
        self::assertStringContainsString('Internal Server Error', $html);
    }

    // =========================================================================
    // Rendering — Lazy template-engine resolution (wiring-order independence)
    // =========================================================================

    #[Test]
    public function renderUsesTemplateEngineFromLazyResolver(): void
    {
        // The engine is provided via the lazy resolver (not the direct param),
        // exactly as ExceptionHandlerWiring supplies it. The "engine bound only
        // after the renderer is constructed" timing is covered end-to-end by
        // ExceptionHandlerWiringTest::errorPageRendererResolvesTemplateEngineBoundAfterWiring.
        $engine = $this->createTemplateEngine(
            existingTemplates: ['errors.404'],
            renderOutput: '<html>lazy 404</html>',
        );
        $renderer = new ErrorPageRenderer(
            templateEngineResolver: static fn(): TemplateEngineInterface => $engine,
        );

        $html = $renderer->render(
            new RuntimeException('not found'),
            $this->createRequest(),
            ResponseStatus::NotFound,
        );

        self::assertSame('<html>lazy 404</html>', $html);
        self::assertSame(['errors.404'], $engine->renderedTemplates);
    }

    #[Test]
    public function renderUsesInlineFallbackWhenLazyResolverYieldsNull(): void
    {
        // Resolver present but the engine is never bound (e.g. ViewWiring absent):
        // must degrade to the inline fallback, not error.
        $renderer = new ErrorPageRenderer(
            templateEngineResolver: static fn(): ?TemplateEngineInterface => null,
        );

        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::NotFound,
        );

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('404', $html);
    }

    #[Test]
    public function resolveTemplateUsesLazilyResolvedEngine(): void
    {
        $engine = $this->createTemplateEngine(['errors.500', 'errors.5xx']);
        $renderer = new ErrorPageRenderer(
            templateEngineResolver: static fn(): TemplateEngineInterface => $engine,
        );

        self::assertSame('errors.500', $renderer->resolveTemplate(500));
    }

    // =========================================================================
    // Rendering — Inline Fallback
    // =========================================================================

    #[Test]
    public function renderFallsToInlineHtmlWithoutTemplateEngine(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::NotFound,
        );

        // Assert
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('404', $html);
        self::assertStringContainsString('Not Found', $html);
    }

    #[Test]
    public function inlineFallbackIsValidHtml(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::InternalServerError,
        );

        // Assert: valid HTML structure
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<meta charset="utf-8">', $html);
        self::assertStringContainsString('</html>', $html);
        self::assertStringContainsString('role="main"', $html);
    }

    #[Test]
    public function inlineFallbackHasAccessibilityAttributes(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::NotFound,
        );

        // Assert: WCAG landmarks and labels
        self::assertStringContainsString('role="main"', $html);
        self::assertStringContainsString('aria-labelledby="error-title"', $html);
        self::assertStringContainsString('id="error-title"', $html);
        self::assertStringContainsString('aria-label="Error recovery options"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    #[Test]
    public function inlineFallbackHasNavigationLinks(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::NotFound,
        );

        // Assert
        self::assertStringContainsString('href="/"', $html);
        self::assertStringContainsString('Return home', $html);
        self::assertStringContainsString('Go back', $html);
    }

    #[Test]
    public function inlineFallbackUsesLightThemeForClientErrors(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::NotFound, // 4xx = client error = light theme
        );

        // Assert: light background color
        self::assertStringContainsString('#f8fafc', $html);
    }

    #[Test]
    public function inlineFallbackUsesDarkThemeForServerErrors(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::InternalServerError, // 5xx = server error = dark theme
        );

        // Assert: dark background color
        self::assertStringContainsString('#0f172a', $html);
    }

    #[Test]
    public function inlineFallbackUsesPulsarDesignColors(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::NotFound,
        );

        // Assert: Pulsar Deep Blue accent
        self::assertStringContainsString('#0039cb', $html);
        // Assert: Montserrat and Overpass font families
        self::assertStringContainsString('Montserrat', $html);
        self::assertStringContainsString('Overpass', $html);
    }

    #[Test]
    public function inlineFallbackHasFocusVisibleStyles(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::NotFound,
        );

        // Assert: focus-visible for keyboard accessibility
        self::assertStringContainsString('focus-visible', $html);
    }

    #[Test]
    public function inlineFallbackDoesNotExposeExceptionDetails(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer(debug: false);
        $exception = new RuntimeException('SECRET: database password is hunter2');

        // Act
        $html = $renderer->render(
            $exception,
            $this->createRequest(),
            ResponseStatus::InternalServerError,
        );

        // Assert
        self::assertStringNotContainsString('SECRET', $html);
        self::assertStringNotContainsString('hunter2', $html);
        self::assertStringNotContainsString('RuntimeException', $html);
    }

    #[Test]
    public function inlineFallbackHasNoExternalDependencies(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::InternalServerError,
        );

        // Assert: no external CSS/JS/font links
        self::assertStringNotContainsString('<link rel="stylesheet"', $html);
        self::assertStringNotContainsString('<script src=', $html);
        self::assertStringNotContainsString('googleapis.com', $html);
        self::assertStringNotContainsString('cdnjs.cloudflare.com', $html);
    }

    // =========================================================================
    // Rendering — Development Mode
    // =========================================================================

    #[Test]
    public function renderDelegatesToDevRendererInDebugMode(): void
    {
        // Arrange
        $devRenderer = $this->createDevRenderer();
        $renderer = new ErrorPageRenderer(
            devRenderer: $devRenderer,
            debug: true,
        );

        // Act
        $html = $renderer->render(
            new RuntimeException('Debug error'),
            $this->createRequest(),
            ResponseStatus::InternalServerError,
        );

        // Assert
        self::assertStringContainsString('DEV_ERROR_PAGE', $html);
        self::assertStringContainsString('Debug error', $html);
    }

    #[Test]
    public function renderFallsToInlineWhenDebugButNoDevRenderer(): void
    {
        // Arrange: debug mode but no dev renderer provided
        $renderer = new ErrorPageRenderer(debug: true);

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::InternalServerError,
        );

        // Assert: still produces valid output (uses template or inline fallback)
        self::assertStringContainsString('500', $html);
        self::assertStringContainsString('<!DOCTYPE html>', $html);
    }

    #[Test]
    public function renderProductionModeIgnoresDevRenderer(): void
    {
        // Arrange: dev renderer provided but debug=false
        $devRenderer = $this->createDevRenderer();
        $renderer = new ErrorPageRenderer(
            devRenderer: $devRenderer,
            debug: false,
        );

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::InternalServerError,
        );

        // Assert: does NOT use dev renderer
        self::assertStringNotContainsString('DEV_ERROR_PAGE', $html);
    }

    // =========================================================================
    // All Status Codes Render Valid HTML
    // =========================================================================

    /**
     * @return iterable<string, array{ResponseStatus}>
     */
    public static function allStatusCodesProvider(): iterable
    {
        yield '400 Bad Request' => [ResponseStatus::BadRequest];
        yield '401 Unauthorized' => [ResponseStatus::Unauthorized];
        yield '403 Forbidden' => [ResponseStatus::Forbidden];
        yield '404 Not Found' => [ResponseStatus::NotFound];
        yield '405 Method Not Allowed' => [ResponseStatus::MethodNotAllowed];
        yield '408 Request Timeout' => [ResponseStatus::RequestTimeout];
        yield '413 Payload Too Large' => [ResponseStatus::PayloadTooLarge];
        yield '422 Unprocessable Entity' => [ResponseStatus::UnprocessableEntity];
        yield '429 Too Many Requests' => [ResponseStatus::TooManyRequests];
        yield '500 Internal Server Error' => [ResponseStatus::InternalServerError];
        yield '502 Bad Gateway' => [ResponseStatus::BadGateway];
        yield '503 Service Unavailable' => [ResponseStatus::ServiceUnavailable];
        yield '504 Gateway Timeout' => [ResponseStatus::GatewayTimeout];
    }

    #[Test]
    #[DataProvider('allStatusCodesProvider')]
    public function everyStatusCodeRendersValidHtml(ResponseStatus $status): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            $status,
        );

        // Assert
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString((string) $status->value, $html);
        self::assertStringContainsString('</html>', $html);
        self::assertStringContainsString('role="main"', $html);
    }

    #[Test]
    #[DataProvider('allStatusCodesProvider')]
    public function everyStatusCodeDoesNotLeakSensitiveData(ResponseStatus $status): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer(debug: false);
        $exception = new RuntimeException('PRIVATE: /var/www/app/database.sqlite password=s3cret');

        // Act
        $html = $renderer->render($exception, $this->createRequest(), $status);

        // Assert
        self::assertStringNotContainsString('PRIVATE', $html);
        self::assertStringNotContainsString('database.sqlite', $html);
        self::assertStringNotContainsString('s3cret', $html);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    #[Test]
    public function renderHandlesUncommonStatusCodeViaCategoryFallback(): void
    {
        // Arrange: 418 I'm a Teapot — no specific template
        $engine = $this->createTemplateEngine(
            existingTemplates: ['errors.4xx'],
            renderOutput: '<html>teapot fallback</html>',
        );
        $renderer = new ErrorPageRenderer(templateEngine: $engine);

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::ImATeapot,
        );

        // Assert
        self::assertSame('<html>teapot fallback</html>', $html);
    }

    #[Test]
    public function renderHandlesSpecialCharactersInExceptionMessage(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer(debug: true);
        $exception = new RuntimeException('Error with <script>alert("XSS")</script> & "quotes"');

        // Act
        $context = $renderer->buildContext($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        // Assert: message is the raw exception message (templates handle escaping)
        $message = $context['message'];
        self::assertIsString($message);
        self::assertStringContainsString('<script>', $message);
    }

    #[Test]
    public function resolveTemplatePrefersMostSpecific(): void
    {
        // Arrange: both specific and category templates exist
        $engine = $this->createTemplateEngine(['errors.500', 'errors.5xx']);
        $renderer = new ErrorPageRenderer(templateEngine: $engine);

        // Act
        $result = $renderer->resolveTemplate(500);

        // Assert: specific wins over category
        self::assertSame('errors.500', $result);
    }

    #[Test]
    public function buildContextMaintenanceNullForNonHttpException(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new RuntimeException('test');
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::ServiceUnavailable);

        // Assert
        self::assertNull($context['maintenanceMessage']);
        self::assertNull($context['estimatedReturn']);
    }

    #[Test]
    public function buildContextMaintenanceNullWhenHeaderAbsent(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new HttpException(ResponseStatus::ServiceUnavailable, 'Down');
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::ServiceUnavailable);

        // Assert
        self::assertNull($context['maintenanceMessage']);
        self::assertNull($context['estimatedReturn']);
    }

    #[Test]
    public function inlineFallbackHasViewportMetaTag(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();

        // Act
        $html = $renderer->render(
            new RuntimeException('test'),
            $this->createRequest(),
            ResponseStatus::NotFound,
        );

        // Assert: responsive viewport
        self::assertStringContainsString('viewport', $html);
        self::assertStringContainsString('width=device-width', $html);
    }

    #[Test]
    public function buildContextRetryAfterNullWhenHeaderMissing(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new HttpException(ResponseStatus::TooManyRequests, 'Rate limited');
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::TooManyRequests);

        // Assert
        self::assertNull($context['retryAfter']);
    }

    #[Test]
    public function buildContextRetryAfterNullForNegativeValue(): void
    {
        // Arrange
        $renderer = new ErrorPageRenderer();
        $exception = new HttpException(
            ResponseStatus::TooManyRequests,
            'Rate limited',
            ['Retry-After' => '-5'],
        );
        $request = $this->createRequest();

        // Act
        $context = $renderer->buildContext($exception, $request, ResponseStatus::TooManyRequests);

        // Assert
        self::assertNull($context['retryAfter']);
    }
}

/**
 * Stub template engine for testing — tracks rendered templates and data.
 *
 * @internal Test helper only
 */
final class StubTemplateEngine implements TemplateEngineInterface
{
    /** @var list<string> */
    public array $renderedTemplates = [];

    /** @var list<array<string, mixed>> */
    public array $renderedData = [];

    /** @var list<array{string|array<string, mixed>, mixed}> */
    public array $sharedCalls = [];

    /** @var list<array{string|list<string>, callable}> */
    public array $composerCalls = [];

    /**
     * @param list<string> $existingTemplates
     */
    public function __construct(
        private readonly array $existingTemplates,
        private readonly string $renderOutput,
    ) {}

    #[Override]
    public function render(string $template, array $data = []): string
    {
        $this->renderedTemplates[] = $template;
        $this->renderedData[] = $data;

        return $this->renderOutput;
    }

    #[Override]
    public function compile(string $template): CompiledTemplate
    {
        return new CompiledTemplate($template, hash('sha256', $template), time());
    }

    #[Override]
    public function exists(string $template): bool
    {
        return in_array($template, $this->existingTemplates, true);
    }

    #[Override]
    public function share(string|array $key, mixed $value = null): void
    {
        $this->sharedCalls[] = [$key, $value];
    }

    #[Override]
    public function composer(string|array $patterns, callable $composer): void
    {
        $this->composerCalls[] = [$patterns, $composer];
    }
}

/**
 * Stub template engine that always throws on render (for fallback testing).
 *
 * @internal Test helper only
 */
final class BrokenTemplateEngine implements TemplateEngineInterface
{
    /** @var list<array{string|array<string, mixed>, mixed}> */
    public array $sharedCalls = [];

    /** @var list<array{string|list<string>, callable}> */
    public array $composerCalls = [];

    #[Override]
    public function render(string $template, array $data = []): string
    {
        throw new RuntimeException('Template engine broken');
    }

    #[Override]
    public function compile(string $template): CompiledTemplate
    {
        return new CompiledTemplate($template, hash('sha256', $template), time());
    }

    #[Override]
    public function exists(string $template): bool
    {
        return true; // pretend template exists
    }

    #[Override]
    public function share(string|array $key, mixed $value = null): void
    {
        $this->sharedCalls[] = [$key, $value];
    }

    #[Override]
    public function composer(string|array $patterns, callable $composer): void
    {
        $this->composerCalls[] = [$patterns, $composer];
    }
}
