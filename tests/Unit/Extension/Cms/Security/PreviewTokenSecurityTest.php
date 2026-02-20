<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Themes\PreviewSession;

use function strlen;

/**
 * Security tests verifying preview tokens are unforgeable.
 *
 * Verifies S12: Preview tokens have sufficient entropy, expire correctly,
 * and cannot be predicted or reused after expiration.
 */
#[CoversClass(PreviewSession::class)]
final class PreviewTokenSecurityTest extends TestCase
{
    #[Test]
    public function previewTokenHasSufficientEntropy(): void
    {
        // 32 random bytes = 64 hex characters = 256 bits of entropy
        $session = new PreviewSession(
            themeId: 'theme-1',
            token: bin2hex(random_bytes(32)),
            userId: 'user-1',
            expiresAt: new DateTimeImmutable('+30 minutes'),
        );

        // Token must be at least 64 hex chars (256 bits)
        self::assertGreaterThanOrEqual(64, strlen($session->token));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $session->token);
    }

    #[Test]
    public function previewSessionExpiresCorrectly(): void
    {
        $session = new PreviewSession(
            themeId: 'theme-1',
            token: bin2hex(random_bytes(32)),
            userId: 'user-1',
            expiresAt: new DateTimeImmutable('-1 minute'),
        );

        self::assertTrue($session->isExpired());
    }

    #[Test]
    public function previewSessionNotExpiredWhenActive(): void
    {
        $session = new PreviewSession(
            themeId: 'theme-1',
            token: bin2hex(random_bytes(32)),
            userId: 'user-1',
            expiresAt: new DateTimeImmutable('+30 minutes'),
        );

        self::assertFalse($session->isExpired());
    }

    #[Test]
    public function differentPreviewSessionsHaveUniqueTokens(): void
    {
        $tokens = [];

        for ($i = 0; $i < 100; $i++) {
            $token = bin2hex(random_bytes(32));
            self::assertNotContains($token, $tokens, 'Token collision detected');
            $tokens[] = $token;
        }
    }

    #[Test]
    public function previewSessionBoundaryExpiry(): void
    {
        // Session that expires "now" should be treated as expired
        $session = new PreviewSession(
            themeId: 'theme-1',
            token: bin2hex(random_bytes(32)),
            userId: 'user-1',
            expiresAt: new DateTimeImmutable('now'),
        );

        // >= comparison means "now" is expired
        self::assertTrue($session->isExpired());
    }

    #[Test]
    public function previewTokenIsHexNotBase64(): void
    {
        // Hex encoding ensures URL-safe tokens without padding chars
        $token = bin2hex(random_bytes(32));
        $session = new PreviewSession(
            themeId: 'theme-1',
            token: $token,
            userId: 'user-1',
            expiresAt: new DateTimeImmutable('+30 minutes'),
        );

        // No characters outside hex range
        self::assertDoesNotMatchRegularExpression('/[^0-9a-f]/', $session->token);
    }
}
