<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Feedback\FeedbackServiceProvider;

final class FeedbackServiceProviderTest extends TestCase
{
    #[Test]
    public function providesReturnsExpectedClassNames(): void
    {
        $provider = new FeedbackServiceProvider();
        $provides = $provider->provides();

        self::assertCount(4, $provides);
        self::assertContains('Pulsar\Extension\Feedback\FeedbackRepositoryInterface', $provides);
        self::assertContains('Pulsar\Extension\Feedback\Internal\FeedbackService', $provides);
    }
}
