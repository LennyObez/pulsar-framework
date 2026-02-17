<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Export\Otlp\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo;

#[CoversClass(ResourceInfo::class)]
final class ResourceInfoProtobufTest extends TestCase
{
    #[Test]
    public function constructorSetsAttributes(): void
    {
        $info = new ResourceInfo(['service.name' => 'my-app', 'service.version' => '1.0.0']);

        self::assertSame(['service.name' => 'my-app', 'service.version' => '1.0.0'], $info->attributes);
    }

    #[Test]
    public function emptyAttributesByDefault(): void
    {
        $info = new ResourceInfo();

        self::assertSame([], $info->attributes);
    }
}
