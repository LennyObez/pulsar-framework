<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Extractor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Extractor\ExtractionResult;

#[CoversClass(ExtractionResult::class)]
final class ExtractionResultTest extends TestCase
{
    #[Test]
    public function totalKeysCountsAcrossDomains(): void
    {
        $result = new ExtractionResult([
            'messages' => [
                'welcome' => ['src/Controller.php:10'],
                'goodbye' => ['src/Controller.php:15'],
            ],
            'errors' => [
                'not_found' => ['src/Error.php:5'],
            ],
        ]);

        self::assertSame(3, $result->totalKeys());
    }

    #[Test]
    public function totalKeysReturnsZeroForEmpty(): void
    {
        $result = new ExtractionResult([]);

        self::assertSame(0, $result->totalKeys());
    }
}
