<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\CreateResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceRequest;

#[CoversClass(CreateResourceRequest::class)]
final class CreateResourceRequestTest extends TestCase
{
    #[Test]
    public function constructor_sets_all_properties(): void
    {
        $context = new MutationContext(actor: 'admin', reason: 'create user');
        $data = ['name' => 'John', 'email' => 'john@example.com'];

        $request = new CreateResourceRequest(
            resourceName: 'users',
            data: $data,
            context: $context,
        );

        self::assertSame('users', $request->resourceName);
        self::assertSame($data, $request->data);
        self::assertSame($context, $request->context);
    }

    #[Test]
    public function empty_data(): void
    {
        $context = new MutationContext(actor: 'system', reason: 'default');

        $request = new CreateResourceRequest(
            resourceName: 'posts',
            data: [],
            context: $context,
        );

        self::assertSame([], $request->data);
    }
}
