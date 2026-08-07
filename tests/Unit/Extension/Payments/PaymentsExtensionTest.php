<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Container\Container;
use Pulsar\Extension\Payments\Config\BancontactConfig;
use Pulsar\Extension\Payments\Config\IdealConfig;
use Pulsar\Extension\Payments\Config\IdempotencyConfig;
use Pulsar\Extension\Payments\Config\KlarnaConfig;
use Pulsar\Extension\Payments\Config\MobileConfig;
use Pulsar\Extension\Payments\Config\PayconiqConfig;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Config\PayPalConfig;
use Pulsar\Extension\Payments\Config\SepaConfig;
use Pulsar\Extension\Payments\Config\StripeConfig;
use Pulsar\Extension\Payments\Config\WebhookConfig;
use Pulsar\Extension\Payments\Config\WebhookLogConfig;
use Pulsar\Extension\Payments\Http\Middleware\PaymentSecurityMiddleware;
use Pulsar\Extension\Payments\PaymentsExtension;
use Pulsar\Extension\Payments\PaymentsServiceProvider;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;

use function in_array;

#[CoversClass(PaymentsExtension::class)]
final class PaymentsExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarPayments(): void
    {
        $extension = new PaymentsExtension();

        self::assertSame('pulsar/payments', $extension->name());
    }

    #[Test]
    public function registerIsNoOp(): void
    {
        $extension = new PaymentsExtension();
        $container = new Container();

        $extension->register($container);

        // register() is intentionally empty — service provider handles bindings
        // Verify no bindings were added (container has no extra state)
        self::assertSame([], $container->getBindings());
    }

    #[Test]
    public function providersReturnsServiceProviderClass(): void
    {
        $extension = new PaymentsExtension();

        $providers = $extension->providers();

        self::assertSame([PaymentsServiceProvider::class], $providers);
    }

    #[Test]
    public function bootRegistersWebhookRoute(): void
    {
        $router = self::bootedRouter();

        $found = false;

        foreach ($router->routes as $route) {
            if ($route->name === 'payments.webhook') {
                $found = true;
                self::assertSame('/webhooks/payments', $route->path);

                break;
            }
        }

        self::assertTrue($found, 'Webhook route was not registered');
    }

    /**
     * Every route a client posts payment data to carries the PCI-DSS body
     * screen. Gateway webhook routes are exempt by design — Apple and Google
     * send numeric transaction identifiers of card-number length, a tenth of
     * which pass Luhn.
     */
    #[Test]
    public function bootPipesThePciScreenOntoClientFacingPostRoutes(): void
    {
        $screened = [
            'payments.checkout.create',
            'payments.checkout.capture',
            'payments.subscriptions.create',
            'payments.subscriptions.cancel',
            'payments.subscriptions.pause',
            'payments.subscriptions.resume',
            'payments.cart.add',
            'payments.cart.remove',
            'payments.cart.update',
            'payments.cart.coupon',
        ];

        $exempt = [
            'payments.webhook',
            'payments.webhooks.stripe',
            'payments.webhooks.paypal',
            'payments.webhooks.google_play',
            'payments.webhooks.apple',
            'payments.webhooks.payconiq',
            'payments.webhooks.bancontact',
        ];

        $seen = [];

        foreach (self::bootedRouter()->routes as $route) {
            $name = $route->name;

            if ($name === null) {
                continue;
            }

            if (in_array($name, $screened, true)) {
                $seen[] = $name;
                self::assertContains(
                    PaymentSecurityMiddleware::class,
                    $route->middleware,
                    "Route $name does not carry the PCI-DSS body screen",
                );
            }

            if (in_array($name, $exempt, true)) {
                self::assertNotContains(
                    PaymentSecurityMiddleware::class,
                    $route->middleware,
                    "Webhook route $name was screened; gateway receipts will be rejected",
                );
            }
        }

        self::assertEqualsCanonicalizing($screened, $seen, 'A screened route is missing from the router');
    }

    /**
     * Drives a request through the pipeline the kernel composes from the
     * registered route, rather than asserting the middleware is merely listed.
     */
    #[Test]
    public function checkoutPipelineRejectsARawCardNumberBeforeTheController(): void
    {
        $route = self::routeNamed(self::bootedRouter(), 'payments.checkout.create');

        $pipeline = new MiddlewarePipeline(new Container());

        foreach ($route->middleware as $name) {
            foreach (new MiddlewareRegistry()->resolve($name) as $middleware) {
                $pipeline->pipe($middleware);
            }
        }

        $controllerRan = false;

        $response = $pipeline->dispatch(
            new ServerRequest(
                method: 'POST',
                uri: $route->path,
                parsedBody: ['amount' => 1000, 'card_number' => '4111111111111111'],
            ),
            static function (ServerRequestInterface $request) use (&$controllerRan): ResponseInterface {
                $controllerRan = true;

                return Response::noContent();
            },
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('PCI-DSS compliance violation', (string) $response->getBody());
        self::assertFalse($controllerRan, 'The checkout controller was reached with a PAN in the body');
    }

    #[Test]
    public function checkoutPipelinePassesACleanBodyToTheController(): void
    {
        $route = self::routeNamed(self::bootedRouter(), 'payments.checkout.create');

        $pipeline = new MiddlewarePipeline(new Container());

        foreach ($route->middleware as $name) {
            foreach (new MiddlewareRegistry()->resolve($name) as $middleware) {
                $pipeline->pipe($middleware);
            }
        }

        $controllerRan = false;

        $response = $pipeline->dispatch(
            new ServerRequest(
                method: 'POST',
                uri: $route->path,
                parsedBody: ['amount' => 1000, 'currency' => 'EUR', 'payment_token' => 'tok_abc123'],
            ),
            static function (ServerRequestInterface $request) use (&$controllerRan): ResponseInterface {
                $controllerRan = true;

                return Response::noContent();
            },
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($controllerRan);
    }

    private static function routeNamed(Router $router, string $name): Route
    {
        foreach ($router->routes as $route) {
            if ($route->name === $name) {
                return $route;
            }
        }

        self::fail("Route $name was not registered");
    }

    private static function bootedRouter(): Router
    {
        $extension = new PaymentsExtension();
        $container = new Container();
        $router = new Router();

        $config = new PaymentsConfig(
            provider: 'null',
            defaultCurrency: 'USD',
            webhook: new WebhookConfig(
                secret: 'test-secret',
                path: '/webhooks/payments',
                toleranceSeconds: 300,
                signatureHeader: 'X-Payments-Signature',
            ),
            idempotency: new IdempotencyConfig(
                ttlSeconds: 86400,
                store: 'memory',
                maxKeyLength: 256,
            ),
            webhookLog: new WebhookLogConfig(
                ttlSeconds: 259200,
                store: 'memory',
            ),
            stripe: new StripeConfig(
                secretKey: '',
                publishableKey: '',
                webhookSecret: '',
                apiVersion: '2024-12-18.acacia',
                testMode: true,
            ),
            paypal: new PayPalConfig(
                clientId: '',
                clientSecret: '',
                webhookId: '',
                sandbox: true,
            ),
            sepa: SepaConfig::fromArray([]),
            mobile: MobileConfig::fromArray([]),
            payconiq: PayconiqConfig::fromArray([]),
            bancontact: BancontactConfig::fromArray([]),
            ideal: IdealConfig::fromArray([]),
            klarna: KlarnaConfig::fromArray([]),
            subscriptionsEnabled: false,
            invoiceRetentionDays: 3650,
            dunningMaxRetries: 4,
            trialMaxDays: 30,
        );

        $container->instance(PaymentsConfig::class, $config);

        $extension->boot($container, $router);

        return $router;
    }
}
