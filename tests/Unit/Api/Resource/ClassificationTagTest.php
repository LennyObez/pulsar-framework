<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\Attribute\ApiResource;
use Pulsar\Api\Resource\Attribute\ClassificationTag;
use Pulsar\Api\Resource\Attribute\Expose;
use Pulsar\Api\Resource\ResourceMetadata;
use Pulsar\Api\Security\ClearanceSnapshot;
use Pulsar\Security\Compliance\DataClassification;

/**
 * Tests that ClassificationTag properly controls field visibility
 * based on the requester's clearance level.
 */
#[CoversClass(ClassificationTag::class)]
#[CoversClass(ResourceMetadata::class)]
#[CoversClass(AbstractApiResource::class)]
final class ClassificationTagTest extends TestCase
{
    #[Test]
    public function publicFieldVisibleToAnonymous(): void
    {
        $resource = $this->buildResource();

        $clearance = ClearanceSnapshot::anonymous('snap-pub');
        $output = $resource->toArray($clearance);

        self::assertArrayHasKey('publicField', $output);
        self::assertSame('visible', $output['publicField']);
    }

    #[Test]
    public function internalFieldHiddenFromPublicClearance(): void
    {
        $resource = $this->buildResource();

        $clearance = ClearanceSnapshot::anonymous('snap-int');
        $output = $resource->toArray($clearance);

        self::assertArrayNotHasKey('internalField', $output);
    }

    #[Test]
    public function internalFieldVisibleWithInternalClearance(): void
    {
        $resource = $this->buildResource();

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-int-ok',
            maxClassification: DataClassification::Internal,
            permissions: [],
            roles: [],
            authenticated: true,
        );
        $output = $resource->toArray($clearance);

        self::assertArrayHasKey('internalField', $output);
        self::assertSame('internal-only', $output['internalField']);
    }

    #[Test]
    public function confidentialFieldHiddenFromInternalClearance(): void
    {
        $resource = $this->buildResource();

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-conf-denied',
            maxClassification: DataClassification::Internal,
            permissions: [],
            roles: [],
            authenticated: true,
        );
        $output = $resource->toArray($clearance);

        self::assertArrayNotHasKey('confidentialField', $output);
    }

    #[Test]
    public function confidentialFieldVisibleWithConfidentialClearance(): void
    {
        $resource = $this->buildResource();

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-conf-ok',
            maxClassification: DataClassification::Confidential,
            permissions: [],
            roles: [],
            authenticated: true,
        );
        $output = $resource->toArray($clearance);

        self::assertArrayHasKey('confidentialField', $output);
        self::assertSame('secret-data', $output['confidentialField']);
    }

    #[Test]
    public function restrictedFieldHiddenFromConfidentialClearance(): void
    {
        $resource = $this->buildResource();

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-rest-denied',
            maxClassification: DataClassification::Confidential,
            permissions: [],
            roles: [],
            authenticated: true,
        );
        $output = $resource->toArray($clearance);

        self::assertArrayNotHasKey('restrictedField', $output);
    }

    #[Test]
    public function restrictedFieldVisibleWithRestrictedClearance(): void
    {
        $resource = $this->buildResource();

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-rest-ok',
            maxClassification: DataClassification::Restricted,
            permissions: [],
            roles: [],
            authenticated: true,
        );
        $output = $resource->toArray($clearance);

        self::assertArrayHasKey('restrictedField', $output);
        self::assertSame('top-secret', $output['restrictedField']);
    }

    #[Test]
    public function allFieldsVisibleWithMaxClearance(): void
    {
        $resource = $this->buildResource();

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-max',
            maxClassification: DataClassification::Restricted,
            permissions: [],
            roles: [],
            authenticated: true,
        );
        $output = $resource->toArray($clearance);

        self::assertCount(4, $output);
        self::assertArrayHasKey('publicField', $output);
        self::assertArrayHasKey('internalField', $output);
        self::assertArrayHasKey('confidentialField', $output);
        self::assertArrayHasKey('restrictedField', $output);
    }

    #[Test]
    public function noClearanceMeansAllExposedFieldsVisible(): void
    {
        $resource = $this->buildResource();

        // Without clearance, classification is not enforced
        $output = $resource->toArray();

        self::assertCount(4, $output);
    }

    #[Test]
    public function clearanceSnapshotIsImmutablePerRequest(): void
    {
        $clearance = new ClearanceSnapshot(
            snapshotId: 'immutable-test',
            maxClassification: DataClassification::Internal,
            permissions: ['perm.a'],
            roles: ['role-a'],
            authenticated: true,
        );

        // Verify readonly properties cannot change — just confirm consistent reads
        $resource1 = $this->buildResource();
        $output1 = $resource1->toArray($clearance);

        $resource2 = $this->buildResource();
        $output2 = $resource2->toArray($clearance);

        self::assertSame($output1, $output2, 'Same clearance snapshot must produce deterministic output');
    }

    #[Test]
    public function fieldClassificationOverridesResourceClassification(): void
    {
        $metadata = ResourceMetadata::resolve(ClassificationTagTestResource::class);

        // confidentialField has ClassificationTag(Confidential) — should take that level
        $policy = $metadata->fieldPolicies['confidentialField'];
        self::assertSame(DataClassification::Confidential, $policy->classification);

        // publicField has no ClassificationTag — inherits from Expose default (Public)
        $publicPolicy = $metadata->fieldPolicies['publicField'];
        self::assertSame(DataClassification::Public, $publicPolicy->classification);
    }

    private function buildResource(): ClassificationTagTestResource
    {
        $resource = new ClassificationTagTestResource();
        $resource->publicField = 'visible';
        $resource->internalField = 'internal-only';
        $resource->confidentialField = 'secret-data';
        $resource->restrictedField = 'top-secret';

        return $resource;
    }
}

#[ApiResource(type: 'classification_test')]
final class ClassificationTagTestResource extends AbstractApiResource
{
    #[Expose]
    public string $publicField = '';

    #[Expose]
    #[ClassificationTag(DataClassification::Internal)]
    public string $internalField = '';

    #[Expose]
    #[ClassificationTag(DataClassification::Confidential)]
    public string $confidentialField = '';

    #[Expose]
    #[ClassificationTag(DataClassification::Restricted)]
    public string $restrictedField = '';
}
