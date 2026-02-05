<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use NoDiscard;
use Pulsar\Api\Api;

use function str_replace;

/**
 * Safe LIKE pattern builder that escapes user input.
 */
#[Api(since: '1.0.0')]
final readonly class LikePattern
{
    private function __construct(
        private string $pattern,
    ) {}

    /**
     * Match values that start with the given prefix.
     */
    #[NoDiscard]
    public static function startsWith(string $prefix): self
    {
        return new self(self::escape($prefix) . '%');
    }

    /**
     * Match values that end with the given suffix.
     */
    #[NoDiscard]
    public static function endsWith(string $suffix): self
    {
        return new self('%' . self::escape($suffix));
    }

    /**
     * Match values that contain the given substring.
     */
    #[NoDiscard]
    public static function contains(string $substring): self
    {
        return new self('%' . self::escape($substring) . '%');
    }

    /**
     * Use a raw LIKE pattern (no escaping).
     */
    #[NoDiscard]
    public static function raw(string $pattern): self
    {
        return new self($pattern);
    }

    public function toString(): string
    {
        return $this->pattern;
    }

    /**
     * Escape special LIKE characters in user input.
     */
    private static function escape(string $value): string
    {
        // Chain replacements explicitly: backslash MUST be escaped first
        // before other replacements introduce backslash prefixes.
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('%', '\\%', $value);

        return str_replace('_', '\\_', $value);
    }
}
