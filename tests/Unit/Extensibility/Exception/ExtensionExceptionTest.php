<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Exception\ExtensionException;
use RuntimeException;

#[CoversClass(ExtensionException::class)]
final class ExtensionExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = ExtensionException::notFound('acme/billing');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function notFoundContainsName(): void
    {
        $exception = ExtensionException::notFound('acme/billing');

        self::assertSame('Extension "acme/billing" not found', $exception->getMessage());
    }

    #[Test]
    public function alreadyRegisteredContainsName(): void
    {
        $exception = ExtensionException::alreadyRegistered('acme/billing');

        self::assertSame('Extension "acme/billing" is already registered', $exception->getMessage());
    }

    #[Test]
    public function invalidExtensionClassContainsClass(): void
    {
        $exception = ExtensionException::invalidExtensionClass('Acme\\InvalidExtension');

        self::assertSame(
            'Extension class "Acme\\InvalidExtension" must implement ExtensionInterface',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function invalidStateContainsAllDetails(): void
    {
        $exception = ExtensionException::invalidState('acme/billing', 'registered', 'booted');

        self::assertSame(
            'Extension "acme/billing" is in state "registered" but expected "booted"',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function bootFailedContainsNameAndReason(): void
    {
        $exception = ExtensionException::bootFailed('acme/billing', 'missing config');

        self::assertSame(
            'Failed to boot extension "acme/billing": missing config',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function registrationFailedContainsNameAndReason(): void
    {
        $exception = ExtensionException::registrationFailed('acme/billing', 'duplicate service ID');

        self::assertSame(
            'Failed to register extension "acme/billing": duplicate service ID',
            $exception->getMessage(),
        );
    }
}
