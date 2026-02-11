<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Resource\FieldPolicy;
use Pulsar\Api\Resource\ResourceMetadata;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Tests\Unit\Api\Resource\Fixture\FilterableSortableResource;
use Pulsar\Tests\Unit\Api\Resource\Fixture\NoAttributeResource;
use Pulsar\Tests\Unit\Api\Resource\Fixture\TestUserResource;
use Pulsar\Tests\Unit\Api\Resource\Fixture\TypeAliasResource;

#[CoversClass(ResourceMetadata::class)]
final class ResourceMetadataTest extends TestCase
{
    // --- resolve() basics ---

    #[Test]
    public function resolveSetsResourceTypeFromAttribute(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);

        self::assertSame('users', $metadata->resourceType);
    }

    #[Test]
    public function resolveSetsResourceClass(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);

        self::assertSame(TestUserResource::class, $metadata->resourceClass);
    }

    #[Test]
    public function resolveUsesShortNameWhenTypeIsEmpty(): void
    {
        $metadata = ResourceMetadata::resolve(TypeAliasResource::class);

        self::assertSame('TypeAliasResource', $metadata->resourceType);
    }

    #[Test]
    public function resolveMissingApiResourceAttributeThrows(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/missing the #\[ApiResource\]/');

        $_ = ResourceMetadata::resolve(NoAttributeResource::class);
    }

    // --- Field policies from #[Expose] ---

    #[Test]
    public function resolveCollectsExposedFieldsOnly(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);
        $names = $metadata->exposedFieldNames();

        self::assertContains('id', $names);
        self::assertContains('name', $names);
        self::assertContains('email', $names);
        self::assertContains('ssn', $names);
        self::assertContains('adminNotes', $names);
        self::assertNotContains('passwordHash', $names);
        self::assertNotContains('internalNotes', $names);
        self::assertNotContains('secretScore', $names);
    }

    #[Test]
    public function resolveFieldPolicyHasCorrectPropertyName(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);
        $policy = $metadata->fieldPolicies['id'];

        self::assertSame('id', $policy->name);
        self::assertSame('id', $policy->propertyName);
    }

    #[Test]
    public function resolveFieldPolicyRespectsAliasViaAs(): void
    {
        $metadata = ResourceMetadata::resolve(FilterableSortableResource::class);

        // The property is articleTitle, but alias is 'title'
        self::assertArrayHasKey('title', $metadata->fieldPolicies);
        self::assertSame('articleTitle', $metadata->fieldPolicies['title']->propertyName);
    }

    // --- Classification ---

    #[Test]
    public function resolveResourceLevelClassification(): void
    {
        $metadata = ResourceMetadata::resolve(FilterableSortableResource::class);

        self::assertSame(DataClassification::Internal, $metadata->resourceClassification);
    }

    #[Test]
    public function resolveDefaultResourceClassificationIsPublic(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);

        self::assertSame(DataClassification::Public, $metadata->resourceClassification);
    }

    #[Test]
    public function resolveFieldLevelClassificationOverridesExpose(): void
    {
        $metadata = ResourceMetadata::resolve(FilterableSortableResource::class);
        $policy = $metadata->fieldPolicies['internalNotes'];

        self::assertSame(DataClassification::Confidential, $policy->classification);
    }

    #[Test]
    public function resolveDefaultFieldClassificationIsPublic(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);
        $policy = $metadata->fieldPolicies['id'];

        self::assertSame(DataClassification::Public, $policy->classification);
    }

    // --- Required permissions and roles ---

    #[Test]
    public function resolveFieldPolicyWithRequiredPermissions(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);
        $policy = $metadata->fieldPolicies['ssn'];

        self::assertSame(['users.view-ssn'], $policy->requiredPermissions);
        self::assertTrue($policy->requiresAuthorization());
    }

    #[Test]
    public function resolveFieldPolicyWithRequiredRoles(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);
        $policy = $metadata->fieldPolicies['adminNotes'];

        self::assertSame(['admin'], $policy->requiredRoles);
        self::assertTrue($policy->requiresAuthorization());
    }

    #[Test]
    public function resolveFieldPolicyWithNoRequirements(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);
        $policy = $metadata->fieldPolicies['id'];

        self::assertSame([], $policy->requiredPermissions);
        self::assertSame([], $policy->requiredRoles);
        self::assertFalse($policy->requiresAuthorization());
    }

    // --- Filterable and sortable ---

    #[Test]
    public function resolveFilterableFields(): void
    {
        $metadata = ResourceMetadata::resolve(FilterableSortableResource::class);
        $filterable = $metadata->filterableFields();

        self::assertArrayHasKey('id', $filterable);
        self::assertArrayHasKey('title', $filterable);
        self::assertArrayNotHasKey('body', $filterable);
    }

    #[Test]
    public function resolveFilterableFieldOperators(): void
    {
        $metadata = ResourceMetadata::resolve(FilterableSortableResource::class);

        self::assertSame(['eq', 'in'], $metadata->fieldPolicies['id']->filterOperators);
        self::assertSame(['eq', 'contains', 'starts_with'], $metadata->fieldPolicies['title']->filterOperators);
    }

    #[Test]
    public function resolveSortableFields(): void
    {
        $metadata = ResourceMetadata::resolve(FilterableSortableResource::class);
        $sortable = $metadata->sortableFields();

        self::assertArrayHasKey('id', $sortable);
        self::assertArrayHasKey('title', $sortable);
        self::assertArrayNotHasKey('body', $sortable);
    }

    #[Test]
    public function resolveNonFilterableFieldHasEmptyOperators(): void
    {
        $metadata = ResourceMetadata::resolve(FilterableSortableResource::class);

        self::assertFalse($metadata->fieldPolicies['body']->filterable);
        self::assertSame([], $metadata->fieldPolicies['body']->filterOperators);
    }

    // --- maxFieldsOverride ---

    #[Test]
    public function resolveMaxFieldsOverrideFromAttribute(): void
    {
        $metadata = ResourceMetadata::resolve(FilterableSortableResource::class);

        self::assertSame(5, $metadata->maxFieldsOverride);
    }

    #[Test]
    public function resolveMaxFieldsOverrideIsNullWhenNotSet(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);

        self::assertNull($metadata->maxFieldsOverride);
    }

    // --- exposedFieldNames ---

    #[Test]
    public function exposedFieldNamesReturnsList(): void
    {
        $metadata = ResourceMetadata::resolve(FilterableSortableResource::class);
        $names = $metadata->exposedFieldNames();

        self::assertSame(['id', 'title', 'body', 'internalNotes'], $names);
    }

    // --- Empty results for resources with no filterable/sortable ---

    #[Test]
    public function filterableFieldsReturnsEmptyWhenNoneFilterable(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);

        self::assertSame([], $metadata->filterableFields());
    }

    #[Test]
    public function sortableFieldsReturnsEmptyWhenNoneSortable(): void
    {
        $metadata = ResourceMetadata::resolve(TestUserResource::class);

        self::assertSame([], $metadata->sortableFields());
    }
}
