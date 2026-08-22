<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Smart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Smart\SmartScope;

#[CoversClass(SmartScope::class)]
final class SmartScopeTest extends TestCase
{
    // --- parse() ---

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function validScopeProvider(): iterable
    {
        yield 'patient/Observation.read' => ['patient/Observation.read', 'patient', 'Observation', 'read'];
        yield 'user/Patient.write' => ['user/Patient.write', 'user', 'Patient', 'write'];
        yield 'system/*.read' => ['system/*.read', 'system', '*', 'read'];
        yield 'patient/*.*' => ['patient/*.*', 'patient', '*', '*'];
        yield 'user/MedicationRequest.write' => ['user/MedicationRequest.write', 'user', 'MedicationRequest', 'write'];
    }

    #[Test]
    #[DataProvider('validScopeProvider')]
    public function parseValidScopes(string $raw, string $context, string $resource, string $permission): void
    {
        $scope = SmartScope::parse($raw);

        self::assertNotNull($scope);
        self::assertSame($context, $scope->context);
        self::assertSame($resource, $scope->resource);
        self::assertSame($permission, $scope->permission);
        self::assertSame($raw, $scope->raw);
    }

    #[Test]
    public function parseWithoutDotDefaultsToWildcardPermission(): void
    {
        $scope = SmartScope::parse('patient/Observation');

        self::assertNotNull($scope);
        self::assertSame('patient', $scope->context);
        self::assertSame('Observation', $scope->resource);
        self::assertSame('*', $scope->permission);
    }

    #[Test]
    public function parseLaunchScope(): void
    {
        $scope = SmartScope::parse('launch');

        self::assertNotNull($scope);
        self::assertSame('launch', $scope->context);
        self::assertSame('', $scope->resource);
        self::assertSame('', $scope->permission);
        self::assertTrue($scope->isLaunchScope());
    }

    #[Test]
    public function parseLaunchPatientScope(): void
    {
        $scope = SmartScope::parse('launch/patient');

        self::assertNotNull($scope);
        self::assertSame('launch', $scope->context);
        self::assertSame('patient', $scope->resource);
        self::assertTrue($scope->isLaunchScope());
    }

    #[Test]
    public function parseReturnsNullForInvalidScope(): void
    {
        self::assertNull(SmartScope::parse('invalid-scope-format'));
    }

    #[Test]
    public function parseTrimsWhitespace(): void
    {
        $scope = SmartScope::parse('  patient/Observation.read  ');

        self::assertNotNull($scope);
        self::assertSame('patient', $scope->context);
        self::assertSame('Observation', $scope->resource);
    }

    // --- grants() ---

    #[Test]
    public function grantsExactResourceAndPermission(): void
    {
        $scope = SmartScope::parse('patient/Observation.read');

        self::assertTrue($scope->grants('Observation', 'read'));
        self::assertFalse($scope->grants('Observation', 'write'));
        self::assertFalse($scope->grants('Patient', 'read'));
    }

    #[Test]
    public function grantsWithWildcardResource(): void
    {
        $scope = SmartScope::parse('patient/*.read');

        self::assertTrue($scope->grants('Observation', 'read'));
        self::assertTrue($scope->grants('Patient', 'read'));
        self::assertFalse($scope->grants('Patient', 'write'));
    }

    #[Test]
    public function grantsWithWildcardPermission(): void
    {
        $scope = SmartScope::parse('patient/Observation.*');

        self::assertTrue($scope->grants('Observation', 'read'));
        self::assertTrue($scope->grants('Observation', 'write'));
        self::assertFalse($scope->grants('Patient', 'read'));
    }

    #[Test]
    public function grantsWithBothWildcards(): void
    {
        $scope = SmartScope::parse('system/*.*');

        self::assertTrue($scope->grants('Observation', 'read'));
        self::assertTrue($scope->grants('Patient', 'write'));
        self::assertTrue($scope->grants('Any', 'any'));
    }

    // --- isLaunchScope() ---

    #[Test]
    public function isLaunchScopeReturnsFalseForNonLaunch(): void
    {
        $scope = SmartScope::parse('patient/Observation.read');

        self::assertFalse($scope->isLaunchScope());
    }

    // --- __toString() ---

    #[Test]
    public function toStringReturnsRawScope(): void
    {
        $scope = SmartScope::parse('user/Patient.write');

        self::assertSame('user/Patient.write', (string) $scope);
    }
}
