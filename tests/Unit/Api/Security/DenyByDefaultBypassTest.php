<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\Attribute\ApiResource;
use Pulsar\Api\Resource\Attribute\Expose;
use Pulsar\Api\Resource\FieldAllowlist;
use Pulsar\Api\Resource\FieldPolicy;
use Pulsar\Api\Resource\ResourceMetadata;

/**
 * CRITICAL: Validates Finding E — deny-by-default field exposure.
 *
 * These tests prove that:
 * 1. A resource without #[Expose] on a property does NOT serialize that property
 * 2. ?fields= with an undeclared field returns 400
 * 3. The serialization output is deterministic (same input -> same output)
 */
#[CoversClass(AbstractApiResource::class)]
#[CoversClass(ResourceMetadata::class)]
#[CoversClass(FieldAllowlist::class)]
final class DenyByDefaultBypassTest extends TestCase
{
    #[Test]
    public function unexposedPropertyIsNeverSerialized(): void
    {
        $resource = new DenyByDefaultTestResource();
        $resource->id = '42';
        $resource->name = 'Alice';
        $resource->password = 'super_secret';
        $resource->internalNote = 'do not expose';

        $output = $resource->toArray();

        self::assertArrayHasKey('id', $output);
        self::assertArrayHasKey('name', $output);
        self::assertArrayNotHasKey('password', $output, 'password has no #[Expose] — MUST be absent');
        self::assertArrayNotHasKey('internalNote', $output, 'internalNote has no #[Expose] — MUST be absent');
        self::assertSame('42', $output['id']);
        self::assertSame('Alice', $output['name']);
    }

    #[Test]
    public function sparseFieldsetWithUndeclaredFieldReturns400(): void
    {
        $allowlist = new FieldAllowlist(
            fieldPolicies: [
                'id' => new FieldPolicy(name: 'id', propertyName: 'id'),
                'name' => new FieldPolicy(name: 'name', propertyName: 'name'),
            ],
            resourceType: 'deny_test',
            maxFields: 50,
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Unknown field "password"/');

        // Simulates ?fields=id,password — password was never exposed
        $_ = $allowlist->validate(['id', 'password']);
    }

    #[Test]
    public function sparseFieldsetCannotRequestUnexposedField(): void
    {
        $resource = new DenyByDefaultTestResource();
        $resource->id = '42';
        $resource->name = 'Alice';
        $resource->password = 'super_secret';

        // Even passing 'password' as a requested field should not include it
        // because the metadata only knows about exposed fields
        $output = $resource->toArray(requestedFields: ['id', 'password']);

        self::assertArrayHasKey('id', $output);
        self::assertArrayNotHasKey('password', $output, 'password bypasses the policy map — silently excluded');
    }

    #[Test]
    public function serializationIsDeterministic(): void
    {
        $resource = new DenyByDefaultTestResource();
        $resource->id = '42';
        $resource->name = 'Alice';
        $resource->password = 'super_secret';
        $resource->internalNote = 'notes';

        $output1 = $resource->toArray();
        $output2 = $resource->toArray();
        $output3 = $resource->toArray();

        self::assertSame($output1, $output2, 'First and second serialization must be identical');
        self::assertSame($output2, $output3, 'Second and third serialization must be identical');
    }

    #[Test]
    public function onlyExposedFieldsAppearInMetadata(): void
    {
        $metadata = ResourceMetadata::resolve(DenyByDefaultTestResource::class);

        $exposed = $metadata->exposedFieldNames();

        self::assertContains('id', $exposed);
        self::assertContains('name', $exposed);
        self::assertNotContains('password', $exposed, 'password has no #[Expose]');
        self::assertNotContains('internalNote', $exposed, 'internalNote has no #[Expose]');
    }

    #[Test]
    public function fieldPoliciesOnlyContainExposedFields(): void
    {
        $metadata = ResourceMetadata::resolve(DenyByDefaultTestResource::class);

        self::assertArrayHasKey('id', $metadata->fieldPolicies);
        self::assertArrayHasKey('name', $metadata->fieldPolicies);
        self::assertArrayNotHasKey('password', $metadata->fieldPolicies);
        self::assertArrayNotHasKey('internalNote', $metadata->fieldPolicies);
    }

    #[Test]
    public function allowlistBuiltFromResourceOnlyContainsExposedFields(): void
    {
        $resource = new DenyByDefaultTestResource();
        $allowlist = $resource->allowlist(50);

        self::assertTrue($allowlist->has('id'));
        self::assertTrue($allowlist->has('name'));
        self::assertFalse($allowlist->has('password'));
        self::assertFalse($allowlist->has('internalNote'));
    }

    #[Test]
    public function allowlistValidateRejectsUnexposedField(): void
    {
        $resource = new DenyByDefaultTestResource();
        $allowlist = $resource->allowlist(50);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);

        $_ = $allowlist->validate(['id', 'password']);
    }

    #[Test]
    public function emptyFieldsetSerializesToEmptyOutput(): void
    {
        $resource = new DenyByDefaultTestResource();
        $resource->id = '42';
        $resource->name = 'Alice';
        $resource->password = 'secret';

        $output = $resource->toArray(requestedFields: []);

        self::assertSame([], $output);
    }
}

#[ApiResource(type: 'deny_test')]
final class DenyByDefaultTestResource extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    #[Expose]
    public string $name = '';

    // NO #[Expose] — MUST never appear in output
    public string $password = '';

    // NO #[Expose] — MUST never appear in output
    public string $internalNote = '';
}
