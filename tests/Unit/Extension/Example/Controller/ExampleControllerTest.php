<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Example\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Example\Controller\ExampleController;
use Pulsar\Extension\Example\ExampleService;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ExampleController::class)]
final class ExampleControllerTest extends TestCase
{
    #[Test]
    public function indexReturnsJsonWithGreetingAndStatus(): void
    {
        $controller = new ExampleController(new ExampleService());
        $request = new ServerRequest(method: 'GET', uri: '/example');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Hello, World!', $data['message']);
        self::assertSame('ok', $data['status']);
    }

    #[Test]
    public function infoReturnsExtensionMetadata(): void
    {
        $controller = new ExampleController(new ExampleService());
        $request = new ServerRequest(method: 'GET', uri: '/example/info');

        $response = $controller->info($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('pulsar/example', $data['extension']);
        self::assertArrayHasKey('framework_version', $data);
        self::assertSame(PHP_VERSION, $data['php_version']);
    }

    #[Test]
    public function greetReturnsPersonalizedGreeting(): void
    {
        $controller = new ExampleController(new ExampleService());
        $request = new ServerRequest(method: 'GET', uri: '/example/Alice');

        $response = $controller->greet($request, ['name' => 'Alice']);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Hello, Alice!', $data['message']);
    }

    #[Test]
    public function greetDefaultsToGuestWhenNameMissing(): void
    {
        $controller = new ExampleController(new ExampleService());
        $request = new ServerRequest(method: 'GET', uri: '/example/');

        $response = $controller->greet($request, []);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Hello, Guest!', $data['message']);
    }
}
