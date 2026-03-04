<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\CustomControl;
use Pulsar\Compliance\Verification\CustomControlRegistry;

#[CoversClass(CustomControlRegistry::class)]
final class CustomControlRegistryTest extends TestCase
{
    public function testRegisterAndGet(): void
    {
        $registry = new CustomControlRegistry();
        $control = new CustomControl(
            id: 'org.backup',
            name: 'Backup Check',
            description: 'Verify backups exist',
            verifier: static fn(): CheckResult => CheckResult::pass('org.backup', 'Backups ok'),
        );

        $registry->register($control);

        self::assertSame($control, $registry->get('org.backup'));
        self::assertTrue($registry->has('org.backup'));
        self::assertFalse($registry->has('nonexistent'));
        self::assertNull($registry->get('nonexistent'));
    }

    public function testAllReturnsList(): void
    {
        $registry = new CustomControlRegistry();
        $registry->register(new CustomControl('a', 'A', 'desc', static fn(): CheckResult => CheckResult::pass('a', 'ok')));
        $registry->register(new CustomControl('b', 'B', 'desc', static fn(): CheckResult => CheckResult::pass('b', 'ok')));

        self::assertCount(2, $registry->all());
        self::assertSame(2, $registry->count());
    }

    public function testByCategory(): void
    {
        $registry = new CustomControlRegistry();
        $registry->register(new CustomControl('a', 'A', 'desc', static fn(): CheckResult => CheckResult::pass('a', 'ok'), category: 'security'));
        $registry->register(new CustomControl('b', 'B', 'desc', static fn(): CheckResult => CheckResult::pass('b', 'ok'), category: 'ops'));
        $registry->register(new CustomControl('c', 'C', 'desc', static fn(): CheckResult => CheckResult::pass('c', 'ok'), category: 'security'));

        self::assertCount(2, $registry->byCategory('security'));
        self::assertCount(1, $registry->byCategory('ops'));
        self::assertCount(0, $registry->byCategory('unknown'));
    }

    public function testVerifyAll(): void
    {
        $registry = new CustomControlRegistry();
        $registry->register(new CustomControl('a', 'A', 'desc', static fn(): CheckResult => CheckResult::pass('a', 'ok')));
        $registry->register(new CustomControl('b', 'B', 'desc', static fn(): CheckResult => CheckResult::fail('b', 'bad')));

        $results = $registry->verifyAll();

        self::assertCount(2, $results);
        self::assertSame(CheckStatus::Pass, $results[0]->status);
        self::assertSame(CheckStatus::Fail, $results[1]->status);
    }

    public function testVerifySingle(): void
    {
        $registry = new CustomControlRegistry();
        $registry->register(new CustomControl('a', 'A', 'desc', static fn(): CheckResult => CheckResult::pass('a', 'ok')));

        $result = $registry->verify('a');
        self::assertNotNull($result);
        self::assertSame(CheckStatus::Pass, $result->status);

        self::assertNull($registry->verify('nonexistent'));
    }

    public function testEmptyRegistry(): void
    {
        $registry = new CustomControlRegistry();

        self::assertSame([], $registry->all());
        self::assertSame(0, $registry->count());
        self::assertSame([], $registry->verifyAll());
    }
}
