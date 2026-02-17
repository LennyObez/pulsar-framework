<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Dlp\DlpAction;
use Pulsar\Security\Dlp\DlpMatch;
use Pulsar\Security\Dlp\DlpScanResult;
use Pulsar\Security\Dlp\SensitiveDataType;

#[CoversClass(DlpScanResult::class)]
#[CoversClass(DlpMatch::class)]
final class DlpScanResultTest extends TestCase
{
    public function testCleanResult(): void
    {
        $result = DlpScanResult::clean('safe content');

        self::assertFalse($result->detected);
        self::assertSame(DlpAction::Alert, $result->actionTaken);
        self::assertSame([], $result->matches);
        self::assertSame('safe content', $result->redactedContent);
    }

    public function testDetectedResult(): void
    {
        $match = new DlpMatch(
            type: SensitiveDataType::CreditCard,
            pattern: '/\d{16}/',
            offset: 5,
            length: 16,
            maskedValue: '************1234',
        );

        $result = new DlpScanResult(
            detected: true,
            actionTaken: DlpAction::Redact,
            matches: [$match],
            redactedContent: 'Card: ************1234',
        );

        self::assertTrue($result->detected);
        self::assertSame(DlpAction::Redact, $result->actionTaken);
        self::assertCount(1, $result->matches);
        self::assertSame('Card: ************1234', $result->redactedContent);
    }

    public function testMultipleMatches(): void
    {
        $match1 = new DlpMatch(SensitiveDataType::CreditCard, '/\d{16}/', 0, 16, '****');
        $match2 = new DlpMatch(SensitiveDataType::Ssn, '/\d{3}-\d{2}-\d{4}/', 20, 11, '***-**-6789');

        $result = new DlpScanResult(
            detected: true,
            actionTaken: DlpAction::Block,
            matches: [$match1, $match2],
            redactedContent: 'redacted',
        );

        self::assertCount(2, $result->matches);
        self::assertSame(SensitiveDataType::CreditCard, $result->matches[0]->type);
        self::assertSame(SensitiveDataType::Ssn, $result->matches[1]->type);
    }

    public function testCleanResultWithEmptyContent(): void
    {
        $result = DlpScanResult::clean('');

        self::assertFalse($result->detected);
        self::assertSame('', $result->redactedContent);
    }
}
