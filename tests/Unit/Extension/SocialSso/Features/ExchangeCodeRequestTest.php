<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Features;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeRequest;
use ReflectionClass;

#[CoversClass(ExchangeCodeRequest::class)]
final class ExchangeCodeRequestTest extends TestCase
{
    #[Test]
    public function constructionWithAllFields(): void
    {
        $request = new ExchangeCodeRequest(
            providerName: 'google',
            code: 'auth-code-123',
            state: 'csrf-state-abc',
        );

        self::assertSame('google', $request->providerName);
        self::assertSame('auth-code-123', $request->code);
        self::assertSame('csrf-state-abc', $request->state);
    }

    #[Test]
    public function isReadonly(): void
    {
        $request = new ExchangeCodeRequest('github', 'code', 'state');

        $reflection = new ReflectionClass($request);
        self::assertTrue($reflection->isReadOnly());
    }
}
