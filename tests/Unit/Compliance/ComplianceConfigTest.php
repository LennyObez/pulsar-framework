<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceConfig;
use Pulsar\Compliance\ComplianceFramework;

use function dirname;

#[CoversClass(ComplianceConfig::class)]
final class ComplianceConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreSafeAndEmpty(): void
    {
        $config = new ComplianceConfig();

        self::assertSame([], $config->enabledFrameworks);
        self::assertTrue($config->verificationEnabled);
        self::assertTrue($config->bootCheck);
        self::assertSame(3600, $config->evidenceInterval);
        self::assertFalse($config->strictMode);
        self::assertSame([], $config->unknownConfigKeys());
    }

    #[Test]
    public function fromArrayReadsEnumInstances(): void
    {
        $config = ComplianceConfig::fromArray([
            'enabled_frameworks' => [
                ComplianceFramework::PciDss,
                ComplianceFramework::Gdpr,
            ],
        ]);

        self::assertSame(
            [ComplianceFramework::PciDss, ComplianceFramework::Gdpr],
            $config->enabledFrameworks,
        );
    }

    #[Test]
    public function fromArrayReadsStringFrameworkValues(): void
    {
        $config = ComplianceConfig::fromArray([
            'enabled_frameworks' => ['pci_dss', 'gdpr'],
        ]);

        self::assertSame(
            [ComplianceFramework::PciDss, ComplianceFramework::Gdpr],
            $config->enabledFrameworks,
        );
    }

    #[Test]
    public function fromArrayDropsUnknownAndDuplicateFrameworks(): void
    {
        $config = ComplianceConfig::fromArray([
            'enabled_frameworks' => [
                'pci_dss',
                'not_a_framework',
                ComplianceFramework::PciDss,
                42,
                'gdpr',
            ],
        ]);

        self::assertSame(
            [ComplianceFramework::PciDss, ComplianceFramework::Gdpr],
            $config->enabledFrameworks,
        );
    }

    #[Test]
    public function fromArrayReadsVerificationSection(): void
    {
        $config = ComplianceConfig::fromArray([
            'verification' => [
                'enabled' => false,
                'boot_check' => false,
                'evidence_interval' => 7200,
                'strict_mode' => true,
            ],
        ]);

        self::assertFalse($config->verificationEnabled);
        self::assertFalse($config->bootCheck);
        self::assertSame(7200, $config->evidenceInterval);
        self::assertTrue($config->strictMode);
    }

    #[Test]
    public function fromArrayFallsBackToDefaultsForWrongVerificationTypes(): void
    {
        $config = ComplianceConfig::fromArray([
            'verification' => [
                'enabled' => 'yes',            // not a bool
                'boot_check' => 'nope',        // not a bool
                'evidence_interval' => '7200', // not an int
                'strict_mode' => 1,            // not a bool
            ],
        ]);

        self::assertTrue($config->verificationEnabled);
        self::assertTrue($config->bootCheck, 'a malformed boot_check must fall back to enabled, not disabled');
        self::assertSame(3600, $config->evidenceInterval);
        self::assertFalse($config->strictMode);
    }

    #[Test]
    public function fromArrayDefaultsBootCheckToEnabledWhenAbsent(): void
    {
        // Boot-time control verification is on unless the operator turns it off:
        // an omitted key must never silently disable the check.
        $config = ComplianceConfig::fromArray(['verification' => ['strict_mode' => false]]);

        self::assertTrue($config->bootCheck);
    }

    #[Test]
    public function fromArrayReportsUnknownTopLevelKeys(): void
    {
        $config = ComplianceConfig::fromArray([
            'enabled_frameworks' => [],
            'enabled_framworks' => [], // typo
        ]);

        self::assertContains('enabled_framworks', $config->unknownConfigKeys());
    }

    #[Test]
    public function fromArrayReportsUnknownVerificationKeysWithPath(): void
    {
        $config = ComplianceConfig::fromArray([
            'verification' => [
                'strict_mode' => true,
                'strct_mode' => true, // typo
            ],
        ]);

        self::assertContains('verification.strct_mode', $config->unknownConfigKeys());
    }

    #[Test]
    public function theShippedConfigProducesNoUnknownKeys(): void
    {
        /** @var array<string, mixed> $shipped */
        $shipped = require dirname(__DIR__, 3) . '/config/compliance.php';

        $config = ComplianceConfig::fromArray($shipped);

        self::assertSame([], $config->unknownConfigKeys());
        self::assertNotEmpty($config->enabledFrameworks);
    }
}
