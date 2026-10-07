<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use ReflectionClass;

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

    /**
     * `since` is required. It used to default to `''`, and the one type that
     * took the default shipped a public-API snapshot entry claiming it became
     * stable in no release at all.
     */
    public function testSinceIsRequired(): void
    {
        $constructor = new ReflectionClass(Api::class)->getConstructor();

        self::assertNotNull($constructor);

        $since = $constructor->getParameters()[0];

        self::assertSame('since', $since->getName());
        self::assertFalse($since->isDefaultValueAvailable(), '#[Api] must not be writable without a version');
    }
}
