<?php

declare(strict_types=1);

namespace Pulsar\Extension\Example\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Example\Controller\ExampleController;
use Pulsar\Extension\Example\ExampleService;

#[CoversClass(ExampleController::class)]
final class ExampleControllerTest extends TestCase
{
    private ExampleController $controller;

    protected function setUp(): void
    {
        $this->controller = new ExampleController(new ExampleService());
    }

    #[Test]
    public function indexReturnsJsonResponseWithGreeting(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hello, World!', (string) $response->getBody());
    }

    #[Test]
    public function infoReturnsJsonResponseWithExtensionInfo(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->controller->info($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('pulsar/example', $body);
        self::assertStringContainsString(PHP_VERSION, $body);
    }

    #[Test]
    public function greetReturnsPersonalizedGreeting(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->controller->greet($request, ['name' => 'Bob']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hello, Bob!', (string) $response->getBody());
    }

    #[Test]
    public function greetWithoutNameUsesGuest(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->controller->greet($request, []);

        self::assertStringContainsString('Hello, Guest!', (string) $response->getBody());
    }
}
