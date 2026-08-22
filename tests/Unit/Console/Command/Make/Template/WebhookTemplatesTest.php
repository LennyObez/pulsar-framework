<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\Template\WebhookTemplates;

#[CoversClass(WebhookTemplates::class)]
final class WebhookTemplatesTest extends TestCase
{
    private WebhookTemplates $templates;

    protected function setUp(): void
    {
        $this->templates = new WebhookTemplates();
    }

    #[Test]
    public function handlerInterfaceExtendsWebhookHandlerInterface(): void
    {
        $output = $this->templates->handlerInterface('Stripe', 'App\\Payment');

        self::assertStringContainsString('namespace App\\Payment\\Contracts;', $output);
        self::assertStringContainsString('interface StripeWebhookHandlerInterface extends WebhookHandlerInterface', $output);
        self::assertStringContainsString('#[Api(since: \'1.0.0\')]', $output);
    }

    #[Test]
    public function handlerLogsWebhookEvent(): void
    {
        $output = $this->templates->handler('Stripe', 'App\\Payment');

        self::assertStringContainsString('namespace App\\Payment\\Internal\\Infrastructure;', $output);
        self::assertStringContainsString('final readonly class StripeWebhookHandler implements StripeWebhookHandlerInterface', $output);
        self::assertStringContainsString('private LoggerInterface $logger', $output);
        self::assertStringContainsString('$this->logger->info(\'Webhook received\'', $output);
    }

    #[Test]
    public function hmacVerifierUsesHmacSha256(): void
    {
        $output = $this->templates->hmacVerifier('Stripe', 'App\\Payment');

        self::assertStringContainsString('final readonly class StripeHmacVerifier implements WebhookVerifierInterface', $output);
        self::assertStringContainsString('hash_hmac(\'sha256\', $payload, $secret)', $output);
        self::assertStringContainsString('throw WebhookException::invalidSignature()', $output);
    }

    #[Test]
    public function eventLogImplementsDeduplication(): void
    {
        $output = $this->templates->eventLog('Stripe', 'App\\Payment');

        self::assertStringContainsString('final class InMemoryStripeEventLog implements WebhookEventLogInterface', $output);
        self::assertStringContainsString('public function claim(', $output);
        self::assertStringContainsString('public function commit(', $output);
        self::assertStringContainsString('public function release(', $output);
        self::assertStringContainsString('public function prune(', $output);
        self::assertStringContainsString('WebhookClaim::replay(', $output);
        self::assertStringContainsString('WebhookClaim::claimed()', $output);
    }

    #[Test]
    public function controllerHandlesAllProcessingStatuses(): void
    {
        $output = $this->templates->controller('Stripe', 'App\\Payment');

        self::assertStringContainsString('final readonly class StripeWebhookController', $output);
        self::assertStringContainsString("private const string SIGNATURE_HEADER = 'X-Webhook-Signature'", $output);
        self::assertStringContainsString('WebhookProcessingStatus::Processed', $output);
        self::assertStringContainsString('WebhookProcessingStatus::Replay', $output);
        self::assertStringContainsString('WebhookProcessingStatus::InvalidSignature', $output);
        self::assertStringContainsString('WebhookProcessingStatus::HandlerError', $output);
    }

    #[Test]
    public function configContainsDtoWithFromArray(): void
    {
        $output = $this->templates->config('Stripe', 'App\\Payment');

        self::assertStringContainsString('final readonly class StripeWebhookConfig', $output);
        self::assertStringContainsString("public string \$secret = ''", $output);
        self::assertStringContainsString('public int $toleranceSeconds = 300', $output);
        self::assertStringContainsString('public int $deduplicationTtlSeconds = 259_200', $output);
        self::assertStringContainsString('public static function fromArray(array $data): self', $output);
    }

    #[Test]
    public function eventContainsFromArrayFactory(): void
    {
        $output = $this->templates->event('Stripe', 'App\\Payment');

        self::assertStringContainsString('final readonly class StripeWebhookEvent', $output);
        self::assertStringContainsString('public string $id', $output);
        self::assertStringContainsString('public StripeWebhookEventType $type', $output);
        self::assertStringContainsString('public static function fromArray(array $data): self', $output);
    }

    #[Test]
    public function eventTypeContainsDefaultCases(): void
    {
        $output = $this->templates->eventType('Stripe', 'App\\Payment');

        self::assertStringContainsString('enum StripeWebhookEventType: string', $output);
        self::assertStringContainsString("case Created = 'created'", $output);
        self::assertStringContainsString("case Updated = 'updated'", $output);
        self::assertStringContainsString("case Deleted = 'deleted'", $output);
    }

    #[Test]
    public function handlerTestContainsTestMethods(): void
    {
        $output = $this->templates->handlerTest('Stripe', 'Payment', 'App\\Payment');

        self::assertStringContainsString('namespace Pulsar\\Tests\\Unit\\Modules\\Payment\\Internal\\Infrastructure;', $output);
        self::assertStringContainsString('#[CoversClass(StripeWebhookHandler::class)]', $output);
        self::assertStringContainsString('it_implements_the_handler_interface', $output);
        self::assertStringContainsString('it_handles_webhook_event', $output);
    }

    #[Test]
    public function controllerTestCoversProcessingOutcomes(): void
    {
        $output = $this->templates->controllerTest('Stripe', 'Payment', 'App\\Payment');

        self::assertStringContainsString('namespace Pulsar\\Tests\\Unit\\Modules\\Payment\\Controller;', $output);
        self::assertStringContainsString('#[CoversClass(StripeWebhookController::class)]', $output);
        self::assertStringContainsString('it_returns_ok_on_successful_processing', $output);
        self::assertStringContainsString('it_returns_forbidden_on_invalid_signature', $output);
    }
}
