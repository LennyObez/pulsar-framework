<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;

#[CoversClass(Api::class)]
final class ApiAttributeTest extends TestCase
{
    public function testDefaultStability(): void
    {
        $api = new Api(since: '1.0.0');

        self::assertSame('1.0.0', $api->since);
        self::assertSame('stable', $api->stability);
    }

    public function testExperimentalStability(): void
    {
        $api = new Api(since: '1.0.0', stability: 'experimental');

        self::assertSame('experimental', $api->stability);
    }

    public function testEmptyDefaults(): void
    {
        $api = new Api();

        self::assertSame('', $api->since);
        self::assertSame('stable', $api->stability);
    }
}
