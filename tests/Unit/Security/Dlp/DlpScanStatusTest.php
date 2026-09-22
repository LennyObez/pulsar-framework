<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Dlp\DlpAction;
use Pulsar\Security\Dlp\DlpConfig;
use Pulsar\Security\Dlp\DlpScanResult;
use Pulsar\Security\Dlp\DlpScanStatus;
use Pulsar\Security\Dlp\HtmlAwareDlpScanner;
use Pulsar\Security\Dlp\SensitiveDataType;
use Pulsar\Security\Dlp\SensitivePattern;
use Pulsar\Security\Dlp\SensitivePatternRegistry;

use function str_repeat;

/**
 * Whether the scanner actually looked, as a fact the scanner produces.
 *
 * `detected === false` had three causes and one spelling. Two of those causes
 * are a scanner that never read the content, and any consumer gating egress on
 * the answer was reading "I did not look" as "there is nothing there". These
 * checks pin each cause to its own status.
 */
#[CoversClass(DlpScanStatus::class)]
#[CoversClass(DlpScanResult::class)]
#[CoversClass(SensitivePatternRegistry::class)]
#[CoversClass(HtmlAwareDlpScanner::class)]
final class DlpScanStatusTest extends TestCase
{
    #[Test]
    public function aScanThatRanAndFoundNothingIsConclusive(): void
    {
        $result = new SensitivePatternRegistry(new DlpConfig(enabled: true))
            ->scan('nothing sensitive here');

        self::assertSame(DlpScanStatus::Completed, $result->status);
        self::assertTrue($result->status->isConclusive());
        self::assertFalse($result->detected);
    }

    #[Test]
    public function aDisabledRegistryReportsThatItDidNotLook(): void
    {
        // The value under test is the status, not the detection: a disabled
        // registry reports `detected === false` for a credit card number too.
        $result = new SensitivePatternRegistry(new DlpConfig(enabled: false))
            ->scan('card 4111111111111111');

        self::assertSame(DlpScanStatus::Disabled, $result->status);
        self::assertFalse($result->status->isConclusive());
        self::assertFalse($result->detected);
    }

    #[Test]
    public function anEngineThatGaveUpReportsFailureRatherThanCleanliness(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig(enabled: true));
        $registry->register(new SensitivePattern(SensitiveDataType::Custom, '/^(?:[a-z]+)+$/'));

        // preg_match_all() answers false here, and `false > 0` used to put that
        // on the same side of the comparison as "matched nothing".
        $result = $registry->scan(str_repeat('a', 40) . '!');

        self::assertSame(DlpScanStatus::Failed, $result->status);
        self::assertFalse($result->status->isConclusive());
    }

    #[Test]
    public function theOtherPatternsStillRunWhenOneEngineFails(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig(enabled: true));
        $registry->register(new SensitivePattern(SensitiveDataType::Custom, '/^(?:[a-z]+)+$/'));

        // What IS known is still reported; the status says it is a lower bound.
        $result = $registry->scan(str_repeat('a', 40) . '! ssn 123-45-6789');

        self::assertSame(DlpScanStatus::Failed, $result->status);
        self::assertTrue($result->detected);
        self::assertSame(SensitiveDataType::Ssn, $result->matches[0]->type);
    }

    #[Test]
    public function emptyContentIsAConclusiveNothing(): void
    {
        $result = new SensitivePatternRegistry(new DlpConfig(enabled: true))->scan('');

        self::assertSame(DlpScanStatus::Completed, $result->status);
    }

    #[Test]
    public function theHtmlScannerDoesNotLaunderTwoDidNotLooksIntoOneCleanRead(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig(enabled: false));

        // Both halves examined nothing. Merging them on "neither detected
        // anything" would have reported Completed.
        $result = new HtmlAwareDlpScanner($registry)
            ->scan('<p>ssn 123-45-6789</p>');

        self::assertSame(DlpScanStatus::Disabled, $result->status);
    }

    #[Test]
    public function theStatusFactoriesSayWhichKindOfEmptyAnswerTheyAre(): void
    {
        self::assertSame(DlpScanStatus::Completed, DlpScanResult::clean('x')->status);
        self::assertSame(DlpScanStatus::Disabled, DlpScanResult::disabled('x')->status);
        self::assertSame(
            DlpScanStatus::Failed,
            DlpScanResult::failed('x', [], DlpAction::Block)->status,
        );
    }
}
