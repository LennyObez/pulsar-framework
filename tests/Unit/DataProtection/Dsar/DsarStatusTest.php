<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Dsar;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\Dsar\DsarStatus;
use ValueError;

#[CoversNothing]
final class DsarStatusTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('pending', DsarStatus::Pending->value);
        self::assertSame('processing', DsarStatus::Processing->value);
        self::assertSame('completed', DsarStatus::Completed->value);
        self::assertSame('rejected', DsarStatus::Rejected->value);
        self::assertSame('downloaded', DsarStatus::Downloaded->value);
    }

    #[Test]
    public function enumHasExactlyFiveCases(): void
    {
        self::assertCount(5, DsarStatus::cases());
    }

    #[Test]
    #[DataProvider('validStringValues')]
    public function fromCreatesEnumFromValidString(string $value, DsarStatus $expected): void
    {
        self::assertSame($expected, DsarStatus::from($value));
    }

    /**
     * @return iterable<string, array{string, DsarStatus}>
     */
    public static function validStringValues(): iterable
    {
        yield 'pending' => ['pending', DsarStatus::Pending];
        yield 'processing' => ['processing', DsarStatus::Processing];
        yield 'completed' => ['completed', DsarStatus::Completed];
        yield 'rejected' => ['rejected', DsarStatus::Rejected];
        yield 'downloaded' => ['downloaded', DsarStatus::Downloaded];
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(DsarStatus::tryFrom('cancelled'));
        self::assertNull(DsarStatus::tryFrom(''));
        self::assertNull(DsarStatus::tryFrom('PENDING'));
    }

    #[Test]
    public function fromThrowsForInvalidValue(): void
    {
        $this->expectException(ValueError::class);

        DsarStatus::from('archived');
    }
}
