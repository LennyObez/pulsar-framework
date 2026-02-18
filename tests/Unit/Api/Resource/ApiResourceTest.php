<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\Attribute\ApiResource;
use Pulsar\Api\Resource\Attribute\Expose;
use Pulsar\Api\Resource\ConditionalField;
use Pulsar\Api\Resource\RedactionRule;
use Pulsar\Api\Resource\RedactionStrategy;
use Pulsar\Api\Resource\ResourceMetadata;
use Pulsar\Api\Security\ClearanceSnapshot;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Tests\Unit\Api\Resource\Fixture\ConditionalFieldResource;
use Pulsar\Tests\Unit\Api\Resource\Fixture\NoAttributeResource;
use Pulsar\Tests\Unit\Api\Resource\Fixture\TestUserResource;

/**
 * Comprehensive tests for AbstractApiResource serialization:
 * nested resources, conditional fields, redaction metadata,
 * sparse fieldsets, and authorization combinations.
 */
#[CoversClass(AbstractApiResource::class)]
#[CoversClass(ResourceMetadata::class)]
final class ApiResourceTest extends TestCase
{
    // --- Nested resource tests ---

    #[Test]
    public function nestedResourceSerializesRecursively(): void
    {
        $address = new ApiResourceTestAddressResource();
        $address->city = 'New York';
        $address->zip = '10001';

        $user = new ApiResourceTestUserWithAddress();
        $user->id = 'u-1';
        $user->address = $address;

        $output = $user->toArray();

        self::assertSame('u-1', $output['id']);
        self::assertIsArray($output['address']);
        self::assertSame('New York', $output['address']['city']);
        self::assertSame('10001', $output['address']['zip']);
    }

    #[Test]
    public function nestedResourceRespectsFieldExposure(): void
    {
        $address = new ApiResourceTestAddressResource();
        $address->city = 'Boston';
        $address->zip = '02101';
        $address->internalCode = 'SEC-42';

        $user = new ApiResourceTestUserWithAddress();
        $user->id = 'u-2';
        $user->address = $address;

        $output = $user->toArray();

        self::assertIsArray($output['address']);
        self::assertArrayHasKey('city', $output['address']);
        self::assertArrayHasKey('zip', $output['address']);
        self::assertArrayNotHasKey('internalCode', $output['address'], 'Nested unexposed fields must be excluded');
    }

    #[Test]
    public function arrayOfResourcesSerializesEachElement(): void
    {
        $tag1 = new ApiResourceTestTagResource();
        $tag1->label = 'php';

        $tag2 = new ApiResourceTestTagResource();
        $tag2->label = 'api';

        $user = new ApiResourceTestUserWithTags();
        $user->id = 'u-3';
        $user->tags = [$tag1, $tag2];

        $output = $user->toArray();

        self::assertIsArray($output['tags']);
        self::assertCount(2, $output['tags']);
        self::assertIsArray($output['tags'][0]);
        self::assertSame('php', $output['tags'][0]['label']);
        self::assertIsArray($output['tags'][1]);
        self::assertSame('api', $output['tags'][1]['label']);
    }

    #[Test]
    public function nestedResourcePropagatesClearance(): void
    {
        $address = new ApiResourceTestClassifiedAddressResource();
        $address->city = 'Washington';
        $address->secretCode = 'TOP-SECRET';

        $user = new ApiResourceTestUserWithClassifiedAddress();
        $user->id = 'u-4';
        $user->address = $address;

        $clearance = ClearanceSnapshot::anonymous('snap-nest');

        $output = $user->toArray($clearance);

        self::assertIsArray($output['address']);
        self::assertArrayHasKey('city', $output['address']);
        self::assertArrayNotHasKey('secretCode', $output['address'], 'Classification must propagate to nested resources');
    }

    // --- Redaction metadata tests ---

