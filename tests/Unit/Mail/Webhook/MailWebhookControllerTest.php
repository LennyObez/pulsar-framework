<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Mail\Webhook\BounceHandler;
use Pulsar\Mail\Webhook\ComplaintHandler;
use Pulsar\Mail\Webhook\ConfigurableIpAllowlist;
use Pulsar\Mail\Webhook\MailWebhookController;
use Pulsar\Mail\Webhook\WebhookEventType;
use Pulsar\Mail\Webhook\WebhookHandlerInterface;
use Pulsar\Mail\Webhook\WebhookRequest;
use Pulsar\Mail\Webhook\WebhookResult;

#[CoversClass(MailWebhookController::class)]
final class MailWebhookControllerTest extends TestCase
{
    #[Test]
    public function acceptedBounceRoutesToTheBounceHandlerAndReturns200(): void
    {
        $bounceAudit = $this->createMock(AuditLoggerInterface::class);
        $bounceAudit->expects(self::once())->method('log');
        $complaintAudit = $this->createMock(AuditLoggerInterface::class);
        $complaintAudit->expects(self::never())->method('log');

        $controller = new MailWebhookController(
            $this->handlerReturning(WebhookResult::accepted('evt-1', WebhookEventType::Bounce, 'msg-1')),
            new BounceHandler($bounceAudit),
            new ComplaintHandler($complaintAudit),
            'mailgun',
        );

        $response = $controller->handle($this->request('{"event_type":"bounce"}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('bounce', (string) $response->getBody());
    }

    #[Test]
    public function acceptedComplaintRoutesToTheComplaintHandler(): void
    {
        $bounceAudit = $this->createMock(AuditLoggerInterface::class);
        $bounceAudit->expects(self::never())->method('log');
        $complaintAudit = $this->createMock(AuditLoggerInterface::class);
        $complaintAudit->expects(self::once())->method('log');

        $controller = new MailWebhookController(
            $this->handlerReturning(WebhookResult::accepted('evt-2', WebhookEventType::Complaint, 'msg-2')),
            new BounceHandler($bounceAudit),
            new ComplaintHandler($complaintAudit),
            'mailgun',
        );

        $response = $controller->handle($this->request('{"event_type":"complaint"}'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function rejectedWebhookReturns401AndRoutesToNoHandler(): void
    {
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::never())->method('log');

        $controller = new MailWebhookController(
            $this->handlerReturning(WebhookResult::rejected('', WebhookEventType::Bounce)),
            new BounceHandler($audit),
            new ComplaintHandler($audit),
            'mailgun',
        );

        $response = $controller->handle($this->request('{}'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function disallowedSourceIpReturns403WithoutInvokingTheHandler(): void
    {
        $handler = $this->createMock(WebhookHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $controller = new MailWebhookController(
            $handler,
            new BounceHandler(),
            new ComplaintHandler(),
            'mailgun',
            new ConfigurableIpAllowlist(['mailgun' => ['10.0.0.0/8']]),
        );

        $response = $controller->handle($this->request('{}', '203.0.113.7'));

        self::assertSame(403, $response->getStatusCode());
    }

    private function handlerReturning(WebhookResult $result): WebhookHandlerInterface
    {
        return new class ($result) implements WebhookHandlerInterface {
            public function __construct(private readonly WebhookResult $result) {}

            public function handle(WebhookRequest $request): WebhookResult
            {
                return $this->result;
            }
        };
    }

    private function request(string $body, string $remoteAddr = '10.0.0.1'): ServerRequest
    {
        return new ServerRequest(
            method: 'POST',
            uri: '/_pulsar/mail/webhook',
            headers: ['Content-Type' => 'application/json'],
            body: $body,
            serverParams: ['REMOTE_ADDR' => $remoteAddr],
        );
    }
}
