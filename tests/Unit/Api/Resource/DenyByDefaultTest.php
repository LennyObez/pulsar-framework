<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\ConditionalField;
use Pulsar\Api\Resource\RedactionRule;
use Pulsar\Api\Resource\RedactionStrategy;
use Pulsar\Api\Security\ClearanceSnapshot;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Tests\Unit\Api\Resource\Fixture\TestUserResource;

/**
 * CRITICAL SECURITY TEST: Validates Finding E deny-by-default invariant.
 *
 * This test verifies that fields without #[Expose] are NEVER serialized,
 * regardless of input, clearance, or configuration. This is the primary
 * defense against accidental data leakage.
 */
#[CoversClass(AbstractApiResource::class)]
final class DenyByDefaultTest extends TestCase
{
    #[Test]
    public function unexposedFieldsAreNeverSerialized(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'user-1';
        $resource->name = 'John Doe';
        $resource->passwordHash = 'bcrypt_hash_value';
        $resource->internalNotes = 'Secret internal notes';
        $resource->secretScore = 99;

        $output = $resource->toArray();

        // Exposed fields ARE present
        self::assertArrayHasKey('id', $output);
        self::assertArrayHasKey('name', $output);

        // Non-exposed fields MUST NEVER appear
        self::assertArrayNotHasKey('passwordHash', $output, 'passwordHash must never be serialized');
        self::assertArrayNotHasKey('internalNotes', $output, 'internalNotes must never be serialized');
        self::assertArrayNotHasKey('secretScore', $output, 'secretScore must never be serialized');

        // Also verify they don't appear under alternate casing
        self::assertArrayNotHasKey('password_hash', $output);
        self::assertArrayNotHasKey('internal_notes', $output);
        self::assertArrayNotHasKey('secret_score', $output);
    }

    #[Test]
    public function unexposedFieldsNeverAppearEvenWithFullClearance(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'user-1';
        $resource->name = 'John';
        $resource->passwordHash = 'hashed';
        $resource->internalNotes = 'notes';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-1',
            maxClassification: DataClassification::Restricted,
            permissions: ['users.view-ssn', 'admin.full-access'],
            roles: ['admin', 'superadmin'],
            authenticated: true,
        );

        $output = $resource->toArray($clearance);

