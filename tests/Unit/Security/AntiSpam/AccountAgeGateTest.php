<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AccountAgeGate;
use Pulsar\Security\AntiSpam\AntiSpamContext;

#[CoversClass(AccountAgeGate::class)]
final class AccountAgeGateTest extends TestCase
{
    #[Test]
    public function nameReturnsAccountAge(): void
    {
        $gate = new AccountAgeGate();
        self::assertSame('account_age', $gate->name());
    }

    #[Test]
    public function passesForAnonymousUsers(): void
    {
        $gate = new AccountAgeGate();
        $context = new AntiSpamContext(body: 'test', ipHash: 'ip');

        $result = $gate->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function passesForOldEnoughAccount(): void
    {
        $gate = new AccountAgeGate(minAgeSeconds: 300);
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            userId: 'user-1',
            accountAgeSeconds: 600,
        );

        $result = $gate->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function passesAtExactMinAge(): void
    {
        $gate = new AccountAgeGate(minAgeSeconds: 300);
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            userId: 'user-1',
            accountAgeSeconds: 300,
        );

        $result = $gate->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsForTooNewAccount(): void
    {
        $gate = new AccountAgeGate(minAgeSeconds: 300);
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            userId: 'user-1',
            accountAgeSeconds: 60,
        );

        $result = $gate->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('too new', $result->reason ?? '');
        self::assertStringContainsString('240', $result->reason ?? '');
    }

    #[Test]
    public function failsWhenAccountAgeIsNull(): void
    {
        $gate = new AccountAgeGate();
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            userId: 'user-1',
            accountAgeSeconds: null,
        );

        $result = $gate->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('unavailable', $result->reason ?? '');
    }

    #[Test]
    public function customMinAgeIsRespected(): void
    {
        $gate = new AccountAgeGate(minAgeSeconds: 60);
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            userId: 'user-1',
            accountAgeSeconds: 61,
        );

        $result = $gate->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsForZeroAgeAccount(): void
    {
        $gate = new AccountAgeGate(minAgeSeconds: 300);
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            userId: 'user-1',
            accountAgeSeconds: 0,
        );

        $result = $gate->check($context);

        self::assertFalse($result->passed);
    }
}
