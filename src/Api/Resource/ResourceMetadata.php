<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Resource\Attribute\ApiResource as ApiResourceAttribute;
use Pulsar\Api\Resource\Attribute\ClassificationTag;
use Pulsar\Api\Resource\Attribute\Expose;
use Pulsar\Api\Resource\Attribute\Filterable;
use Pulsar\Api\Resource\Attribute\Sortable;
use Pulsar\Security\Compliance\DataClassification;
use ReflectionClass;
use ReflectionProperty;

use function array_keys;

/**
 * Resolved metadata for an API resource class.
 *
 * Computed from class/property attributes and cached. Provides the
 * field policies, resource type, and complexity constraints needed
 * by the serialization pipeline.
 */
#[Api(since: '1.0.0')]
final readonly class ResourceMetadata
{
    /**
     * @param string $resourceType The resource type identifier
     * @param class-string $resourceClass The fully-qualified resource class name
     * @param array<string, FieldPolicy> $fieldPolicies Map of output field name => policy
     * @param int|null $maxFieldsOverride Per-resource max-fields override (null = use global default)
     * @param DataClassification $resourceClassification Resource-level classification tag
     */
    public function __construct(
        public string $resourceType,
        public string $resourceClass,
        public array $fieldPolicies,
        public ?int $maxFieldsOverride,
        public DataClassification $resourceClassification,
    ) {}

    /**
     * Resolve metadata from a resource class using reflection.
     *
     * @param class-string $class
     *
     * @throws ApiException If the class is missing the #[ApiResource] attribute
     */
    #[NoDiscard]
    public static function resolve(string $class): self
    {
        $reflection = new ReflectionClass($class);

        // Resolve #[ApiResource] attribute
        $resourceAttrs = $reflection->getAttributes(ApiResourceAttribute::class);

        if ($resourceAttrs === []) {
            throw ApiException::missingResourceAttribute($class);
        }

        /** @var ApiResourceAttribute $resourceAttr */
        $resourceAttr = $resourceAttrs[0]->newInstance();

        $resourceType = $resourceAttr->type !== '' ? $resourceAttr->type : $reflection->getShortName();

        // Resolve resource-level classification
        $classificationAttrs = $reflection->getAttributes(ClassificationTag::class);
        $resourceClassification = $classificationAttrs !== []
            ? $classificationAttrs[0]->newInstance()->level
            : DataClassification::Public;

        // Resolve field policies from properties with #[Expose]
        $fieldPolicies = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $exposeAttrs = $property->getAttributes(Expose::class);

            if ($exposeAttrs === []) {
                // Deny-by-default: skip fields without #[Expose]
                continue;
            }

            /** @var Expose $expose */
            $expose = $exposeAttrs[0]->newInstance();

            $outputName = $expose->as !== '' ? $expose->as : $property->getName();

            // Resolve per-field classification (field-level overrides resource-level)
            $fieldClassificationAttrs = $property->getAttributes(ClassificationTag::class);
            $fieldClassification = $fieldClassificationAttrs !== []
                ? $fieldClassificationAttrs[0]->newInstance()->level
                : $expose->classification;

            // Resolve filter/sort
            $filterableAttrs = $property->getAttributes(Filterable::class);
            $filterable = $filterableAttrs !== [];
            $filterOperators = $filterable ? $filterableAttrs[0]->newInstance()->operators : [];

            $sortableAttrs = $property->getAttributes(Sortable::class);
            $sortable = $sortableAttrs !== [];

            $fieldPolicies[$outputName] = new FieldPolicy(
                name: $outputName,
                propertyName: $property->getName(),
                classification: $fieldClassification,
                requiredPermissions: $expose->requiredPermissions,
                requiredRoles: $expose->requiredRoles,
                filterable: $filterable,
                filterOperators: $filterOperators,
                sortable: $sortable,
            );
        }

        return new self(
            resourceType: $resourceType,
            resourceClass: $class,
            fieldPolicies: $fieldPolicies,
            maxFieldsOverride: $resourceAttr->maxFields,
            resourceClassification: $resourceClassification,
        );
    }

    /**
     * Get all exposed field names.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function exposedFieldNames(): array
    {
        return array_keys($this->fieldPolicies);
    }

    /**
     * Get filterable field policies only.
     *
     * @return array<string, FieldPolicy>
     */
    #[NoDiscard]
    public function filterableFields(): array
    {
        return array_filter($this->fieldPolicies, static fn(FieldPolicy $policy): bool => $policy->filterable);
    }

    /**
     * Get sortable field policies only.
     *
     * @return array<string, FieldPolicy>
     */
    #[NoDiscard]
    public function sortableFields(): array
    {
        return array_filter($this->fieldPolicies, static fn(FieldPolicy $policy): bool => $policy->sortable);
    }
}
