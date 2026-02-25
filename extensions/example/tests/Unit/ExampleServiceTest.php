<?php

declare(strict_types=1);

namespace Pulsar\Extension\Example\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Example\ExampleService;

#[CoversClass(ExampleService::class)]
final class ExampleServiceTest extends TestCase
{
    private ExampleService $service;

    protected function setUp(): void
    {
        $this->service = new ExampleService();
    }

    #[Test]
    public function greetingReturnsDefaultHelloWorld(): void
    {
        self::assertSame('Hello, World!', $this->service->getGreeting());
    }

    #[Test]
    public function greetingReturnsCustomName(): void
    {
        self::assertSame('Hello, Alice!', $this->service->getGreeting('Alice'));
    }

    #[Test]
    public function greetingHandlesEmptyString(): void
    {
        self::assertSame('Hello, !', $this->service->getGreeting(''));
    }

    #[Test]
    public function infoReturnsExpectedStructure(): void
    {
        $info = $this->service->getInfo();

        self::assertArrayHasKey('extension', $info);
        self::assertArrayHasKey('framework_version', $info);
        self::assertArrayHasKey('php_version', $info);
        self::assertSame('pulsar/example', $info['extension']);
        self::assertSame(PHP_VERSION, $info['php_version']);
    }

    #[Test]
    public function infoFrameworkVersionIsNonEmpty(): void
    {
        $info = $this->service->getInfo();

        self::assertNotEmpty($info['framework_version']);
    }
}