    #[Test]
    public function redactionMetaNotPresentByDefault(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-5';
        $resource->email = 'hidden@example.com';

        $clearance = ClearanceSnapshot::anonymous('snap-meta');

        $output = $resource->toArray($clearance);

        self::assertArrayNotHasKey('_meta', $output, 'Redaction metadata must not leak to regular API consumers');
    }

    #[Test]
    public function redactionMetaPresentWhenAuditFlagEnabled(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-6';
        $resource->email = 'hidden@example.com';

        $clearance = ClearanceSnapshot::anonymous('snap-meta-2');

        $output = $resource->toArray($clearance, includeRedactionMeta: true);

        self::assertArrayHasKey('_meta', $output);
        self::assertIsArray($output['_meta']);
        self::assertArrayHasKey('redactions', $output['_meta']);
        self::assertIsArray($output['_meta']['redactions']);
        self::assertArrayHasKey('email', $output['_meta']['redactions']);
    }

    #[Test]
    public function redactionMetaTracksRedactionStrategy(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-7';
        $resource->email = 'user@example.com';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-strat',
            maxClassification: DataClassification::Internal,
            permissions: [],
            roles: [],
            authenticated: true,
        );

        $redactionRules = [
            'email' => new RedactionRule(
                appliesAbove: DataClassification::Internal,
                strategy: RedactionStrategy::Mask,
            ),
        ];

        $output = $resource->toArray($clearance, redactionRules: $redactionRules, includeRedactionMeta: true);

        self::assertSame('***', $output['email']);
        self::assertIsArray($output['_meta']);
        self::assertIsArray($output['_meta']['redactions']);
        self::assertSame('classification:mask', $output['_meta']['redactions']['email']);
    }

    // --- Conditional field tests ---

    #[Test]
    public function conditionalFieldWhenFactoryIncludesValue(): void
    {
        $resource = new ConditionalFieldResource();
        $resource->id = 'c-1';
        $resource->email = ConditionalField::when(true, 'yes@example.com');

        $output = $resource->toArray();

        self::assertSame('yes@example.com', $output['email']);
    }

    #[Test]
    public function conditionalFieldWhenFactoryExcludesValue(): void
    {
        $resource = new ConditionalFieldResource();
        $resource->id = 'c-2';
        $resource->email = ConditionalField::when(false, 'no@example.com');

        $output = $resource->toArray();

        self::assertArrayNotHasKey('email', $output);
    }

    #[Test]
    public function conditionalFieldUnlessFactoryIncludesValue(): void
    {
        $resource = new ConditionalFieldResource();
        $resource->id = 'c-3';
        $resource->email = ConditionalField::unless(false, 'included@example.com');

        $output = $resource->toArray();

        self::assertSame('included@example.com', $output['email']);
    }

    #[Test]
    public function conditionalFieldUnlessFactoryExcludesValue(): void
    {
        $resource = new ConditionalFieldResource();
        $resource->id = 'c-4';
        $resource->email = ConditionalField::unless(true, 'excluded@example.com');

        $output = $resource->toArray();

        self::assertArrayNotHasKey('email', $output);
    }

    // --- Sparse fieldset + authorization ---

    #[Test]
    public function sparseFieldsetOnlyIncludesRequestedFields(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-8';
        $resource->name = 'Alice';
        $resource->email = 'alice@example.com';

        $output = $resource->toArray(requestedFields: ['id']);

        self::assertArrayHasKey('id', $output);
        self::assertArrayNotHasKey('name', $output);
        self::assertArrayNotHasKey('email', $output);
    }

    #[Test]
    public function emptyRequestedFieldsReturnsEmptyArray(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-9';
        $resource->name = 'Bob';

        $output = $resource->toArray(requestedFields: []);

        self::assertSame([], $output);
    }

    // --- Missing attribute tests ---

    #[Test]
    public function resourceWithoutApiResourceAttributeThrows(): void
    {
        $this->expectException(ApiException::class);

        (void) ResourceMetadata::resolve(NoAttributeResource::class);
    }

    // --- Expose alias ---

    #[Test]
    public function exposeAliasUsesCustomName(): void
    {
        $resource = new ApiResourceTestAliasedResource();
        $resource->id = 'a-1';
        $resource->displayName = 'Test User';

        $output = $resource->toArray();

        self::assertArrayHasKey('full_name', $output);
        self::assertArrayNotHasKey('displayName', $output);
        self::assertSame('Test User', $output['full_name']);
    }

    // --- Allowlist from resource ---

    #[Test]
    public function allowlistMaxFieldsRespectsPerResourceOverride(): void
    {
        $resource = new ApiResourceTestMaxFieldsResource();
        $allowlist = $resource->allowlist(100);

        // The resource has maxFields=3 override
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);

        (void) $allowlist->validate(['id', 'name', 'email', 'extra']);
    }

    // --- Metadata resolution ---

    #[Test]
    public function metadataIsCachedPerInstance(): void
    {
        $resource = new TestUserResource();

        $meta1 = $resource->metadata();
        $meta2 = $resource->metadata();

        self::assertSame($meta1, $meta2);
    }

    #[Test]
    public function filterableFieldsReturnOnlyFilterable(): void
    {
        $resource = new ApiResourceTestFilterableResource();
        $metadata = $resource->metadata();

        $filterable = $metadata->filterableFields();

        self::assertArrayHasKey('name', $filterable);
        self::assertArrayNotHasKey('id', $filterable);
    }

    #[Test]
    public function sortableFieldsReturnOnlySortable(): void
    {
        $resource = new ApiResourceTestSortableResource();
        $metadata = $resource->metadata();

        $sortable = $metadata->sortableFields();

        self::assertArrayHasKey('createdAt', $sortable);
        self::assertArrayNotHasKey('id', $sortable);
    }
}

