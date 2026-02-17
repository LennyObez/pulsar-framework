<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\DeleteResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceRequest;

#[CoversClass(DeleteResourceRequest::class)]
final class DeleteResourceRequestTest extends TestCase
{
    #[Test]
    public function constructor_sets_all_properties(): void
    {
        $context = new MutationContext(actor: 'admin', reason: 'delete user');

        $request = new DeleteResourceRequest(
            resourceName: 'users',
            id: '42',
            context: $context,
        );

        self::assertSame('users', $request->resourceName);
        self::assertSame('42', $request->id);
        self::assertSame($context, $request->context);
    }
}
