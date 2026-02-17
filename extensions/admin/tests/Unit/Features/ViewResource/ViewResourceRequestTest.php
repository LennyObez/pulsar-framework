<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\ViewResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceRequest;

#[CoversClass(ViewResourceRequest::class)]
final class ViewResourceRequestTest extends TestCase
{
    #[Test]
    public function constructor_sets_properties(): void
    {
        $request = new ViewResourceRequest(
            resourceName: 'users',
            id: '42',
        );

        self::assertSame('users', $request->resourceName);
        self::assertSame('42', $request->id);
    }
}