        self::assertArrayNotHasKey('passwordHash', $output, 'Full clearance must not bypass deny-by-default');
        self::assertArrayNotHasKey('internalNotes', $output, 'Full clearance must not bypass deny-by-default');
        self::assertArrayNotHasKey('secretScore', $output);
    }

    #[Test]
    public function unexposedFieldsNeverAppearInSparseFieldset(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'user-1';
        $resource->passwordHash = 'hashed';

        // Even if someone requests passwordHash explicitly, it must not appear
        $output = $resource->toArray(requestedFields: ['id', 'passwordHash']);

        self::assertArrayHasKey('id', $output);
        self::assertArrayNotHasKey('passwordHash', $output, 'Sparse fieldset must not bypass deny-by-default');
    }

    #[Test]
    public function onlyExposedFieldsAppearByDefault(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'user-1';
        $resource->name = 'Jane';
        $resource->email = 'jane@example.com';
        $resource->ssn = '123-45-6789';
        $resource->adminNotes = 'Admin only content';

        $output = $resource->toArray();

        // All exposed fields should be present (authorization is not checked without clearance)
        $exposedKeys = ['id', 'name', 'email', 'ssn', 'adminNotes'];

        foreach ($exposedKeys as $key) {
            self::assertArrayHasKey($key, $output, "Exposed field '{$key}' should be present");
        }
    }

    #[Test]
    public function classificationFilteringOmitsHighClassificationFields(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'user-1';
        $resource->name = 'Jane';
        $resource->email = 'jane@example.com';

        // Public-only clearance should omit Confidential email
        $clearance = ClearanceSnapshot::anonymous('snap-anon');

        $output = $resource->toArray($clearance);

        self::assertArrayHasKey('id', $output);
        self::assertArrayHasKey('name', $output);
        self::assertArrayNotHasKey('email', $output, 'Confidential field must be omitted for Public-only clearance');
    }

    #[Test]
    public function permissionGatedFieldOmittedWithoutPermission(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'user-1';
        $resource->ssn = '123-45-6789';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-2',
            maxClassification: DataClassification::Restricted,
            permissions: [],
            roles: ['user'],
            authenticated: true,
        );

        $output = $resource->toArray($clearance);

        self::assertArrayHasKey('id', $output);
        self::assertArrayNotHasKey('ssn', $output, 'SSN must be denied without users.view-ssn permission');
    }

    #[Test]
    public function permissionGatedFieldIncludedWithPermission(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'user-1';
        $resource->ssn = '123-45-6789';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-3',
            maxClassification: DataClassification::Restricted,
            permissions: ['users.view-ssn'],
            roles: ['admin'],
            authenticated: true,
        );

        $output = $resource->toArray($clearance);

        self::assertArrayHasKey('ssn', $output);
        self::assertSame('123-45-6789', $output['ssn']);
    }

    #[Test]
    public function roleGatedFieldOmittedWithoutRole(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'user-1';
        $resource->adminNotes = 'Admin content';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-4',
            maxClassification: DataClassification::Restricted,
            permissions: [],
            roles: ['user', 'editor'],
            authenticated: true,
        );

        $output = $resource->toArray($clearance);

        self::assertArrayNotHasKey('adminNotes', $output, 'Admin notes must be denied without admin role');
    }

    #[Test]
    public function roleGatedFieldIncludedWithRole(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'user-1';
        $resource->adminNotes = 'Admin content';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-5',
            maxClassification: DataClassification::Restricted,
            permissions: [],
            roles: ['admin'],
            authenticated: true,
        );

        $output = $resource->toArray($clearance);

        self::assertArrayHasKey('adminNotes', $output);
        self::assertSame('Admin content', $output['adminNotes']);
    }

    #[Test]
    public function conditionalFieldIncludedWhenTrue(): void
    {
        $resource = new Fixture\ConditionalFieldResource();
        $resource->id = 'user-1';
        $resource->email = new ConditionalField(
            value: 'user@example.com',
            include: true,
        );

        $output = $resource->toArray();

        self::assertSame('user@example.com', $output['email']);
    }

    #[Test]
    public function conditionalFieldExcludedWhenFalse(): void
    {
        $resource = new Fixture\ConditionalFieldResource();
        $resource->id = 'user-1';
        $resource->email = new ConditionalField(
            value: 'user@example.com',
            include: false,
        );

        $output = $resource->toArray();

        self::assertArrayNotHasKey('email', $output);
    }

    #[Test]
    public function redactionRulesApplyMasking(): void
    {
        $resource = new TestUserResource();
        $resource->id = 'user-1';
        $resource->email = 'sensitive@example.com';

        // Authenticated user with Internal clearance — Confidential email gets redacted
        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-redact',
            maxClassification: DataClassification::Internal,
            permissions: [],
            roles: [],
            authenticated: true,
        );

        $redactionRules = [
            'email' => new RedactionRule(
                appliesAbove: DataClassification::Internal,
                strategy: RedactionStrategy::Mask,
                maskChar: '*',
            ),
        ];

        $output = $resource->toArray($clearance, redactionRules: $redactionRules);

        // Email should be masked, not the raw value
        self::assertArrayHasKey('email', $output);
        self::assertSame('***', $output['email']);
    }

    #[Test]
    public function metadataResolvesExposedFieldNamesOnly(): void
    {
        $resource = new TestUserResource();
        $metadata = $resource->metadata();

        $fieldNames = $metadata->exposedFieldNames();

        // Only exposed fields should appear
        self::assertContains('id', $fieldNames);
        self::assertContains('name', $fieldNames);
        self::assertContains('email', $fieldNames);
        self::assertContains('ssn', $fieldNames);
        self::assertContains('adminNotes', $fieldNames);

        // Non-exposed fields must NOT appear
        self::assertNotContains('passwordHash', $fieldNames);
        self::assertNotContains('internalNotes', $fieldNames);
        self::assertNotContains('secretScore', $fieldNames);
    }

    #[Test]
    public function resourceTypeResolvesFromAttribute(): void
    {
        $resource = new TestUserResource();
        $metadata = $resource->metadata();

        self::assertSame('users', $metadata->resourceType);
    }
}
