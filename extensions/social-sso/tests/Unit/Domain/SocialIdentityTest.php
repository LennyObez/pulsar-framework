<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;

final class SocialIdentityTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredFields(): void
    {
        $id = new SocialIdentity(
            provider: 'github',
            providerUserId: '12345',
        );

        self::assertSame('github', $id->provider);
        self::assertSame('12345', $id->providerUserId);
        self::assertNull($id->email);
        self::assertNull($id->name);
        self::assertNull($id->avatarUrl);
        self::assertSame([], $id->rawAttributes);
    }

    #[Test]
    public function constructsWithAllFields(): void
    {
        $id = new SocialIdentity(
            provider: 'google',
            providerUserId: 'g-789',
            email: 'user@example.com',
            name: 'Test User',
            avatarUrl: 'https://example.com/avatar.jpg',
            rawAttributes: ['locale' => 'en'],
        );

        self::assertSame('user@example.com', $id->email);
        self::assertSame('Test User', $id->name);
        self::assertSame('https://example.com/avatar.jpg', $id->avatarUrl);
        self::assertSame(['locale' => 'en'], $id->rawAttributes);
    }
}