// --- Test fixture resources defined locally ---

#[ApiResource(type: 'address')]
final class ApiResourceTestAddressResource extends AbstractApiResource
{
    #[Expose]
    public string $city = '';

    #[Expose]
    public string $zip = '';

    public string $internalCode = '';
}

#[ApiResource(type: 'user_with_address')]
final class ApiResourceTestUserWithAddress extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    #[Expose]
    public ApiResourceTestAddressResource $address;
}

#[ApiResource(type: 'tag')]
final class ApiResourceTestTagResource extends AbstractApiResource
{
    #[Expose]
    public string $label = '';
}

#[ApiResource(type: 'user_with_tags')]
final class ApiResourceTestUserWithTags extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    /** @var list<ApiResourceTestTagResource> */
    #[Expose]
    public array $tags = [];
}

#[ApiResource(type: 'classified_address')]
final class ApiResourceTestClassifiedAddressResource extends AbstractApiResource
{
    #[Expose]
    public string $city = '';

    #[Expose(classification: DataClassification::Restricted)]
    public string $secretCode = '';
}

#[ApiResource(type: 'user_classified_address')]
final class ApiResourceTestUserWithClassifiedAddress extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    #[Expose]
    public ApiResourceTestClassifiedAddressResource $address;
}

#[ApiResource(type: 'aliased')]
final class ApiResourceTestAliasedResource extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    #[Expose(as: 'full_name')]
    public string $displayName = '';
}

#[ApiResource(type: 'max_fields', maxFields: 3)]
final class ApiResourceTestMaxFieldsResource extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    #[Expose]
    public string $name = '';

    #[Expose]
    public string $email = '';
}

#[ApiResource(type: 'filterable_test')]
final class ApiResourceTestFilterableResource extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    #[Expose]
    #[\Pulsar\Api\Resource\Attribute\Filterable]
    public string $name = '';
}

#[ApiResource(type: 'sortable_test')]
final class ApiResourceTestSortableResource extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    #[Expose]
    #[\Pulsar\Api\Resource\Attribute\Sortable]
    public string $createdAt = '';
}
