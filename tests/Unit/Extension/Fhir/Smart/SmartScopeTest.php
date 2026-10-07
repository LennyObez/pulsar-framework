<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Smart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Smart\SmartScope;

#[CoversClass(SmartScope::class)]
final class SmartScopeTest extends TestCase
{
    #[DataProvider('validScopeProvider')]
    public function testParseValidScopes(string $raw, string $context, string $resource, string $permission): void
    {
        $scope = SmartScope::parse($raw);

        self::assertNotNull($scope, "Failed to parse: $raw");
        self::assertSame($context, $scope->context);
        self::assertSame($resource, $scope->resource);
        self::assertSame($permission, $scope->permission);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function validScopeProvider(): iterable
    {
        yield 'patient read' => ['patient/Patient.read', 'patient', 'Patient', 'read'];
        yield 'patient write' => ['patient/Patient.write', 'patient', 'Patient', 'write'];
        yield 'patient wildcard' => ['patient/*.read', 'patient', '*', 'read'];
        yield 'patient all permissions' => ['patient/Patient.*', 'patient', 'Patient', '*'];
        yield 'user read' => ['user/Observation.read', 'user', 'Observation', 'read'];
        yield 'system write' => ['system/Patient.write', 'system', 'Patient', 'write'];
        yield 'full wildcard' => ['patient/*.*', 'patient', '*', '*'];
        yield 'launch' => ['launch', 'launch', '', ''];
        yield 'launch patient' => ['launch/patient', 'launch', 'patient', ''];
        yield 'launch encounter' => ['launch/encounter', 'launch', 'encounter', ''];
    }

    public function testParseInvalidScope(): void
    {
        self::assertNull(SmartScope::parse('invalid'));
        self::assertNull(SmartScope::parse(''));
    }

    public function testGrantsExactMatch(): void
    {
        $scope = SmartScope::parse('patient/Patient.read');

        self::assertNotNull($scope);
        self::assertTrue($scope->grants('Patient', 'read'));
        self::assertFalse($scope->grants('Patient', 'write'));
        self::assertFalse($scope->grants('Observation', 'read'));
    }

    public function testGrantsWildcardResource(): void
    {
        $scope = SmartScope::parse('patient/*.read');

        self::assertNotNull($scope);
        self::assertTrue($scope->grants('Patient', 'read'));
        self::assertTrue($scope->grants('Observation', 'read'));
        self::assertFalse($scope->grants('Patient', 'write'));
    }

    public function testGrantsWildcardPermission(): void
    {
        $scope = SmartScope::parse('patient/Patient.*');

        self::assertNotNull($scope);
        self::assertTrue($scope->grants('Patient', 'read'));
        self::assertTrue($scope->grants('Patient', 'write'));
        self::assertFalse($scope->grants('Observation', 'read'));
    }

    public function testGrantsFullWildcard(): void
    {
        $scope = SmartScope::parse('patient/*.*');

        self::assertNotNull($scope);
        self::assertTrue($scope->grants('Patient', 'read'));
        self::assertTrue($scope->grants('Observation', 'write'));
        self::assertTrue($scope->grants('Condition', 'read'));
    }

    public function testIsLaunchScope(): void
    {
        $launch = SmartScope::parse('launch');
        self::assertNotNull($launch);
        self::assertTrue($launch->isLaunchScope());

        $patient = SmartScope::parse('patient/Patient.read');
        self::assertNotNull($patient);
        self::assertFalse($patient->isLaunchScope());
    }

    public function testToString(): void
    {
        $scope = SmartScope::parse('patient/Patient.read');

        self::assertNotNull($scope);
        self::assertSame('patient/Patient.read', (string) $scope);
    }
}
