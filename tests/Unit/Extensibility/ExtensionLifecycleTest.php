<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\ExtensionLifecycle;

#[CoversClass(ExtensionLifecycle::class)]
final class ExtensionLifecycleTest extends TestCase
{
    #[Test]
    public function discoveredCanRegister(): void
    {
        self::assertFalse(ExtensionLifecycle::Discovered->canRegister());
    }

    #[Test]
    public function validatedCanRegister(): void
    {
        self::assertTrue(ExtensionLifecycle::Validated->canRegister());
    }

    #[Test]
    public function registeredCannotRegister(): void
    {
        self::assertFalse(ExtensionLifecycle::Registered->canRegister());
    }

    #[Test]
    public function registeredCanBoot(): void
    {
        self::assertTrue(ExtensionLifecycle::Registered->canBoot());
    }

    #[Test]
    public function validatedCannotBoot(): void
    {
        self::assertFalse(ExtensionLifecycle::Validated->canBoot());
    }

    #[Test]
    public function bootedIsBooted(): void
    {
        self::assertTrue(ExtensionLifecycle::Booted->isBooted());
        self::assertFalse(ExtensionLifecycle::Registered->isBooted());
    }

    #[Test]
    public function failedHasFailed(): void
    {
        self::assertTrue(ExtensionLifecycle::Failed->hasFailed());
        self::assertFalse(ExtensionLifecycle::Booted->hasFailed());
    }
}
