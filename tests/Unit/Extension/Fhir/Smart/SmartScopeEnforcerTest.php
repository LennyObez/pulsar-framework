<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Smart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Smart\SmartScope;
use Pulsar\Extension\Fhir\Smart\SmartScopeEnforcer;

#[CoversClass(SmartScopeEnforcer::class)]
final class SmartScopeEnforcerTest extends TestCase
{
    private SmartScopeEnforcer $enforcer;

    protected function setUp(): void
    {
        $this->enforcer = new SmartScopeEnforcer();
    }

    public function testIsAllowedWithMatchingScope(): void
    {
        $scopes = [
            SmartScope::parse('patient/Patient.read'),
            SmartScope::parse('patient/Observation.read'),
        ];

        self::assertTrue($this->enforcer->isAllowed(array_values(array_filter($scopes)), 'Patient', 'read'));
        self::assertTrue($this->enforcer->isAllowed(array_values(array_filter($scopes)), 'Observation', 'read'));
        self::assertFalse($this->enforcer->isAllowed(array_values(array_filter($scopes)), 'Patient', 'write'));
        self::assertFalse($this->enforcer->isAllowed(array_values(array_filter($scopes)), 'Condition', 'read'));
    }

    public function testIsAllowedWithWildcard(): void
    {
        $scopes = [SmartScope::parse('patient/*.read')];

        self::assertTrue($this->enforcer->isAllowed(array_values(array_filter($scopes)), 'Patient', 'read'));
        self::assertTrue($this->enforcer->isAllowed(array_values(array_filter($scopes)), 'Observation', 'read'));
        self::assertFalse($this->enforcer->isAllowed(array_values(array_filter($scopes)), 'Patient', 'write'));
    }

    public function testIsAllowedEmptyScopes(): void
    {
        self::assertFalse($this->enforcer->isAllowed([], 'Patient', 'read'));
    }

    public function testCheckAccessFromScopeString(): void
    {
        $scopeString = 'patient/Patient.read patient/Observation.read patient/Condition.write';

        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Patient', 'read'));
        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Observation', 'read'));
        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Condition', 'write'));
        self::assertFalse($this->enforcer->checkAccess($scopeString, 'Patient', 'write'));
        self::assertFalse($this->enforcer->checkAccess($scopeString, 'Encounter', 'read'));
    }

    public function testCheckAccessWithInvalidScopesInString(): void
    {
        $scopeString = 'patient/Patient.read invalid_scope another_bad';

        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Patient', 'read'));
        self::assertFalse($this->enforcer->checkAccess($scopeString, 'Observation', 'read'));
    }

    public function testCheckAccessWithWildcardInString(): void
    {
        $scopeString = 'patient/*.* launch';

        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Patient', 'read'));
        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Observation', 'write'));
    }
}
