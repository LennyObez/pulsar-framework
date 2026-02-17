<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\DuplicateDetector;

#[CoversClass(DuplicateDetector::class)]
final class DuplicateDetectorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cacheStore = [];

    private DuplicateDetector $detector;

    protected function setUp(): void
    {
        $this->cacheStore = [];

        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willReturnCallback(
            function (string $key): mixed {
                /** @var array<string, mixed> $store */
                $store = $this->cacheStore;

                return $store[$key] ?? null;
            },
        );
        $cache->method('set')->willReturnCallback(
            function (string $key, mixed $value): bool {
                $this->cacheStore[$key] = $value;

                return true;
            },
        );

        $this->detector = new DuplicateDetector($cache);
    }

    #[Test]
    public function nameReturnsDuplicate(): void
    {
        self::assertSame('duplicate', $this->detector->name());
    }

    #[Test]
    public function passesForFreshSubmission(): void
    {
        $context = new AntiSpamContext(body: 'Brand new comment', ipHash: 'ip1');
        $result = $this->detector->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsForExactDuplicate(): void
    {
        $context = new AntiSpamContext(body: 'Duplicate text here', ipHash: 'ip1');

        // First submission passes and records
        $this->detector->check($context);

        // Second identical submission fails
        $result = $this->detector->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('Duplicate', $result->reason ?? '');
    }

    #[Test]
    public function passesForDifferentContent(): void
    {
        $context1 = new AntiSpamContext(body: 'First comment', ipHash: 'ip1');
        $context2 = new AntiSpamContext(body: 'Completely different text', ipHash: 'ip1');

        $this->detector->check($context1);
        $result = $this->detector->check($context2);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function passesForSameContentDifferentIp(): void
    {
        $context1 = new AntiSpamContext(body: 'Same text', ipHash: 'ip1');
        $context2 = new AntiSpamContext(body: 'Same text', ipHash: 'ip2');

        $this->detector->check($context1);
        $result = $this->detector->check($context2);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsForNearDuplicate(): void
    {
        $recentBody = 'This is a test comment that is fairly long and has some substance to it for comparison';
        $newBody = 'This is a test comment that is fairly long and has some substance to it for comparing';

        $context = new AntiSpamContext(
            body: $newBody,
            ipHash: 'ip1',
            recentBodies: [$recentBody],
        );

        $result = $this->detector->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('similar', $result->reason ?? '');
    }

    #[Test]
    public function passesForDissimilarRecentBodies(): void
    {
        $context = new AntiSpamContext(
            body: 'This is about PHP frameworks',
            ipHash: 'ip1',
            recentBodies: ['The weather today is sunny and warm'],
        );

        $result = $this->detector->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function passesForEmptyBody(): void
    {
        $context = new AntiSpamContext(body: '', ipHash: 'ip1');
        $result = $this->detector->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function passesForEmptyRecentBodies(): void
    {
        $context = new AntiSpamContext(
            body: 'Some new content',
            ipHash: 'ip1',
            recentBodies: [''],
        );

        $result = $this->detector->check($context);

        self::assertTrue($result->passed);
    }
}
