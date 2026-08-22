<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Smart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Smart\SmartScope;
use Pulsar\Extension\Fhir\Smart\SmartScopeEnforcer;

#[CoversClass(SmartScopeEnforcer::class)]
#[CoversClass(SmartScope::class)]
final class SmartScopeEnforcerTest extends TestCase
{
    private SmartScopeEnforcer $enforcer;

    protected function setUp(): void
    {
        $this->enforcer = new SmartScopeEnforcer();
    }

    #[Test]
    public function isAllowedReturnsFalseWithNoScopes(): void
    {
        self::assertFalse($this->enforcer->isAllowed([], 'Observation', 'read'));
    }

    #[Test]
    public function isAllowedReturnsTrueWhenScopeMatches(): void
    {
        $scopes = [SmartScope::parse('patient/Observation.read')];

        self::assertTrue($this->enforcer->isAllowed($scopes, 'Observation', 'read'));
    }

    #[Test]
    public function isAllowedReturnsFalseWhenNoScopeMatches(): void
    {
        $scopes = [SmartScope::parse('patient/Observation.read')];

        self::assertFalse($this->enforcer->isAllowed($scopes, 'Patient', 'read'));
        self::assertFalse($this->enforcer->isAllowed($scopes, 'Observation', 'write'));
    }

    #[Test]
    public function isAllowedChecksMultipleScopes(): void
    {
        $scopes = [
            SmartScope::parse('patient/Observation.read'),
            SmartScope::parse('patient/Patient.read'),
            SmartScope::parse('user/MedicationRequest.write'),
        ];

        self::assertTrue($this->enforcer->isAllowed($scopes, 'Patient', 'read'));
        self::assertTrue($this->enforcer->isAllowed($scopes, 'MedicationRequest', 'write'));
        self::assertFalse($this->enforcer->isAllowed($scopes, 'Condition', 'read'));
    }

    #[Test]
    public function checkAccessParsesSpaceDelimitedScopes(): void
    {
        $scopeString = 'patient/Observation.read patient/Patient.write';

        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Observation', 'read'));
        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Patient', 'write'));
        self::assertFalse($this->enforcer->checkAccess($scopeString, 'Patient', 'read'));
    }

    #[Test]
    public function checkAccessSkipsInvalidScopesInString(): void
    {
        $scopeString = 'patient/Observation.read invalid!!scope user/Patient.write';

        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Observation', 'read'));
        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Patient', 'write'));
    }

    #[Test]
    public function checkAccessWithEmptyStringDeniesAll(): void
    {
        self::assertFalse($this->enforcer->checkAccess('', 'Observation', 'read'));
    }

    #[Test]
    public function checkAccessWithWildcardScope(): void
    {
        $scopeString = 'system/*.read';

        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Observation', 'read'));
        self::assertTrue($this->enforcer->checkAccess($scopeString, 'Patient', 'read'));
        self::assertFalse($this->enforcer->checkAccess($scopeString, 'Patient', 'write'));
    }
}
