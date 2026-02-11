<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\Template\EventIngestionTemplates;

#[CoversClass(EventIngestionTemplates::class)]
final class EventIngestionTemplatesTest extends TestCase
{
    private EventIngestionTemplates $templates;

    protected function setUp(): void
    {
        $this->templates = new EventIngestionTemplates();
    }

    #[Test]
    public function eventHandlerInterfaceExtendsWebhookHandlerInterface(): void
    {
        $output = $this->templates->eventHandlerInterface('Audit', 'App\\Compliance');

        self::assertStringContainsString('namespace App\\Compliance\\Contracts;', $output);
        self::assertStringContainsString('interface AuditEventHandlerInterface extends WebhookHandlerInterface', $output);
        self::assertStringContainsString('#[Api(since: \'1.0.0\')]', $output);
    }

    #[Test]
    public function eventHandlerHandlesKnownAndUnknownTypes(): void
    {
        $output = $this->templates->eventHandler('Audit', 'App\\Compliance');

        self::assertStringContainsString('final readonly class AuditEventHandler implements AuditEventHandlerInterface', $output);
        self::assertStringContainsString('AuditEventType::tryFrom($eventType)', $output);
        self::assertStringContainsString('$this->logger->warning(\'Unknown event type\'', $output);
        self::assertStringContainsString('AuditEvent::fromArray($payload)', $output);
    }

    #[Test]
    public function hmacVerifierUsesHmacSha256(): void
    {
        $output = $this->templates->hmacVerifier('Audit', 'App\\Compliance');

        self::assertStringContainsString('final readonly class AuditHmacVerifier implements WebhookVerifierInterface', $output);
        self::assertStringContainsString('hash_hmac(\'sha256\', $payload, $secret)', $output);
        self::assertStringContainsString('throw WebhookException::invalidSignature()', $output);
    }

    #[Test]
    public function eventLogImplementsDeduplication(): void
    {
        $output = $this->templates->eventLog('Audit', 'App\\Compliance');

        self::assertStringContainsString('final class InMemoryAuditEventLog implements WebhookEventLogInterface', $output);
        self::assertStringContainsString('public function claim(', $output);
        self::assertStringContainsString('public function commit(', $output);
        self::assertStringContainsString('public function release(', $output);
        self::assertStringContainsString('public function prune(', $output);
    }

    #[Test]
    public function controllerHandlesAllProcessingStatuses(): void
    {
        $output = $this->templates->controller('Audit', 'App\\Compliance');

        self::assertStringContainsString('final readonly class AuditWebhookController', $output);
        self::assertStringContainsString('WebhookProcessingStatus::Processed', $output);
        self::assertStringContainsString('WebhookProcessingStatus::Replay', $output);
        self::assertStringContainsString('WebhookProcessingStatus::InvalidSignature', $output);
        self::assertStringContainsString('WebhookProcessingStatus::HandlerError', $output);
    }

    #[Test]
    public function configContainsDtoWithFromArray(): void
    {
        $output = $this->templates->config('Audit', 'App\\Compliance');

        self::assertStringContainsString('final readonly class AuditIngestionConfig', $output);
        self::assertStringContainsString("public string \$secret = ''", $output);
        self::assertStringContainsString('public int $toleranceSeconds = 300', $output);
        self::assertStringContainsString('public int $deduplicationTtlSeconds = 259_200', $output);
    }

    #[Test]
    public function eventContainsFromArrayFactory(): void
    {
        $output = $this->templates->event('Audit', 'App\\Compliance');

        self::assertStringContainsString('final readonly class AuditEvent', $output);
        self::assertStringContainsString('public string $id', $output);
        self::assertStringContainsString('public AuditEventType $type', $output);
        self::assertStringContainsString('public static function fromArray(array $data): self', $output);
    }

    #[Test]
    public function eventTypeWithDefaultCases(): void
    {
        $output = $this->templates->eventType('Audit', 'App\\Compliance', []);

        self::assertStringContainsString('enum AuditEventType: string', $output);
        self::assertStringContainsString("case Created = 'created'", $output);
        self::assertStringContainsString("case Updated = 'updated'", $output);
        self::assertStringContainsString("case Deleted = 'deleted'", $output);
    }

    #[Test]
    public function eventTypeWithCustomCases(): void
    {
        $output = $this->templates->eventType('Audit', 'App\\Compliance', ['login_attempt', 'policy-change']);

        self::assertStringContainsString('enum AuditEventType: string', $output);
        self::assertStringContainsString("case LoginAttempt = 'login_attempt'", $output);
        self::assertStringContainsString("case PolicyChange = 'policy-change'", $output);
    }

    #[Test]
    public function exceptionContainsStaticFactories(): void
    {
        $output = $this->templates->exception('Audit', 'App\\Compliance');

        self::assertStringContainsString('final class AuditIngestionException extends RuntimeException', $output);
        self::assertStringContainsString('public static function invalidPayload(string $reason): self', $output);
        self::assertStringContainsString('public static function handlerFailed(string $eventId, string $reason): self', $output);
        self::assertStringContainsString('Invalid Audit event payload:', $output);
    }

    #[Test]
    public function serviceProviderBindsHandler(): void
    {
        $output = $this->templates->serviceProvider('Audit', 'App\\Compliance');

        self::assertStringContainsString('namespace App\\Compliance;', $output);
        self::assertStringContainsString('class AuditIngestionServiceProvider implements ServiceProviderInterface', $output);
        self::assertStringContainsString('$container->bind(AuditEventHandlerInterface::class, AuditEventHandler::class)', $output);
    }

    #[Test]
    public function readmeContainsModuleStructure(): void
    {
        $output = $this->templates->readme('Audit');

        self::assertStringContainsString('# Audit Event Ingestion', $output);
        self::assertStringContainsString('Contracts/', $output);
        self::assertStringContainsString('Internal/Infrastructure/', $output);
        self::assertStringContainsString('Domain/', $output);
        self::assertStringContainsString('Exception/', $output);
    }

    #[Test]
    public function eventHandlerTestContainsTestMethods(): void
    {
        $output = $this->templates->eventHandlerTest('Audit', 'Compliance', 'App\\Compliance');

        self::assertStringContainsString('namespace Pulsar\\Tests\\Unit\\Modules\\Compliance\\Internal\\Infrastructure;', $output);
        self::assertStringContainsString('#[CoversClass(AuditEventHandler::class)]', $output);
        self::assertStringContainsString('it_implements_the_handler_interface', $output);
        self::assertStringContainsString('it_handles_known_event_type', $output);
    }

    #[Test]
    public function controllerTestCoversProcessing(): void
    {
        $output = $this->templates->controllerTest('Audit', 'Compliance', 'App\\Compliance');

        self::assertStringContainsString('namespace Pulsar\\Tests\\Unit\\Modules\\Compliance\\Controller;', $output);
        self::assertStringContainsString('#[CoversClass(AuditWebhookController::class)]', $output);
        self::assertStringContainsString('it_returns_ok_on_successful_processing', $output);
    }
}
