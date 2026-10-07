<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;

#[CoversClass(ResourceInfo::class)]
final class ResourceInfoTest extends TestCase
{
    #[Test]
    public function defaultAttributesAreEmpty(): void
    {
        $resource = new ResourceInfo();

        self::assertSame([], $resource->attributes);
    }

    #[Test]
    public function constructorSetsAttributes(): void
    {
        $attrs = ['service.name' => 'test', 'service.version' => '1.0'];
        $resource = new ResourceInfo(attributes: $attrs);

        self::assertSame($attrs, $resource->attributes);
    }
}
