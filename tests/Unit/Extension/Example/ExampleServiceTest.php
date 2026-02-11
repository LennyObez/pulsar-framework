<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Example;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Example\ExampleService;

#[CoversClass(ExampleService::class)]
final class ExampleServiceTest extends TestCase
{
    #[Test]
    public function getGreetingReturnsDefaultGreeting(): void
    {
        $service = new ExampleService();

        self::assertSame('Hello, World!', $service->getGreeting());
    }

    #[Test]
    public function getGreetingReturnsPersonalizedGreeting(): void
    {
        $service = new ExampleService();

        self::assertSame('Hello, Pulsar!', $service->getGreeting('Pulsar'));
    }

    #[Test]
    public function getInfoReturnsExtensionInfo(): void
    {
        $service = new ExampleService();

        $info = $service->getInfo();

        self::assertSame('pulsar/example', $info['extension']);
        self::assertArrayHasKey('framework_version', $info);
        self::assertSame(PHP_VERSION, $info['php_version']);
    }
}
