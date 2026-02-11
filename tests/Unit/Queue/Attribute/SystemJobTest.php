<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Attribute\SystemJob;

#[CoversClass(SystemJob::class)]
final class SystemJobTest extends TestCase
{
    #[Test]
    public function canBeInstantiated(): void
    {
        $attr = new SystemJob();

        self::assertInstanceOf(SystemJob::class, $attr);
    }
}
