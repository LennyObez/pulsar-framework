<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Bot;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Internal\Bot\BotPatterns;

final class BotPatternsTest extends TestCase
{
    #[Test]
    public function class_is_accessible(): void
    {
        // BotPatterns is a constant holder: verify the class can be loaded
        self::assertTrue(class_exists(BotPatterns::class));
    }
}
