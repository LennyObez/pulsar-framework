<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Security\ClearanceSnapshot;
use Pulsar\Api\Security\FieldAuthorizationResult;
use Pulsar\Api\Security\FieldAuthorizer;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Abstract base class for API resources.
 *
 * Resources declare exposed fields via {@see Attribute\Expose} attribute.
 * Fields without `#[Expose]` are NEVER serialized (deny-by-default).
 *
 * Subclasses define public properties with `#[Expose]` to declare the API shape.
 * The `toArray()` method only includes exposed fields, respecting authorization
 * and classification clearance from the {@see ClearanceSnapshot}.
 */
#[Api(since: '1.0.0')]
abstract class AbstractApiResource
{
    private ?ResourceMetadata $resolvedMetadata = null;

    /**
     * Serialize the resource to an array, respecting field exposure rules.
     *
     * Only fields marked with `#[Expose]` are included. Fields that fail
     * authorization checks are silently omitted. Conditional fields are
     * evaluated and included only when their condition is met.
     *
     * @param ClearanceSnapshot|null $clearance Requester's clearance (null = anonymous/Public-only)
     * @param list<string>|null $requestedFields Sparse fieldset (null = all exposed fields)
     * @param array<string, RedactionRule> $redactionRules Field name => redaction rule
     * @param bool $includeRedactionMeta Whether to include `_meta.redactions` in the output.
     *     Only enable for callers with debug/audit privileges. Regular API consumers
     *     must never see which fields were omitted — that leaks hidden field names.
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(
        ?ClearanceSnapshot $clearance = null,
        ?array $requestedFields = null,
        array $redactionRules = [],
        bool $includeRedactionMeta = false,
    ): array {
        $metadata = $this->metadata();
        $authorizer = new FieldAuthorizer();
        $output = [];
        $redactions = [];

        $fieldsToSerialize = $requestedFields ?? $metadata->exposedFieldNames();

        foreach ($fieldsToSerialize as $fieldName) {
            $policy = $metadata->fieldPolicies[$fieldName] ?? null;

            if ($policy === null) {
                // Field not in the policy map — skip silently
                continue;
            }

            // Read the property value
            $propertyName = $policy->propertyName;

            if (!isset($this->{$propertyName}) && !property_exists($this, $propertyName)) {
                continue;
            }

            $value = $this->{$propertyName};

            // Handle conditional fields
            if ($value instanceof ConditionalField) {
                if (!$value->include) {
                    continue;
                }

                $value = $value->value;
            }

            // Authorization check
            if ($clearance !== null && $policy->requiresAuthorization()) {
                $authResult = $authorizer->authorize($policy, $clearance);

                if ($authResult === FieldAuthorizationResult::Denied) {
                    $redactions[$fieldName] = 'denied';
                    continue;
                }

                if ($authResult === FieldAuthorizationResult::Redacted) {
                    if (array_key_exists($fieldName, $redactionRules) && is_string($value)) {
                        $redacted = $redactionRules[$fieldName]->apply($value);

                        if ($redacted === null) {
                            $redactions[$fieldName] = 'redacted:omit';
                            continue;
                        }

                        $output[$fieldName] = $redacted;
                        $redactions[$fieldName] = 'redacted:' . $redactionRules[$fieldName]->strategy->value;
                        continue;
                    }

                    $redactions[$fieldName] = 'redacted:omit';
                    continue;
                }
            }

            // Classification check (when clearance is provided)
            if ($clearance !== null && !$clearance->meetsClassification($policy->classification)) {
                if (array_key_exists($fieldName, $redactionRules) && is_string($value)) {
                    $redacted = $redactionRules[$fieldName]->apply($value);

                    if ($redacted === null) {
                        $redactions[$fieldName] = 'classification:omit';
                        continue;
                    }

                    $output[$fieldName] = $redacted;
                    $redactions[$fieldName] = 'classification:' . $redactionRules[$fieldName]->strategy->value;
                    continue;
                }

                $redactions[$fieldName] = 'classification:omit';
                continue;
            }

            // Recursively serialize nested resources
            if ($value instanceof self) {
                $output[$fieldName] = $value->toArray($clearance, null, $redactionRules, $includeRedactionMeta);
                continue;
            }

            // Serialize arrays of resources
            if (is_array($value)) {
                $output[$fieldName] = self::serializeArray($value, $clearance, $redactionRules, $includeRedactionMeta);
                continue;
            }

            $output[$fieldName] = $value;
        }

        // Attach redaction metadata only when the debug/audit flag is enabled.
        // Regular API consumers must never see this — it leaks hidden field names.
        if ($includeRedactionMeta && $redactions !== []) {
            $output['_meta'] = ['redactions' => $redactions];
        }

        return $output;
    }

    /**
     * Get the resolved metadata for this resource.
     */
    #[NoDiscard]
    public function metadata(): ResourceMetadata
    {
        return $this->resolvedMetadata ??= ResourceMetadata::resolve(static::class);
    }

    /**
     * Build a field allowlist for this resource.
     */
    #[NoDiscard]
    public function allowlist(int $maxFields): FieldAllowlist
    {
        $metadata = $this->metadata();

        return new FieldAllowlist(
            fieldPolicies: $metadata->fieldPolicies,
            resourceType: $metadata->resourceType,
            maxFields: $metadata->maxFieldsOverride ?? $maxFields,
        );
    }

    /**
     * Serialize an array, recursively handling nested resources.
     *
     * @param array<array-key, mixed> $items
     * @param array<string, RedactionRule> $redactionRules
     *
     * @return array<array-key, mixed>
     */
    private static function serializeArray(
        array $items,
        ?ClearanceSnapshot $clearance,
        array $redactionRules,
        bool $includeRedactionMeta,
    ): array {
        $output = [];

        foreach ($items as $key => $item) {
            if ($item instanceof self) {
                $output[$key] = $item->toArray($clearance, null, $redactionRules, $includeRedactionMeta);
            } elseif (is_array($item)) {
                $output[$key] = self::serializeArray($item, $clearance, $redactionRules, $includeRedactionMeta);
            } else {
                $output[$key] = $item;
            }
        }

        return $output;
    }
}
