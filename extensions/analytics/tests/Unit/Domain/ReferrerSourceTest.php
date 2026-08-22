<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\ReferrerSource;

final class ReferrerSourceTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $ref = new ReferrerSource(
            source: 'Google',
            medium: 'organic',
            campaign: 'spring',
            rawUrl: 'https://google.com?q=test',
        );

        self::assertSame('Google', $ref->source);
        self::assertSame('organic', $ref->medium);
        self::assertSame('spring', $ref->campaign);
        self::assertSame('https://google.com?q=test', $ref->rawUrl);
    }

    #[Test]
    public function directFactoryReturnsDirect(): void
    {
        $ref = ReferrerSource::direct();

        self::assertSame('Direct / None', $ref->source);
        self::assertSame('none', $ref->medium);
        self::assertTrue($ref->isDirect());
    }

    #[Test]
    public function fromOrganicSetsOrganicMedium(): void
    {
        $ref = ReferrerSource::fromOrganic('Google', 'https://google.com');

        self::assertSame('Google', $ref->source);
        self::assertSame('organic', $ref->medium);
        self::assertSame('', $ref->campaign);
        self::assertSame('https://google.com', $ref->rawUrl);
        self::assertFalse($ref->isDirect());
    }

    #[Test]
    public function fromSocialSetsSocialMedium(): void
    {
        $ref = ReferrerSource::fromSocial('Twitter', 'https://t.co/abc');

        self::assertSame('Twitter', $ref->source);
        self::assertSame('social', $ref->medium);
        self::assertSame('https://t.co/abc', $ref->rawUrl);
    }

    #[Test]
    public function fromReferralSetsReferralMedium(): void
    {
        $ref = ReferrerSource::fromReferral('blog.example.com', 'https://blog.example.com/post');

        self::assertSame('blog.example.com', $ref->source);
        self::assertSame('referral', $ref->medium);
        self::assertSame('https://blog.example.com/post', $ref->rawUrl);
    }

    #[Test]
    public function fromUtmSetsAllUtmFields(): void
    {
        $ref = ReferrerSource::fromUtm('newsletter', 'email', 'march-2026', 'https://example.com?utm_source=newsletter');

        self::assertSame('newsletter', $ref->source);
        self::assertSame('email', $ref->medium);
        self::assertSame('march-2026', $ref->campaign);
        self::assertSame('https://example.com?utm_source=newsletter', $ref->rawUrl);
    }

    #[Test]
    public function isDirectReturnsFalseForNonDirectSources(): void
    {
        self::assertFalse(ReferrerSource::fromOrganic('Google')->isDirect());
        self::assertFalse(ReferrerSource::fromSocial('Twitter')->isDirect());
        self::assertFalse(ReferrerSource::fromReferral('example.com')->isDirect());
        self::assertFalse(ReferrerSource::fromUtm('src', 'med', 'camp')->isDirect());
    }
}
