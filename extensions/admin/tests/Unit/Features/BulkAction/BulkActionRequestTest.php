<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\BulkAction;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionRequest;

#[CoversClass(BulkActionRequest::class)]
final class BulkActionRequestTest extends TestCase
{
    #[Test]
    public function constructor_sets_all_properties(): void
    {
        $context = new MutationContext(actor: 'admin', reason: 'bulk archive');

        $request = new BulkActionRequest(
            resourceName: 'orders',
            action: 'archive',
            ids: ['1', '2', '3'],
            parameters: ['reason' => 'completed'],
            context: $context,
        );

        self::assertSame('orders', $request->resourceName);
        self::assertSame('archive', $request->action);
        self::assertSame(['1', '2', '3'], $request->ids);
        self::assertSame(['reason' => 'completed'], $request->parameters);
        self::assertSame($context, $request->context);
    }

    #[Test]
    public function empty_ids_and_parameters(): void
    {
        $context = new MutationContext(actor: 'admin', reason: 'test');

        $request = new BulkActionRequest(
            resourceName: 'users',
            action: 'delete',
            ids: [],
            parameters: [],
            context: $context,
        );

        self::assertSame([], $request->ids);
        self::assertSame([], $request->parameters);
    }
}
