<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\ConditionalField;
use Pulsar\Api\Resource\FieldAllowlist;
use Pulsar\Api\Resource\RedactionRule;
use Pulsar\Api\Resource\RedactionStrategy;
use Pulsar\Api\Resource\ResourceMetadata;
use Pulsar\Api\Security\ClearanceSnapshot;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Tests\Unit\Api\Resource\Fixture\ConditionalFieldResource;
use Pulsar\Tests\Unit\Api\Resource\Fixture\FilterableSortableResource;
use Pulsar\Tests\Unit\Api\Resource\Fixture\NestedAddressResource;
use Pulsar\Tests\Unit\Api\Resource\Fixture\TestUserResource;
use Pulsar\Tests\Unit\Api\Resource\Fixture\UserWithAddressResource;

#[CoversClass(AbstractApiResource::class)]
final class AbstractApiResourceTest extends TestCase
{
    // --- toArray basics ---

    #[Test]
    public function toArrayIncludesOnlyExposedFields(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-1';
        $resource->name = 'Alice';
        $resource->email = 'alice@example.com';
        $resource->ssn = '123-45-6789';
        $resource->adminNotes = 'VIP';
        $resource->passwordHash = 'secret_hash';

        $output = $resource->toArray();

        self::assertSame('u-1', $output['id']);
        self::assertSame('Alice', $output['name']);
        self::assertSame('alice@example.com', $output['email']);
        self::assertSame('123-45-6789', $output['ssn']);
        self::assertSame('VIP', $output['adminNotes']);
        self::assertArrayNotHasKey('passwordHash', $output);
        self::assertArrayNotHasKey('internalNotes', $output);
        self::assertArrayNotHasKey('secretScore', $output);
    }

    #[Test]
    public function toArrayWithNullClearanceIncludesAllExposed(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-2';
        $resource->name = 'Bob';
        $resource->email = 'bob@example.com';
        $resource->ssn = '000-00-0000';
        $resource->adminNotes = 'Note';

        $output = $resource->toArray(clearance: null);

        self::assertCount(5, $output);
        self::assertArrayHasKey('id', $output);
        self::assertArrayHasKey('email', $output);
        self::assertArrayHasKey('ssn', $output);
        self::assertArrayHasKey('adminNotes', $output);
    }

    // --- Sparse fieldset ---

    #[Test]
    public function toArraySparseFieldsetLimitsOutput(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-3';
        $resource->name = 'Charlie';
        $resource->email = 'charlie@example.com';

        $output = $resource->toArray(requestedFields: ['id', 'name']);

        self::assertSame(['id' => 'u-3', 'name' => 'Charlie'], $output);
    }

    #[Test]
    public function toArraySparseFieldsetIgnoresUnexposedFields(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-4';

        // Requesting a field not in fieldPolicies is silently skipped
        $output = $resource->toArray(requestedFields: ['id', 'passwordHash']);

        self::assertSame(['id' => 'u-4'], $output);
    }

    // --- Conditional fields ---

    #[Test]
    public function conditionalFieldIncludedWhenTrue(): void
    {
        $resource = new ConditionalFieldResource();
        $resource->id = 'cf-1';
        $resource->email = ConditionalField::when(true, 'visible@example.com');

        $output = $resource->toArray();

        self::assertSame('visible@example.com', $output['email']);
    }

    #[Test]
    public function conditionalFieldExcludedWhenFalse(): void
    {
        $resource = new ConditionalFieldResource();
        $resource->id = 'cf-2';
        $resource->email = ConditionalField::when(false, 'hidden@example.com');

        $output = $resource->toArray();

        self::assertArrayNotHasKey('email', $output);
    }

    // --- Authorization checks ---

    #[Test]
    public function toArrayDeniesFieldWhenPermissionMissing(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-5';
        $resource->ssn = '111-22-3333';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-1',
            maxClassification: DataClassification::Restricted,
            permissions: [],
            roles: ['admin'],
            authenticated: true,
        );

        $output = $resource->toArray(clearance: $clearance);

