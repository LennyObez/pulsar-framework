<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\Exception\ManifestException;
use RuntimeException;

#[CoversClass(ManifestException::class)]
final class ManifestExceptionTest extends TestCase
{
    #[Test]
    public function extendsExtensionException(): void
    {
        $exception = ManifestException::fileNotFound('/tmp/pulsar.json');

        self::assertInstanceOf(ExtensionException::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function fileNotFoundContainsPath(): void
    {
        $exception = ManifestException::fileNotFound('/extensions/acme/pulsar.json');

        self::assertSame('Manifest file not found: /extensions/acme/pulsar.json', $exception->getMessage());
    }

    #[Test]
    public function invalidJsonContainsPathAndError(): void
    {
        $exception = ManifestException::invalidJson('/ext/pulsar.json', 'Syntax error');

        self::assertSame('Invalid JSON in manifest "/ext/pulsar.json": Syntax error', $exception->getMessage());
    }

    #[Test]
    public function missingFieldContainsFieldAndPath(): void
    {
        $exception = ManifestException::missingField('name', '/ext/pulsar.json');

        self::assertSame('Missing required field "name" in manifest: /ext/pulsar.json', $exception->getMessage());
    }

    #[Test]
    public function invalidFieldTypeContainsAllDetails(): void
    {
        $exception = ManifestException::invalidFieldType('version', 'string', 'integer', '/ext/pulsar.json');

        self::assertSame(
            'Invalid type for field "version" in manifest "/ext/pulsar.json": expected string, got integer',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function invalidVersionContainsVersionAndPath(): void
    {
        $exception = ManifestException::invalidVersion('not-semver', '/ext/pulsar.json');

        self::assertSame(
            'Invalid version format "not-semver" in manifest: /ext/pulsar.json',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function extensionClassNotFoundContainsClassAndPath(): void
    {
        $exception = ManifestException::extensionClassNotFound('Acme\\Extension', '/ext/pulsar.json');

        self::assertSame(
            'Extension class "Acme\\Extension" not found for manifest: /ext/pulsar.json',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function incompatibleFrameworkVersionWithMaxConstraint(): void
    {
        $exception = ManifestException::incompatibleFrameworkVersion(
            'acme/billing',
            '1.0.0',
            '2.0.0',
            '3.0.0',
        );

        self::assertSame(
            'Extension "acme/billing" requires Pulsar 1.0.0 - 2.0.0, but version 3.0.0 is installed',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function incompatibleFrameworkVersionWithoutMaxConstraint(): void
    {
        $exception = ManifestException::incompatibleFrameworkVersion(
            'acme/billing',
            '2.0.0',
            null,
            '1.5.0',
        );

        self::assertSame(
            'Extension "acme/billing" requires Pulsar >= 2.0.0, but version 1.5.0 is installed',
            $exception->getMessage(),
        );
    }
}
