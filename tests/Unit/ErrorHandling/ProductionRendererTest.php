<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

#[CoversClass(ProductionRenderer::class)]
final class ProductionRendererTest extends TestCase
{
    private function createRequest(string $path = '/'): Request
    {
        return new Request(
            method: Method::GET,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function doesNotContainExceptionMessage(): void
    {
        $renderer = new ProductionRenderer();
        $exception = new RuntimeException('Sensitive database error: connection to db01.internal failed');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringNotContainsString('Sensitive database error', $html);
        self::assertStringNotContainsString('db01.internal', $html);
    }

    #[Test]
    public function doesNotContainStackTrace(): void
    {
        $renderer = new ProductionRenderer();
        $exception = new RuntimeException('test');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringNotContainsString('ProductionRendererTest', $html);
        self::assertStringNotContainsString('#0', $html);
    }

    #[Test]
    public function doesNotContainExceptionClassName(): void
    {
        $renderer = new ProductionRenderer();
        $exception = new RuntimeException('test');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringNotContainsString('RuntimeException', $html);
    }

    #[Test]
    public function shows404Message(): void
    {
        $renderer = new ProductionRenderer();
        $exception = new RuntimeException('not found');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::NotFound);

        self::assertStringContainsString('could not be found', $html);
        self::assertStringContainsString('404', $html);
    }

    #[Test]
    public function shows500Message(): void
    {
        $renderer = new ProductionRenderer();
        $exception = new RuntimeException('server error');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('internal error', $html);
        self::assertStringContainsString('try again later', $html);
    }

    #[Test]
    public function showsForbiddenMessage(): void
    {
        $renderer = new ProductionRenderer();
        $exception = new RuntimeException('forbidden');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::Forbidden);

        self::assertStringContainsString('permission', $html);
    }

    #[Test]
    public function doesNotExposeFilePaths(): void
    {
        $renderer = new ProductionRenderer();
        $exception = new RuntimeException('test');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringNotContainsString('.php', $html);
        self::assertStringNotContainsString('src/', $html);
    }
}