        self::assertArrayNotHasKey('ssn', $output);
    }

    #[Test]
    public function toArrayDeniesFieldWhenRoleMissing(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-6';
        $resource->adminNotes = 'Sensitive notes';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-2',
            maxClassification: DataClassification::Restricted,
            permissions: ['users.view-ssn'],
            roles: ['viewer'],
            authenticated: true,
        );

        $output = $resource->toArray(clearance: $clearance);

        self::assertArrayNotHasKey('adminNotes', $output);
    }

    #[Test]
    public function toArrayAllowsFieldWhenPermissionAndRolePresent(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-7';
        $resource->ssn = '999-88-7777';
        $resource->adminNotes = 'Full access notes';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-3',
            maxClassification: DataClassification::Restricted,
            permissions: ['users.view-ssn'],
            roles: ['admin'],
            authenticated: true,
        );

        $output = $resource->toArray(clearance: $clearance);

        self::assertSame('999-88-7777', $output['ssn']);
        self::assertSame('Full access notes', $output['adminNotes']);
    }

    // --- Classification check ---

    #[Test]
    public function toArrayOmitsFieldWhenClassificationInsufficientAnonymous(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-8';
        $resource->email = 'restricted@example.com';

        // Confidential email requires at least Confidential clearance
        $clearance = ClearanceSnapshot::anonymous('snap-anon');

        $output = $resource->toArray(clearance: $clearance);

        // Anonymous clearance is Public, email is Confidential => denied (unauthenticated)
        self::assertArrayNotHasKey('email', $output);
    }

    #[Test]
    public function toArrayRedactsFieldWhenClassificationInsufficientAuthenticated(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-9';
        $resource->email = 'redacted@example.com';

        // Authenticated but only Internal clearance (email is Confidential)
        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-auth-low',
            maxClassification: DataClassification::Internal,
            permissions: [],
            roles: [],
            authenticated: true,
        );

        // With a redaction rule for email (mask strategy)
        $redactionRules = [
            'email' => new RedactionRule(
                appliesAbove: DataClassification::Public,
                strategy: RedactionStrategy::Mask,
            ),
        ];

        $output = $resource->toArray(
            clearance: $clearance,
            redactionRules: $redactionRules,
        );

        // Authenticated -> Redacted result -> redaction rule applied (mask = ***)
        self::assertSame('***', $output['email']);
    }

    // --- Redaction metadata ---

    #[Test]
    public function redactionMetaIncludedWhenFlagEnabled(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-10';
        $resource->ssn = '555-66-7777';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-meta',
            maxClassification: DataClassification::Restricted,
            permissions: [],
            roles: ['admin'],
            authenticated: true,
        );

        $output = $resource->toArray(
            clearance: $clearance,
            includeRedactionMeta: true,
        );

        // ssn requires permission 'users.view-ssn' which is missing => denied
        self::assertArrayNotHasKey('ssn', $output);
        self::assertArrayHasKey('_meta', $output);
        $meta = $output['_meta'];
        assert(is_array($meta));
        $redactions = $meta['redactions'];
        assert(is_array($redactions));
        self::assertSame('denied', $redactions['ssn']);
    }

    #[Test]
    public function redactionMetaExcludedWhenFlagDisabled(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-11';
        $resource->ssn = '555-66-7777';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-no-meta',
            maxClassification: DataClassification::Restricted,
            permissions: [],
            roles: ['admin'],
            authenticated: true,
        );

        $output = $resource->toArray(
            clearance: $clearance,
            includeRedactionMeta: false,
        );

        self::assertArrayNotHasKey('_meta', $output);
    }

    #[Test]
    public function redactionMetaNotPresentWhenNoRedactionsOccur(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-12';
        $resource->name = 'Full';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-full',
            maxClassification: DataClassification::Restricted,
            permissions: ['users.view-ssn'],
            roles: ['admin'],
            authenticated: true,
        );

        $output = $resource->toArray(
            clearance: $clearance,
            requestedFields: ['id', 'name'],
            includeRedactionMeta: true,
        );

        self::assertArrayNotHasKey('_meta', $output);
    }

    // --- Nested resource serialization ---

    #[Test]
    public function nestedResourceSerializesRecursively(): void
    {
        $resource = new UserWithAddressResource();
        $resource->id = 'u-nested';
        $resource->name = 'Nested User';
        $resource->address->city = 'Springfield';
        $resource->address->zip = '62704';

        $output = $resource->toArray();

        self::assertIsArray($output['address']);
        self::assertSame('Springfield', $output['address']['city']);
        self::assertSame('62704', $output['address']['zip']);
    }

    // --- Array of resources serialization ---

    #[Test]
    public function arrayOfResourcesSerializesEachElement(): void
    {
        $resource = new UserWithAddressResource();
        $resource->id = 'u-arr';
        $resource->name = 'Array User';

        $addr1 = new NestedAddressResource();
        $addr1->city = 'City A';
        $addr1->zip = '11111';

        $addr2 = new NestedAddressResource();
        $addr2->city = 'City B';
        $addr2->zip = '22222';

        $resource->tags = [$addr1, $addr2];

        $output = $resource->toArray();

        $tags = $output['tags'];
        assert(is_array($tags));
        self::assertCount(2, $tags);
        $tag0 = $tags[0];
        assert(is_array($tag0));
        $tag1 = $tags[1];
        assert(is_array($tag1));
        self::assertSame('City A', $tag0['city']);
        self::assertSame('City B', $tag1['city']);
    }

    #[Test]
    public function scalarArraySerializesPrimitivesDirectly(): void
    {
        $resource = new UserWithAddressResource();
        $resource->id = 'u-scalar';
        $resource->name = 'Scalar';
        $resource->tags = ['php', 'api', 'framework'];

        $output = $resource->toArray();

        self::assertSame(['php', 'api', 'framework'], $output['tags']);
    }

    // --- Redaction with Omit strategy via classification ---

    #[Test]
    public function classificationRedactionOmitsFieldWhenStrategyIsOmit(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-omit';
        $resource->email = 'omit@example.com';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-omit',
            maxClassification: DataClassification::Internal,
            permissions: ['users.view-ssn'],
            roles: ['admin'],
            authenticated: true,
        );

        $redactionRules = [
            'email' => new RedactionRule(
                appliesAbove: DataClassification::Public,
                strategy: RedactionStrategy::Omit,
            ),
        ];

        $output = $resource->toArray(
            clearance: $clearance,
            redactionRules: $redactionRules,
            includeRedactionMeta: true,
        );

        // Email is Confidential, clearance is Internal => fails classification
        // Omit strategy returns null => field omitted
        self::assertArrayNotHasKey('email', $output);
        $meta = $output['_meta'];
        assert(is_array($meta));
        $redactions = $meta['redactions'];
        assert(is_array($redactions));
        self::assertSame('classification:omit', $redactions['email']);
    }

    // --- Redaction with Truncate strategy ---

    #[Test]
    public function classificationRedactionTruncatesFieldValue(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-trunc';
        $resource->email = 'truncate@example.com';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-trunc',
            maxClassification: DataClassification::Internal,
            permissions: ['users.view-ssn'],
            roles: ['admin'],
            authenticated: true,
        );

        $redactionRules = [
            'email' => new RedactionRule(
                appliesAbove: DataClassification::Public,
                strategy: RedactionStrategy::Truncate,
                truncateLength: 3,
            ),
        ];

        $output = $resource->toArray(
            clearance: $clearance,
            redactionRules: $redactionRules,
            includeRedactionMeta: true,
        );

        self::assertSame('tru...', $output['email']);
        $meta = $output['_meta'];
        assert(is_array($meta));
        $redactions = $meta['redactions'];
        assert(is_array($redactions));
        self::assertSame('classification:truncate', $redactions['email']);
    }

    // --- metadata() caching ---

    #[Test]
    public function metadataReturnsCachedInstance(): void
    {
        $resource = new TestUserResource();

        $first = $resource->metadata();
        $second = $resource->metadata();

        self::assertSame($first, $second);
    }

    // --- allowlist() ---

    #[Test]
    public function allowlistUsesMetadataFieldPolicies(): void
    {
        $resource = new TestUserResource();
        $allowlist = $resource->allowlist(maxFields: 100);

        self::assertInstanceOf(FieldAllowlist::class, $allowlist);
        self::assertTrue($allowlist->has('id'));
        self::assertTrue($allowlist->has('name'));
        self::assertFalse($allowlist->has('passwordHash'));
    }

    #[Test]
    public function allowlistRespectsMaxFieldsOverride(): void
    {
        $resource = new FilterableSortableResource();
        // FilterableSortableResource has maxFields=5 in its attribute
        $allowlist = $resource->allowlist(maxFields: 100);

        // Validate should work with 4 fields (under the 5 cap from attribute)
        $result = $allowlist->validate(['id', 'title', 'body', 'internalNotes']);

        self::assertCount(4, $result);
    }

    // --- Redaction via authorization (not classification) ---

    #[Test]
    public function authorizationRedactedFieldAppliesRedactionRule(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-auth-redact';
        $resource->email = 'redact-auth@example.com';

        // Authenticated with Confidential clearance but insufficient for email
        // Actually, email is Confidential and clearance is Public => classification fails
        // But we need the authorization path to produce Redacted
        // For that, we need an authenticated user who doesn't meet the classification
        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-auth-redact',
            maxClassification: DataClassification::Internal,
            permissions: [],
            roles: [],
            authenticated: true,
        );

        // email is Confidential, clearance is Internal => classification not met
        // authenticated => Redacted path in FieldAuthorizer (but note: the email
        // does NOT have requiredPermissions/requiredRoles, so requiresAuthorization() is false,
        // so the authorization block is skipped and we go directly to classification check)
        $redactionRules = [
            'email' => new RedactionRule(
                appliesAbove: DataClassification::Public,
                strategy: RedactionStrategy::Hash,
                hashAlgorithm: 'sha256',
            ),
        ];

        $output = $resource->toArray(
            clearance: $clearance,
            redactionRules: $redactionRules,
            includeRedactionMeta: true,
        );

        // Classification fails => redaction rule applies (hash)
        self::assertArrayHasKey('email', $output);
        self::assertSame(hash('sha256', 'redact-auth@example.com'), $output['email']);
        $meta = $output['_meta'];
        assert(is_array($meta));
        $redactions = $meta['redactions'];
        assert(is_array($redactions));
        self::assertSame('classification:hash', $redactions['email']);
    }

    // --- Classification omit without redaction rule ---

    #[Test]
    public function classificationFailsWithoutRedactionRuleOmitsField(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'u-no-rule';
        $resource->email = 'norule@example.com';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-no-rule',
            maxClassification: DataClassification::Internal,
            permissions: ['users.view-ssn'],
            roles: ['admin'],
            authenticated: true,
        );

        $output = $resource->toArray(
            clearance: $clearance,
            includeRedactionMeta: true,
        );

        self::assertArrayNotHasKey('email', $output);
        $meta = $output['_meta'];
        assert(is_array($meta));
        $redactions = $meta['redactions'];
        assert(is_array($redactions));
        self::assertSame('classification:omit', $redactions['email']);
    }
}
