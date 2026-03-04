<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\UpdateResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceRequest;

#[CoversClass(UpdateResourceRequest::class)]
final class UpdateResourceRequestTest extends TestCase
{
    #[Test]
    public function constructor_sets_all_properties(): void
    {
        $context = new MutationContext(actor: 'admin', reason: 'update');
        $data = ['name' => 'Updated Name'];

        $request = new UpdateResourceRequest(
            resourceName: 'users',
            id: '42',
            data: $data,
            context: $context,
        );

        self::assertSame('users', $request->resourceName);
        self::assertSame('42', $request->id);
        self::assertSame($data, $request->data);
        self::assertSame($context, $request->context);
    }
}
