<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\DevErrorPage;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use RuntimeException;

#[CoversClass(DevErrorPage::class)]
final class DevErrorPageTest extends TestCase
{
    private function createRequest(string $path = '/', string $method = 'GET'): ServerRequest
    {
        return new ServerRequest(method: $method, uri: $path);
    }

    #[Test]
    public function renderProducesValidHtml(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('Something went wrong');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('</html>', $html);
        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('Something went wrong', $html);
    }

    #[Test]
    public function renderShowsStatusCodeAndReasonPhrase(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::NotFound);

        self::assertStringContainsString('404', $html);
        self::assertStringContainsString('Not Found', $html);
    }

    #[Test]
    public function renderShowsFileAndLineNumber(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        // Should contain this test file's name
        self::assertStringContainsString('DevErrorPageTest.php', $html);
    }

    #[Test]
    public function renderShowsSourceCodeWithHighlightedLine(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        // Should contain source code section with error line highlighting
        self::assertStringContainsString('Source Code', $html);
        self::assertStringContainsString('dev-error__source-line--error', $html);
    }

    #[Test]
    public function renderShowsStackTrace(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('trace test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('Stack Trace', $html);
        self::assertStringContainsString('dev-error__frame', $html);
    }

    #[Test]
    public function renderShowsRequestDetails(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('test');

        $request = new ServerRequest(method: 'POST', uri: '/api/users?page=1');
        $html = $page->render($exception, $request, ResponseStatus::InternalServerError);

        self::assertStringContainsString('POST', $html);
        self::assertStringContainsString('/api/users', $html);
        self::assertStringContainsString('Request', $html);
    }

    #[Test]
    public function renderShowsRouteInfoWhenMatched(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('test');

        $route = new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: 'UserController::show',
            name: 'users.show',
            middleware: ['auth'],
        );
        $matched = new MatchedRoute($route, ['id' => '42']);

        $request = $this->createRequest('/users/42')
            ->withAttribute('_matched_route', $matched);

        $html = $page->render($exception, $request, ResponseStatus::InternalServerError);

        self::assertStringContainsString('users.show', $html);
        self::assertStringContainsString('/users/{id}', $html);
        self::assertStringContainsString('UserController::show', $html);
        self::assertStringContainsString('auth', $html);
    }

    #[Test]
    public function renderShowsNoRouteMessageWhenNotMatched(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('No route matched', $html);
    }

    #[Test]
    public function renderShowsEnvironmentInfo(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('Environment', $html);
        self::assertStringContainsString(PHP_VERSION, $html);
        self::assertStringContainsString('Pulsar Version', $html);
    }

    #[Test]
    public function renderShowsDatabaseQueries(): void
    {
        $queries = [
            ['sql' => 'SELECT * FROM users WHERE id = ?', 'time_ms' => 1.23],
            ['sql' => 'SELECT * FROM posts WHERE user_id = ?', 'time_ms' => 150.5],
        ];

        $page = new DevErrorPage(executedQueries: $queries);
        $exception = new RuntimeException('test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('Database Queries', $html);
        self::assertStringContainsString('SELECT * FROM users', $html);
        self::assertStringContainsString('1.23', $html);
        self::assertStringContainsString('2 queries executed', $html);
        // Slow query (>100ms) should have slow class
        self::assertStringContainsString('dev-error__query--slow', $html);
    }

    #[Test]
    public function renderShowsNoQueriesMessage(): void
    {
        $page = new DevErrorPage(executedQueries: []);
        $exception = new RuntimeException('test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('No queries executed', $html);
    }

    #[Test]
    public function renderShowsPreviousExceptions(): void
    {
        $page = new DevErrorPage();
        $inner = new LogicException('Inner cause');
        $exception = new RuntimeException('Outer error', 0, $inner);

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('Previous Exceptions', $html);
        self::assertStringContainsString('LogicException', $html);
        self::assertStringContainsString('Inner cause', $html);
    }

    #[Test]
    public function renderEscapesHtmlEntitiesInExceptionMessage(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('Error with <script>alert("xss")</script>');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderEscapesSqlInQueries(): void
    {
        $queries = [
            ['sql' => "SELECT * FROM users WHERE name = '<script>bad</script>'", 'time_ms' => 1.0],
        ];

        $page = new DevErrorPage(executedQueries: $queries);
        $exception = new RuntimeException('test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringNotContainsString('<script>bad</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderContainsExpandableFrameScript(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('toggleFrame', $html);
    }

    #[Test]
    public function renderContainsDesignCharterStyles(): void
    {
        $page = new DevErrorPage();
        $exception = new RuntimeException('test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        // Pulsar Deep Blue color
        self::assertStringContainsString('#0039cb', $html);
        // Dark theme background
        self::assertStringContainsString('#0f172a', $html);
        // Font family references
        self::assertStringContainsString('Overpass', $html);
        self::assertStringContainsString('JetBrains Mono', $html);
    }

    #[Test]
    public function renderShowsQueryTotalTime(): void
    {
        $queries = [
            ['sql' => 'SELECT 1', 'time_ms' => 1.5],
            ['sql' => 'SELECT 2', 'time_ms' => 2.5],
        ];

        $page = new DevErrorPage(executedQueries: $queries);
        $exception = new RuntimeException('test');

        $html = $page->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        // Total should be 4.00 ms
        self::assertStringContainsString('4.00', $html);
    }
}
