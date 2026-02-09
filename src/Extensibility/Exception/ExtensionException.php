<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for extension-related errors.
 */
#[Api(since: '1.0.0')]
class ExtensionException extends RuntimeException
{
    /**
     * Create exception for extension not found.
     */
    #[NoDiscard]
    public static function notFound(string $name): self
    {
        return new self(sprintf('Extension "%s" not found', $name));
    }

    /**
     * Create exception for extension already registered.
     */
    #[NoDiscard]
    public static function alreadyRegistered(string $name): self
    {
        return new self(sprintf('Extension "%s" is already registered', $name));
    }

    /**
     * Create exception for invalid extension class.
     */
    #[NoDiscard]
    public static function invalidExtensionClass(string $class): self
    {
        return new self(sprintf(
            'Extension class "%s" must implement ExtensionInterface',
            $class,
        ));
    }

    /**
     * Create exception for extension in invalid state.
     */
    #[NoDiscard]
    public static function invalidState(string $name, string $currentState, string $expectedState): self
    {
        return new self(sprintf(
            'Extension "%s" is in state "%s" but expected "%s"',
            $name,
            $currentState,
            $expectedState,
        ));
    }

    /**
     * Create exception for boot failure.
     */
    #[NoDiscard]
    public static function bootFailed(string $name, string $reason): self
    {
        return new self(sprintf('Failed to boot extension "%s": %s', $name, $reason));
    }

    /**
     * Create exception for registration failure.
     */
    #[NoDiscard]
    public static function registrationFailed(string $name, string $reason): self
    {
        return new self(sprintf('Failed to register extension "%s": %s', $name, $reason));
    }

    #[NoDiscard]
    public static function bootBeforeRegister(): self
    {
        return new self('Extensions must be registered before booting');
    }
}
