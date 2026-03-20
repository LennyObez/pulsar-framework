<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\Payments\PaymentsServiceProvider;

use function sprintf;

/**
 * F22.22: every entry returned by `provides()` MUST be bindable
 * after `register()` runs. Drift between the two lists has no
 * runtime symptom — the consumer who tries to resolve a service
 * that's listed-but-not-bound just sees an unhelpful "not found"
 * exception. This regression test fails CI before merge so the
 * audit can rely on `provides()` as a complete catalogue.
 */
#[CoversClass(PaymentsServiceProvider::class)]
final class PaymentsServiceProviderProvidesContractTest extends TestCase
{
    #[Test]
    public function everyEntryInProvidesIsBindableAfterRegister(): void
    {
        $container = new Container();
        // The provider reads `config.payments` from the container if
        // present; bind a closure returning an empty array so
        // PaymentsConfig::fromArray() builds defaults.
        $container->bind('config.payments', static fn(): array => []);

        $provider = new PaymentsServiceProvider();
        $provider->register($container);

        $unboundEntries = [];
        foreach ($provider->provides() as $entry) {
            if (!$container->has($entry)) {
                $unboundEntries[] = $entry;
            }
        }

        self::assertSame(
            [],
            $unboundEntries,
            sprintf(
                'PaymentsServiceProvider::provides() lists entries that were not bound by register(): %s',
                implode(', ', $unboundEntries),
            ),
        );
    }
}
