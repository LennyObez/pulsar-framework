<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\CustomControl;

#[CoversClass(CustomControl::class)]
final class CustomControlTest extends TestCase
{
    public function testConstruction(): void
    {
        $verifier = static fn(): CheckResult => CheckResult::pass('test', 'ok');

        $control = new CustomControl(
            id: 'org.backup',
            name: 'Backup Check',
            description: 'Verify backups exist',
            verifier: $verifier,
            category: 'operations',
        );

        self::assertSame('org.backup', $control->id);
        self::assertSame('Backup Check', $control->name);
        self::assertSame('Verify backups exist', $control->description);
        self::assertSame('operations', $control->category);
        self::assertSame($verifier, $control->verifier);
    }

    public function testDefaultCategory(): void
    {
        $control = new CustomControl(
            id: 'test',
            name: 'Test',
            description: 'desc',
            verifier: static fn(): CheckResult => CheckResult::pass('test', 'ok'),
        );

        self::assertSame('custom', $control->category);
    }
}
