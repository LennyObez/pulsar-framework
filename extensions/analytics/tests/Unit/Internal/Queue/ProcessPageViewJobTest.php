<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\PageView;
use Pulsar\Extension\Analytics\Internal\Queue\ProcessPageViewJob;
use Pulsar\Queue\JobContext;

final class ProcessPageViewJobTest extends TestCase
{
    #[Test]
    public function handle_inserts_page_view(): void
    {
        /** @var PageViewRepositoryInterface&MockObject $repo */
        $repo = $this->createMock(PageViewRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('insert')
            ->with(self::callback(static function (PageView $pv): bool {
                return $pv->id === 'pv_001'
                    && $pv->siteId === 'site_001'
                    && $pv->pathname === '/about';
            }));

        $job = new ProcessPageViewJob($repo, [
            'id' => 'pv_001',
            'site_id' => 'site_001',
            'visitor_id' => 'vis_001',
            'session_id' => 'sess_001',
            'pathname' => '/about',
            'referrer_source' => 'google',
            'country_code' => 'US',
            'device_type' => 'desktop',
            'browser' => 'Chrome',
            'os' => 'Windows',
            'screen_width' => 1920,
            'is_bounce' => false,
            'created_at' => '2025-01-15 12:00:00',
        ]);

        $context = $this->createStub(JobContext::class);
        $job->handle($context);
    }

    #[Test]
    public function handle_with_missing_data_uses_defaults(): void
    {
        /** @var PageViewRepositoryInterface&MockObject $repo */
        $repo = $this->createMock(PageViewRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('insert')
            ->with(self::callback(static function (PageView $pv): bool {
                return $pv->id === ''
                    && $pv->pathname === '/';
            }));

        $job = new ProcessPageViewJob($repo, []);

        $context = $this->createStub(JobContext::class);
        $job->handle($context);
    }

    #[Test]
    public function queue_returns_analytics(): void
    {
        $repo = $this->createStub(PageViewRepositoryInterface::class);
        $job = new ProcessPageViewJob($repo);

        self::assertSame('analytics', $job->queue());
    }

    #[Test]
    public function max_attempts_returns_3(): void
    {
        $repo = $this->createStub(PageViewRepositoryInterface::class);
        $job = new ProcessPageViewJob($repo);

        self::assertSame(3, $job->maxAttempts());
    }

    #[Test]
    public function timeout_returns_10(): void
    {
        $repo = $this->createStub(PageViewRepositoryInterface::class);
        $job = new ProcessPageViewJob($repo);

        self::assertSame(10, $job->timeout());
    }
}
