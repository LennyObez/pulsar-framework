<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Registry for content types, including both built-in enum cases and
 * custom types registered by CMS plugins at runtime.
 *
 * @psalm-api Static registry called by name from CMS plugins, content
 *            services, and the field registry.
 */
#[Api(since: '1.0.0')]
final class ContentTypeRegistry
{
    /** @var array<string, true> */
    private static array $customTypes = [];

    /**
     * Register a custom content type slug.
     */
    public static function register(string $type): void
    {
        self::$customTypes[$type] = true;
    }

    /**
     * Whether the given string is a valid content type (built-in or custom).
     */
    public static function isValid(string $value): bool
    {
        if (ContentType::tryFrom($value) !== null) {
            return true;
        }

        return isset(self::$customTypes[$value]);
    }

    /**
     * Remove all custom types. Intended for testing only.
     */
    public static function reset(): void
    {
        self::$customTypes = [];
    }
}
