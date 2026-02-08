<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Idempotency\IdempotencyStoreInterface;
use Pulsar\Extension\Payments\Contracts\PaymentGatewayInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Webhook\WebhookEventLogInterface;
use Pulsar\Extension\Payments\Contracts\WebhookProcessorInterface;
use Pulsar\Webhook\WebhookVerifierInterface;
use Pulsar\Idempotency\InMemoryIdempotencyStore;
use Pulsar\Extension\Payments\Internal\Infrastructure\Webhook\HmacWebhookVerifier;
use Pulsar\Webhook\InMemoryWebhookEventLog;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentHandler;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookHandler;
use Pulsar\Extension\Payments\Features\ProcessWebhook\WebhookController;
use Pulsar\Extension\Payments\Gateway\PaymentGateway;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\SystemClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\NullProvider;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\SimulatorProvider;
use Pulsar\Extension\Payments\Webhook\WebhookProcessor;

/**
 * Service provider for the payments extension.
 *
 * Binds all payment services to the container based on configuration.
 */
final class PaymentsServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Clock
        $container->bind(ClockInterface::class, SystemClock::class);

        // Config
        $container->bind(PaymentsConfig::class, static function () use ($container): PaymentsConfig {
            /** @var array<string, mixed> $configData */
            $configData = [];

            if ($container->has('config.payments')) {
                /** @var array<string, mixed> $configData */
                $configData = $container->get('config.payments');
            }

            return PaymentsConfig::fromArray($configData);
        });

        // Provider
        $container->bind(PaymentProviderInterface::class, static function () use ($container): PaymentProviderInterface {
            /** @var PaymentsConfig $config */
            $config = $container->get(PaymentsConfig::class);

            /** @var ClockInterface $clock */
            $clock = $container->get(ClockInterface::class);

            return match ($config->provider) {
                'null' => new NullProvider($clock),
                'simulator' => new SimulatorProvider($clock),
                default => $container->get($config->provider),
            };
        });

        // Idempotency store
        $container->bind(IdempotencyStoreInterface::class, static function () use ($container): IdempotencyStoreInterface {
            /** @var PaymentsConfig $config */
            $config = $container->get(PaymentsConfig::class);

            return match ($config->idempotency->store) {
                'memory' => new InMemoryIdempotencyStore(),
                default => $container->get($config->idempotency->store),
            };
        });

        // Webhook event log
        $container->bind(WebhookEventLogInterface::class, static function () use ($container): WebhookEventLogInterface {
            /** @var PaymentsConfig $config */
            $config = $container->get(PaymentsConfig::class);

            return match ($config->webhookLog->store) {
                'memory' => new InMemoryWebhookEventLog(),
                default => $container->get($config->webhookLog->store),
            };
        });

        // Webhook verifier
        $container->bind(WebhookVerifierInterface::class, HmacWebhookVerifier::class);

        // Feature handlers
        $container->bind(CreatePaymentIntentHandler::class, CreatePaymentIntentHandler::class);
        $container->bind(ProcessWebhookHandler::class, ProcessWebhookHandler::class);

        // Gateway
        $container->bind(PaymentGateway::class, PaymentGateway::class);
        $container->bind(PaymentGatewayInterface::class, PaymentGateway::class);

        // Webhook processor
        $container->bind(WebhookProcessor::class, WebhookProcessor::class);
        $container->bind(WebhookProcessorInterface::class, WebhookProcessor::class);

        // Controller
        $container->bind(WebhookController::class, WebhookController::class);
    }

    public function provides(): array
    {
        return [
            ClockInterface::class,
            PaymentsConfig::class,
            PaymentProviderInterface::class,
            IdempotencyStoreInterface::class,
            WebhookEventLogInterface::class,
            WebhookVerifierInterface::class,
            CreatePaymentIntentHandler::class,
            ProcessWebhookHandler::class,
            PaymentGateway::class,
            PaymentGatewayInterface::class,
            WebhookProcessor::class,
            WebhookProcessorInterface::class,
            WebhookController::class,
        ];
    }
}
