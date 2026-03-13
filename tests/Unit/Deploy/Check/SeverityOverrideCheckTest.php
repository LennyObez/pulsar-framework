<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\Check\SeverityOverrideCheck;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Deploy\DeploySeverity;

#[CoversClass(SeverityOverrideCheck::class)]
final class SeverityOverrideCheckTest extends TestCase
{
    #[Test]
    public function passingResultIsNeverModified(): void
    {
        $inner = $this->createInnerCheck(CheckResult::pass('test', 'All good'));
        $check = new SeverityOverrideCheck($inner, DeploySeverity::Fail);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function errorDowngradedToWarningWhenConfiguredAsWarn(): void
    {
        $inner = $this->createInnerCheck(CheckResult::error('test', 'Something failed'));
        $check = new SeverityOverrideCheck($inner, DeploySeverity::Warn);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertSame('Something failed', $result->message);
    }

    #[Test]
    public function warningUpgradedToErrorWhenConfiguredAsFail(): void
    {
        $inner = $this->createInnerCheck(CheckResult::warning('test', 'Might be an issue'));
        $check = new SeverityOverrideCheck($inner, DeploySeverity::Fail);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
    }

    #[Test]
    public function sameAsSeverityIsPassedThrough(): void
    {
        $inner = $this->createInnerCheck(CheckResult::error('test', 'Error'));
        $check = new SeverityOverrideCheck($inner, DeploySeverity::Fail);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
    }

    #[Test]
    public function delegatesNameAndDescription(): void
    {
        $inner = $this->createInnerCheck(CheckResult::pass('test', 'OK'));
        $check = new SeverityOverrideCheck($inner, DeploySeverity::Warn);

        self::assertSame('inner-check', $check->getName());
        self::assertSame('Inner check description', $check->getDescription());
    }

    /**
     * F26.4: every result that comes out of the override decorator
     * carries the `overridden` flag and the `originalSeverity` so
     * the operator's report shows "PASS (overridden, originally
     * ERROR)" instead of a bare "PASS".
     */
    #[Test]
    public function overriddenResultStampsOriginalSeverity(): void
    {
        $inner = $this->createInnerCheck(CheckResult::error('test', 'Boom'));
        $check = new SeverityOverrideCheck($inner, DeploySeverity::Warn);

        $result = $check->check('production');

        self::assertTrue($result->overridden);
        self::assertSame(CheckSeverity::Error, $result->originalSeverity);
        self::assertSame(CheckSeverity::Warning, $result->severity);
    }

    /**
     * F26.4: when the override would not change the severity (or
     * the result already passed), the override flag is NOT set.
     * The flag is reserved for genuine downgrades / upgrades so
     * the report never raises false alarms.
     */
    #[Test]
    public function unchangedResultLeavesOverriddenFalse(): void
    {
        $inner = $this->createInnerCheck(CheckResult::pass('test', 'OK'));
        $check = new SeverityOverrideCheck($inner, DeploySeverity::Warn);

        $result = $check->check('production');

        self::assertFalse($result->overridden);
        self::assertNull($result->originalSeverity);
    }

    private function createInnerCheck(CheckResult $result): DeployCheckInterface
    {
        return new class ($result) implements DeployCheckInterface {
            public function __construct(private readonly CheckResult $result) {}

            public function getName(): string
            {
                return 'inner-check';
            }

            public function getDescription(): string
            {
                return 'Inner check description';
            }

            public function check(string $environment): CheckResult
            {
                return $this->result;
            }
        };
    }
}
