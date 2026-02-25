<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Features\ExchangeCode;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeRequest;

final class ExchangeCodeRequestTest extends TestCase
{
    #[Test]
    public function constructsWithAllFields(): void
    {
        $req = new ExchangeCodeRequest('google', 'auth-code-123', 'state-abc');

        self::assertSame('google', $req->providerName);
        self::assertSame('auth-code-123', $req->code);
        self::assertSame('state-abc', $req->state);
    }
}
