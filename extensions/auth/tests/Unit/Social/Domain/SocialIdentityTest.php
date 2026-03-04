<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Domain\SocialIdentity;

#[CoversClass(SocialIdentity::class)]
final class SocialIdentityTest extends TestCase
{
    #[Test]
    public function constructorSetsAllFields(): void
    {
        $identity = new SocialIdentity(
            provider: 'google',
            providerUserId: '12345',
            email: 'user@example.com',
            name: 'Test User',
            avatarUrl: 'https://example.com/avatar.jpg',
            rawAttributes: ['locale' => 'en'],
        );

        self::assertSame('google', $identity->provider);
        self::assertSame('12345', $identity->providerUserId);
        self::assertSame('user@example.com', $identity->email);
        self::assertSame('Test User', $identity->name);
        self::assertSame('https://example.com/avatar.jpg', $identity->avatarUrl);
        self::assertSame(['locale' => 'en'], $identity->rawAttributes);
    }

    #[Test]
    public function optionalFieldsDefaultToNull(): void
    {
        $identity = new SocialIdentity(provider: 'github', providerUserId: '999');

        self::assertNull($identity->email);
        self::assertNull($identity->name);
        self::assertNull($identity->avatarUrl);
        self::assertSame([], $identity->rawAttributes);
    }
}
