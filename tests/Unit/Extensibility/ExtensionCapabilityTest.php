<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\ExtensionCapability;

#[CoversNothing]
final class ExtensionCapabilityTest extends TestCase
{
    #[Test]
    public function enumHasEighteenCases(): void
    {
        $cases = ExtensionCapability::cases();

        self::assertCount(18, $cases);
    }

    #[Test]
    public function allExpectedCasesExist(): void
    {
        $expected = [
            'ContainerRead',
            'ContainerWrite',
            'ServiceRegister',
            'ServiceDecorate',
            'RouteRegister',
            'RouteRegisterGlobal',
            'MiddlewareRegister',
            'CryptoKeyAccess',
            'CryptoOperations',
            'AuditWrite',
            'AuditSinkAccess',
            'DatabaseRaw',
            'FilesystemWrite',
            'CommandRegister',
            'NetworkEgress',
            'EnvRead',
            'ConfigWrite',
            'ProcessExec',
        ];

        $actual = array_map(static fn(ExtensionCapability $c): string => $c->name, ExtensionCapability::cases());

        self::assertSame($expected, $actual);
    }
}
