<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Support\UuidGenerator;

final class UuidGeneratorTest extends TestCase
{
    #[Test]
    public function v7ReturnsValidFormat(): void
    {
        $uuid = UuidGenerator::v7();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    #[Test]
    public function v7GeneratesUniqueValues(): void
    {
        $uuid1 = UuidGenerator::v7();
        $uuid2 = UuidGenerator::v7();

        self::assertNotSame($uuid1, $uuid2);
    }

    #[Test]
    public function v7HasCorrectVersionBit(): void
    {
        $uuid = UuidGenerator::v7();
        $parts = explode('-', $uuid);

        self::assertStringStartsWith('7', $parts[2]);
    }
}
