<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\Psd2\Config\Psd2Config;
use Pulsar\Extension\Psd2\Contracts\CertificateValidatorInterface;
use Pulsar\Extension\Psd2\Contracts\ScaChallengeStoreInterface;
use Pulsar\Extension\Psd2\Contracts\ScaDynamicLinkingServiceInterface;
use Pulsar\Extension\Psd2\Contracts\TransactionRiskAnalyzerInterface;
use Pulsar\Extension\Psd2\Contracts\VelocityTrackerInterface;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;
use Pulsar\Extension\Psd2\Middleware\CertificateAuthenticationMiddleware;
use Pulsar\Extension\Psd2\Middleware\ScaRequiredMiddleware;
use Pulsar\Extension\Psd2\Psd2ServiceProvider;
use Pulsar\Security\Crypto\MasterKey;

use function str_repeat;

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

    /**
     * A binding assertion only proves a factory was registered, never that it
     * runs: the KDF context for the SCA secret was 24 bytes for as long as
     * nothing resolved the service, and libsodium accepts exactly 8. Every
     * service this provider advertises is therefore resolved here, which is the
     * only assertion that would have failed on that defect.
     */
    #[Test]
    public function everyProvidedServiceResolves(): void
    {
        $container = $this->containerWithMasterKey();
        new Psd2ServiceProvider()->register($container);

        self::assertInstanceOf(Psd2Config::class, $container->get(Psd2Config::class));
        self::assertInstanceOf(
            ScaChallengeStoreInterface::class,
            $container->get(ScaChallengeStoreInterface::class),
        );
        self::assertInstanceOf(
            VelocityTrackerInterface::class,
            $container->get(VelocityTrackerInterface::class),
        );
        self::assertInstanceOf(
            ScaDynamicLinkingServiceInterface::class,
            $container->get(ScaDynamicLinkingServiceInterface::class),
        );
        self::assertInstanceOf(
            TransactionRiskAnalyzerInterface::class,
            $container->get(TransactionRiskAnalyzerInterface::class),
        );
        self::assertInstanceOf(
            CertificateValidatorInterface::class,
            $container->get(CertificateValidatorInterface::class),
        );
        self::assertInstanceOf(
            ScaRequiredMiddleware::class,
            $container->get(ScaRequiredMiddleware::class),
        );
        self::assertInstanceOf(
            CertificateAuthenticationMiddleware::class,
            $container->get(CertificateAuthenticationMiddleware::class),
        );
    }

    /**
     * Resolving the service is not enough on its own: the derived sub-key has to
     * key a working MAC, so the resolved service mints a challenge and verifies
     * the code it produced.
     */
    #[Test]
    public function resolvedDynamicLinkingServiceMintsAndVerifiesACode(): void
    {
        $container = $this->containerWithMasterKey();
        new Psd2ServiceProvider()->register($container);

        $service = $container->get(ScaDynamicLinkingServiceInterface::class);

        $challenge = $service->createChallenge(
            transactionId: 'txn-1',
            amountMinorUnits: 12_500,
            currency: 'EUR',
            payeeId: 'payee-1',
            payeeName: 'Acme GmbH',
        );

        self::assertNotSame('', $challenge->authenticationCode);

        $verified = $service->verifyChallenge(
            challengeId: $challenge->challengeId,
            responseCode: $challenge->authenticationCode,
            amountMinorUnits: 12_500,
            currency: 'EUR',
            payeeId: 'payee-1',
        );

        self::assertTrue($verified->verified);
    }

    /**
     * The dynamic-linking code is a keyed MAC, so a deployment without a master
     * key must fail closed rather than mint codes under a guessable secret.
     */
    #[Test]
    public function dynamicLinkingFailsClosedWithoutAMasterKey(): void
    {
        $container = new Container();
        new Psd2ServiceProvider()->register($container);

        $this->expectException(Psd2Exception::class);

        (void) $container->get(ScaDynamicLinkingServiceInterface::class);
    }

    private function containerWithMasterKey(): Container
    {
        $container = new Container();
        $container->instance(MasterKey::class, MasterKey::fromHex(str_repeat('a1', 32)));

        return $container;
    }
}
