<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamCheckResult;

#[CoversClass(AntiSpamCheckResult::class)]
final class AntiSpamCheckResultTest extends TestCase
{
    #[Test]
    public function passFactoryCreatesPassingResult(): void
    {
        $result = AntiSpamCheckResult::pass('honeypot');

        self::assertTrue($result->passed);
        self::assertSame('honeypot', $result->checkName);
        self::assertSame(0, $result->score);
        self::assertNull($result->reason);
    }

    #[Test]
    public function failFactoryCreatesFailingResult(): void
    {
        $result = AntiSpamCheckResult::fail('link_density', 30, 'Too many links');

        self::assertFalse($result->passed);
        self::assertSame('link_density', $result->checkName);
        self::assertSame(30, $result->score);
        self::assertSame('Too many links', $result->reason);
    }

    #[Test]
    public function skipFactoryCreatesPassingResult(): void
    {
        $result = AntiSpamCheckResult::skip('captcha');

        self::assertTrue($result->passed);
        self::assertSame('captcha', $result->checkName);
        self::assertSame(0, $result->score);
        self::assertNull($result->reason);
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $result = new AntiSpamCheckResult(
            passed: false,
            checkName: 'custom',
            score: 99,
            reason: 'Custom reason',
        );

        self::assertFalse($result->passed);
        self::assertSame('custom', $result->checkName);
        self::assertSame(99, $result->score);
        self::assertSame('Custom reason', $result->reason);
    }
}
