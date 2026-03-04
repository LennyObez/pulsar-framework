<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamContext;

#[CoversClass(AntiSpamContext::class)]
final class AntiSpamContextTest extends TestCase
{
    #[Test]
    public function isAnonymousReturnsTrueWhenNoUserId(): void
    {
        $context = new AntiSpamContext(body: 'test', ipHash: 'abc');

        self::assertTrue($context->isAnonymous());
    }

    #[Test]
    public function isAnonymousReturnsFalseWhenUserIdPresent(): void
    {
        $context = new AntiSpamContext(body: 'test', ipHash: 'abc', userId: 'user-1');

        self::assertFalse($context->isAnonymous());
    }

    #[Test]
    public function defaultValuesAreCorrect(): void
    {
        $context = new AntiSpamContext(body: 'hello', ipHash: 'ip123');

        self::assertSame('hello', $context->body);
        self::assertSame('ip123', $context->ipHash);
        self::assertNull($context->userId);
        self::assertNull($context->accountAgeSeconds);
        self::assertSame('new', $context->reputationTier);
        self::assertSame([], $context->recentBodies);
        self::assertSame([], $context->formFields);
        self::assertNull($context->powChallenge);
        self::assertNull($context->powNonce);
        self::assertNull($context->captchaToken);
        self::assertSame(0, $context->submissionTimestamp);
    }

    #[Test]
    public function allFieldsCanBeSet(): void
    {
        $context = new AntiSpamContext(
            body: 'body text',
            ipHash: 'hash',
            userId: 'u1',
            accountAgeSeconds: 3600,
            reputationTier: 'moderator',
            recentBodies: ['prev1', 'prev2'],
            formFields: ['name' => 'test'],
            powChallenge: 'challenge',
            powNonce: 'nonce',
            captchaToken: 'token',
            submissionTimestamp: 1700000000,
        );

        self::assertSame('body text', $context->body);
        self::assertSame('u1', $context->userId);
        self::assertSame(3600, $context->accountAgeSeconds);
        self::assertSame('moderator', $context->reputationTier);
        self::assertCount(2, $context->recentBodies);
        self::assertSame('challenge', $context->powChallenge);
        self::assertSame('nonce', $context->powNonce);
        self::assertSame('token', $context->captchaToken);
    }
}
