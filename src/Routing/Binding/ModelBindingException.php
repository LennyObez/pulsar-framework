<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown when route model binding fails.
 */
#[Api(since: '1.0.0-rc.11')]
final class ModelBindingException extends RuntimeException
{
    /**
     * The bound model could not be found for the given key.
     */
    #[NoDiscard]
    public static function modelNotFound(string $modelClass, string $keyName, string|int $keyValue): self
    {
        return new self(
            sprintf('No [%s] found for [%s] = "%s"', $modelClass, $keyName, (string) $keyValue),
            404,
        );
    }

    /**
     * The authenticated identity is not authorized to access the resolved model.
     */
    #[NoDiscard]
    public static function authorizationFailed(string $modelClass, string|int $keyValue): self
    {
        return new self(
            sprintf('Authorization denied for [%s] with key "%s"', $modelClass, (string) $keyValue),
            403,
        );
    }

    /**
     * A regulated preset requires an authorization policy but none was configured.
     */
    #[NoDiscard]
    public static function missingPolicy(string $modelClass): self
    {
        return new self(
            sprintf(
                'Regulated preset requires an authorization policy for [%s], but none is configured. '
                . 'Register an AuthorizationHookInterface implementation or set an authzPolicy on the binding.',
                $modelClass,
            ),
            500,
        );
    }

    /**
     * No ModelResolverPort implementation is available for the requested model.
     */
    #[NoDiscard]
    public static function missingResolver(string $modelClass): self
    {
        return new self(
            sprintf('No model resolver registered for [%s]', $modelClass),
            500,
        );
    }

    /**
     * The route parameter value does not match the declared key type.
     */
    #[NoDiscard]
    public static function invalidKeyType(string $parameter, string $expectedType, string $actualValue): self
    {
        return new self(
            sprintf(
                'Route parameter [%s] expects type [%s] but received "%s"',
                $parameter,
                $expectedType,
                $actualValue,
            ),
            404,
        );
    }

    /**
     * The key name is not in the allow-list defined by ModelBindingConfig.
     */
    #[NoDiscard]
    public static function invalidKeyName(string $keyName, string $modelClass): self
    {
        return new self(
            sprintf(
                'Key name [%s] is not allowed for model [%s]. Check the allowed_key_names configuration.',
                $keyName,
                $modelClass,
            ),
            400,
        );
    }

    /**
     * Attempt to bypass authorization on a regulated route without the #[PublicRoute] attribute.
     */
    #[NoDiscard]
    public static function authBypassForbidden(string $routeName): self
    {
        return new self(
            sprintf(
                'Authorization bypass is forbidden on regulated route [%s]. '
                . 'Add the #[PublicRoute] attribute to explicitly opt out of authorization.',
                $routeName,
            ),
            403,
        );
    }
}
