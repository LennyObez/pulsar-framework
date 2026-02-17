<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Releases\ReleasesServiceProvider;

final class ReleasesServiceProviderTest extends TestCase
{
    #[Test]
    public function providesReturnsSixBindings(): void
    {
        $provider = new ReleasesServiceProvider();
        $provides = $provider->provides();

        self::assertCount(6, $provides);
        self::assertContains('Pulsar\Extension\Releases\ReleaseRepositoryInterface', $provides);
        self::assertContains('Pulsar\Extension\Releases\BetaSignupRepositoryInterface', $provides);
        self::assertContains('Pulsar\Extension\Releases\Internal\ReleaseService', $provides);
    }
}
