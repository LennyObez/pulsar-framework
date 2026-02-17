<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Exception;

use Pulsar\Api\Api;

/**
 * Thrown when file upload validation or processing fails.
 */
#[Api(since: '1.0.0')]
final class UploadException extends FormException
{
    public static function fileTooLarge(string $field, int $maxBytes): self
    {
        return new self("File for field '{$field}' exceeds maximum size of {$maxBytes} bytes");
    }

    public static function invalidMimeType(string $field, string $actual, string $expected): self
    {
        return new self("File for field '{$field}' has MIME type '{$actual}', expected '{$expected}'");
    }

    public static function antivirusScanFailed(string $field): self
    {
        return new self("Antivirus scan failed for file uploaded to field '{$field}'");
    }

    public static function antivirusNotConfigured(): self
    {
        return new self('AntivirusPort must be configured when using file uploads in a regulated preset');
    }

    public static function moveFailed(string $field, string $destination): self
    {
        return new self("Failed to move uploaded file for field '{$field}' to '{$destination}'");
    }
}
