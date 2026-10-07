<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\EmailDomainCheckConfig;
use Pulsar\Security\AntiSpam\EmailDomainSignalMode;

#[CoversClass(EmailDomainCheckConfig::class)]
final class EmailDomainCheckConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreEnabledAndHardOnBothSignals(): void
    {
        $config = new EmailDomainCheckConfig();

        self::assertTrue($config->enabled);
        self::assertSame(EmailDomainSignalMode::Hard, $config->disposableBlock);
        self::assertSame(EmailDomainSignalMode::Hard, $config->mxBlock);
        self::assertTrue($config->mxFailOpen);
        self::assertSame(86400, $config->mxCacheTtlSeconds);
        self::assertTrue($config->hasActiveSignal());
    }

    #[Test]
    public function fromArrayParsesModesAndFallsBackForUnknownValues(): void
    {
        $config = EmailDomainCheckConfig::fromArray([
            'email_domain_check_enabled' => true,
            'disposable_block' => 'score',
            'mx_check_enabled' => true,
            'mx_block' => 'not-a-mode',
            'mx_fail_open' => false,
            'mx_cache_ttl' => 120,
        ]);

        self::assertSame(EmailDomainSignalMode::Score, $config->disposableBlock);
        self::assertSame(EmailDomainSignalMode::Hard, $config->mxBlock, 'unknown value falls back to the default (hard)');
        self::assertFalse($config->mxFailOpen);
        self::assertSame(120, $config->mxCacheTtlSeconds);
    }

    #[Test]
    public function disposableListAsAStringIsTreatedAsAPath(): void
    {
        $config = EmailDomainCheckConfig::fromArray(['disposable_list' => '/etc/pulsar/disposable.txt']);

        self::assertSame('/etc/pulsar/disposable.txt', $config->disposableListPath);
        self::assertSame([], $config->disposableListInline);
    }

    #[Test]
    public function disposableListAsAnArrayIsTreatedAsInlineDomains(): void
    {
        $config = EmailDomainCheckConfig::fromArray([
            'disposable_list' => ['bad.example', 42, 'worse.example', null],
        ]);

        self::assertNull($config->disposableListPath);
        self::assertSame(['bad.example', 'worse.example'], $config->disposableListInline);
    }

    #[Test]
    public function hasActiveSignalIsFalseWhenEverythingIsDisabled(): void
    {
        $allOff = new EmailDomainCheckConfig(
            disposableBlock: EmailDomainSignalMode::Off,
            mxCheckEnabled: false,
        );
        self::assertFalse($allOff->hasActiveSignal());

        $masterOff = new EmailDomainCheckConfig(enabled: false);
        self::assertFalse($masterOff->hasActiveSignal());
    }
}
