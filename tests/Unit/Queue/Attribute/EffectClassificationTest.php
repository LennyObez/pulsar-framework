<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Attribute;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Attribute\EffectClassification;

#[CoversNothing]
final class EffectClassificationTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('idempotent', EffectClassification::Idempotent->value);
        self::assertSame('read_only', EffectClassification::ReadOnly->value);
        self::assertSame('non_idempotent', EffectClassification::NonIdempotent->value);
    }
}
