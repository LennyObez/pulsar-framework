<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Resource\FieldAllowlist;
use Pulsar\Api\Resource\FieldPolicy;
use Pulsar\Security\Compliance\DataClassification;

#[CoversClass(FieldAllowlist::class)]
final class FieldAllowlistTest extends TestCase
{
    private function createAllowlist(int $maxFields = 50): FieldAllowlist
    {
        return new FieldAllowlist(
            fieldPolicies: [
                'id' => new FieldPolicy(name: 'id', propertyName: 'id'),
                'name' => new FieldPolicy(name: 'name', propertyName: 'name'),
                'email' => new FieldPolicy(
                    name: 'email',
                    propertyName: 'email',
                    classification: DataClassification::Internal,
                ),
            ],
            resourceType: 'users',
            maxFields: $maxFields,
        );
    }

    #[Test]
    public function validFieldsPassValidation(): void
    {
        $allowlist = $this->createAllowlist();

        $result = $allowlist->validate(['id', 'name']);

        self::assertSame(['id', 'name'], $result);
    }

    #[Test]
    public function unknownFieldThrows400(): void
    {
        $allowlist = $this->createAllowlist();

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Unknown field "secret"/');

        $_ = $allowlist->validate(['id', 'secret']);
    }

    #[Test]
    public function multipleUnknownFieldsReportedTogether(): void
    {
        $allowlist = $this->createAllowlist();

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/foo, bar/');

        $_ = $allowlist->validate(['foo', 'bar']);
    }

    #[Test]
    public function fieldCountExceedsMaxThrows400(): void
    {
        $allowlist = $this->createAllowlist(maxFields: 2);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/exceeds maximum of 2/');

        $_ = $allowlist->validate(['id', 'name', 'email']);
    }

    #[Test]
    public function exactlyMaxFieldsIsAllowed(): void
    {
        $allowlist = $this->createAllowlist(maxFields: 3);

        $result = $allowlist->validate(['id', 'name', 'email']);

        self::assertSame(['id', 'name', 'email'], $result);
    }

    #[Test]
    public function defaultFieldsReturnsAllExposedNames(): void
    {
        $allowlist = $this->createAllowlist();

        self::assertSame(['id', 'name', 'email'], $allowlist->defaultFields());
    }

    #[Test]
    public function hasReturnsTrueForExistingField(): void
    {
        $allowlist = $this->createAllowlist();

        self::assertTrue($allowlist->has('id'));
        self::assertTrue($allowlist->has('email'));
    }

    #[Test]
    public function hasReturnsFalseForUnknownField(): void
    {
        $allowlist = $this->createAllowlist();

        self::assertFalse($allowlist->has('password'));
    }

    #[Test]
    public function policyReturnsFieldPolicyForKnownField(): void
    {
        $allowlist = $this->createAllowlist();
        $policy = $allowlist->policy('email');

        self::assertNotNull($policy);
        self::assertSame('email', $policy->name);
        self::assertSame(DataClassification::Internal, $policy->classification);
    }

    #[Test]
    public function policyReturnsNullForUnknownField(): void
    {
        $allowlist = $this->createAllowlist();

        self::assertNull($allowlist->policy('unknown'));
    }

    #[Test]
    public function filterSilentlyRemovesUnknownFields(): void
    {
        $allowlist = $this->createAllowlist();

        $result = $allowlist->filter(['id', 'unknown', 'name', 'secret']);

        self::assertSame(['id', 'name'], $result);
    }

    #[Test]
    public function filterReturnsEmptyArrayWhenAllFieldsUnknown(): void
    {
        $allowlist = $this->createAllowlist();

        self::assertSame([], $allowlist->filter(['unknown', 'secret']));
    }

    #[Test]
    public function allPoliciesReturnsFullMap(): void
    {
        $allowlist = $this->createAllowlist();

        $policies = $allowlist->allPolicies();

        self::assertCount(3, $policies);
        self::assertArrayHasKey('id', $policies);
        self::assertArrayHasKey('name', $policies);
        self::assertArrayHasKey('email', $policies);
    }

    #[Test]
    public function emptyFieldListValidates(): void
    {
        $allowlist = $this->createAllowlist();

        self::assertSame([], $allowlist->validate([]));
    }
}
