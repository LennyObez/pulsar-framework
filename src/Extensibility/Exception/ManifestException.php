<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Exception;

use Pulsar\Api\Api;

use function sprintf;

/**
 * Exception for manifest parsing and validation errors.
 */
#[Api]
final class ManifestException extends ExtensionException
{
    /**
     * Create exception for manifest file not found.
     */
    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('Manifest file not found: %s', $path));
    }

    /**
     * Create exception for invalid JSON in manifest.
     */
    public static function invalidJson(string $path, string $error): self
    {
        return new self(sprintf('Invalid JSON in manifest "%s": %s', $path, $error));
    }

    /**
     * Create exception for missing required field.
     */
    public static function missingField(string $field, string $path): self
    {
        return new self(sprintf('Missing required field "%s" in manifest: %s', $field, $path));
    }

    /**
     * Create exception for invalid field type.
     */
    public static function invalidFieldType(string $field, string $expected, string $actual, string $path): self
    {
        return new self(sprintf(
            'Invalid type for field "%s" in manifest "%s": expected %s, got %s',
            $field,
            $path,
            $expected,
            $actual,
        ));
    }

    /**
     * Create exception for invalid version format.
     */
    public static function invalidVersion(string $version, string $path): self
    {
        return new self(sprintf(
            'Invalid version format "%s" in manifest: %s',
            $version,
            $path,
        ));
    }

    /**
     * Create exception for extension class not found.
     */
    public static function extensionClassNotFound(string $class, string $path): self
    {
        return new self(sprintf(
            'Extension class "%s" not found for manifest: %s',
            $class,
            $path,
        ));
    }

    /**
     * Create exception for incompatible framework version.
     */
    public static function incompatibleFrameworkVersion(
        string $extensionName,
        string $requiredMin,
        ?string $requiredMax,
        string $currentVersion,
    ): self {
        $constraint = $requiredMax !== null
            ? sprintf('%s - %s', $requiredMin, $requiredMax)
            : sprintf('>= %s', $requiredMin);

        return new self(sprintf(
            'Extension "%s" requires Pulsar %s, but version %s is installed',
            $extensionName,
            $constraint,
            $currentVersion,
        ));
    }
}
