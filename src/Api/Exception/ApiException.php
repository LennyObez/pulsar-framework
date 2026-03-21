<?php

declare(strict_types=1);

namespace Pulsar\Api\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for all API resource errors.
 *
 * Provides static factory methods for specific API error scenarios.
 * @api
 */
#[Api(since: '1.0.0')]
final class ApiException extends RuntimeException
{
    /**
     * A requested sparse fieldset field is not exposed on the resource.
     */
    #[NoDiscard]
    public static function unknownField(string $field, string $resourceType): self
    {
        return new self(
            sprintf('Unknown field "%s" requested for resource "%s"', $field, $resourceType),
            400,
        );
    }

    /**
     * The number of requested fields exceeds the complexity cap.
     */
    #[NoDiscard]
    public static function fieldLimitExceeded(int $requested, int $max): self
    {
        return new self(
            sprintf('Requested %d fields exceeds maximum of %d', $requested, $max),
            400,
        );
    }

    /**
     * The nesting depth for included resources exceeds the cap.
     */
    #[NoDiscard]
    public static function nestingDepthExceeded(int $depth, int $max): self
    {
        return new self(
            sprintf('Nesting depth %d exceeds maximum of %d', $depth, $max),
            400,
        );
    }

    /**
     * The number of included relations exceeds the cap.
     */
    #[NoDiscard]
    public static function includesLimitExceeded(int $count, int $max): self
    {
        return new self(
            sprintf('Requested %d includes exceeds maximum of %d', $count, $max),
            400,
        );
    }

    /**
     * A domain entity was returned directly from a controller without transformation.
     */
    #[NoDiscard]
    public static function entitySerializationBanned(string $entityClass, string $correlationId): self
    {
        return new self(
            sprintf(
                'Entity "%s" returned directly from controller without API resource transformation (correlation: %s). '
                . 'Wrap the entity in an ApiResource subclass.',
                $entityClass,
                $correlationId,
            ),
            500,
        );
    }

    /**
     * A resource class is missing the #[ApiResource] attribute.
     */
    #[NoDiscard]
    public static function missingResourceAttribute(string $class): self
    {
        return new self(
            sprintf('Class "%s" extends ApiResource but is missing the #[ApiResource] attribute', $class),
        );
    }

    /**
     * The resource type identifier is empty.
     */
    #[NoDiscard]
    public static function emptyResourceType(string $class): self
    {
        return new self(
            sprintf('Resource type for class "%s" must not be empty', $class),
        );
    }

    /**
     * An unknown resource type was referenced.
     */
    #[NoDiscard]
    public static function unknownResource(string $resourceType): self
    {
        return new self(
            sprintf('Unknown resource type "%s"', $resourceType),
            400,
        );
    }

    /**
     * A filter was requested on an unregistered field.
     */
    #[NoDiscard]
    public static function unknownFilterField(string $field, string $resourceType): self
    {
        return new self(
            sprintf('Filter field "%s" is not registered for resource "%s"', $field, $resourceType),
            400,
        );
    }

    /**
     * An unknown filter operator was used.
     */
    #[NoDiscard]
    public static function unknownFilterOperator(string $operator, string $field): self
    {
        return new self(
            sprintf('Unknown filter operator "%s" for field "%s"', $operator, $field),
            400,
        );
    }

    /**
     * A filter operator is not allowed for the specified field.
     */
    #[NoDiscard]
    public static function invalidFilterOperator(string $operator, string $field, string $resourceType): self
    {
        return new self(
            sprintf(
                'Operator "%s" is not allowed for filter field "%s" on resource "%s"',
                $operator,
                $field,
                $resourceType,
            ),
            400,
        );
    }

    /**
     * A filter value does not match the expected type.
     */
    #[NoDiscard]
    public static function invalidFilterValue(string $value, string $expectedType, string $field): self
    {
        return new self(
            sprintf('Invalid value "%s" for filter field "%s" (expected %s)', $value, $field, $expectedType),
            400,
        );
    }

    /**
     * A filter requires authorization that the user lacks.
     */
    #[NoDiscard]
    public static function unauthorizedFilter(string $field, string $requiredRole): self
    {
        return new self(
            sprintf('Filter on field "%s" requires role "%s"', $field, $requiredRole),
            403,
        );
    }

    /**
     * A sort was requested on an unregistered field.
     */
    #[NoDiscard]
    public static function unknownSortField(string $field, string $resourceType): self
    {
        return new self(
            sprintf('Sort field "%s" is not registered for resource "%s"', $field, $resourceType),
            400,
        );
    }

    /**
     * A sort field requires authorization that the user lacks.
     */
    #[NoDiscard]
    public static function unauthorizedSort(string $field, string $requiredRole): self
    {
        return new self(
            sprintf('Sort on field "%s" requires role "%s"', $field, $requiredRole),
            403,
        );
    }

    /**
     * An unsupported API version was requested.
     */
    #[NoDiscard]
    public static function unsupportedVersion(string $version): self
    {
        return new self(
            sprintf('Unsupported API version "%s"', $version),
            400,
        );
    }

    /**
     * No acceptable content type was found from the Accept header.
     */
    #[NoDiscard]
    public static function notAcceptable(string $accept): self
    {
        return new self(
            sprintf('No acceptable response format for Accept header "%s"', $accept),
            406,
        );
    }
}
