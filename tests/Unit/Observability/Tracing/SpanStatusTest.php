<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Tracing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Tracing\SpanStatus;

#[CoversClass(SpanStatus::class)]
final class SpanStatusTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('unset', SpanStatus::Unset->value);
        self::assertSame('ok', SpanStatus::Ok->value);
        self::assertSame('error', SpanStatus::Error->value);
    }
}
