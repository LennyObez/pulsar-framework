<?php

declare(strict_types=1);

namespace Pulsar\Config\Validation;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * A single config validation error with path and human-readable message.
 */
#[Api(since: '1.0.0')]
final readonly class ConfigValidationError
{
    public function __construct(
        public string $path,
        public string $message,
        public ConfigSeverity $severity = ConfigSeverity::Error,
    ) {}

    #[NoDiscard]
    public static function required(string $path): self
    {
        return new self($path, sprintf('Required configuration key "%s" is missing.', $path));
    }

    #[NoDiscard]
    public static function invalidType(string $path, string $expected, string $actual): self
    {
        return new self(
            $path,
            sprintf('Configuration key "%s" must be %s, got %s.', $path, $expected, $actual),
        );
    }

    #[NoDiscard]
    public static function outOfRange(string $path, string $constraint): self
    {
        return new self(
            $path,
            sprintf('Configuration key "%s" is out of range: %s.', $path, $constraint),
        );
    }

    #[NoDiscard]
    public static function invalidFormat(string $path, string $expected): self
    {
        return new self(
            $path,
            sprintf('Configuration key "%s" has invalid format: expected %s.', $path, $expected),
        );
    }

    #[NoDiscard]
    public static function custom(string $path, string $message, ConfigSeverity $severity = ConfigSeverity::Error): self
    {
        return new self($path, $message, $severity);
    }
}
