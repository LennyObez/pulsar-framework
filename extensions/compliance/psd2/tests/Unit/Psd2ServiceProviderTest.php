<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Config\Psd2Config;
use Pulsar\Extension\Psd2\Contracts\CertificateValidatorInterface;
use Pulsar\Extension\Psd2\Contracts\ScaChallengeStoreInterface;
use Pulsar\Extension\Psd2\Contracts\ScaDynamicLinkingServiceInterface;
use Pulsar\Extension\Psd2\Contracts\TransactionRiskAnalyzerInterface;
use Pulsar\Extension\Psd2\Contracts\VelocityTrackerInterface;
use Pulsar\Extension\Psd2\Middleware\CertificateAuthenticationMiddleware;
use Pulsar\Extension\Psd2\Middleware\ScaRequiredMiddleware;
use Pulsar\Extension\Psd2\Psd2ServiceProvider;

#[CoversClass(Psd2ServiceProvider::class)]
final class Psd2ServiceProviderTest extends TestCase
{
    #[Test]
    public function providesListsAllServices(): void
    {
        $provider = new Psd2ServiceProvider();
        $provides = $provider->provides();

        self::assertContains(Psd2Config::class, $provides);
        self::assertContains(ScaChallengeStoreInterface::class, $provides);
        self::assertContains(VelocityTrackerInterface::class, $provides);
        self::assertContains(ScaDynamicLinkingServiceInterface::class, $provides);
        self::assertContains(TransactionRiskAnalyzerInterface::class, $provides);
        self::assertContains(CertificateValidatorInterface::class, $provides);
        self::assertContains(ScaRequiredMiddleware::class, $provides);
        self::assertContains(CertificateAuthenticationMiddleware::class, $provides);
    }

    #[Test]
    public function registerBindsAllServices(): void
    {
        $bindings = [];
        $container = $this->createMock(\Pulsar\Container\ContainerInterface::class);
        $container->expects(self::exactly(8))
            ->method('bind')
            ->willReturnCallback(function (string $id) use (&$bindings): void {
                $bindings[] = $id;
            });

        $provider = new Psd2ServiceProvider();
        $provider->register($container);

        self::assertContains(Psd2Config::class, $bindings);
        self::assertContains(ScaChallengeStoreInterface::class, $bindings);
        self::assertContains(VelocityTrackerInterface::class, $bindings);
        self::assertContains(ScaDynamicLinkingServiceInterface::class, $bindings);
        self::assertContains(TransactionRiskAnalyzerInterface::class, $bindings);
        self::assertContains(CertificateValidatorInterface::class, $bindings);
        self::assertContains(ScaRequiredMiddleware::class, $bindings);
        self::assertContains(CertificateAuthenticationMiddleware::class, $bindings);
    }
}
